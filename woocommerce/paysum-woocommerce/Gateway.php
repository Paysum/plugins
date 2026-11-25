<?php

namespace PaySum\WooCommerce;

use PaySum\Checkout\Checkout;

defined('ABSPATH') || exit;

final class Gateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'paysum';
        $this->method_title = 'PaySum';
        $this->method_description = 'Send customers to PaySum to pay for their order.';
        $this->supports = ['products'];
        $this->form_fields = [
            'enabled' => ['title' => 'Enable PaySum', 'type' => 'checkbox', 'default' => 'no'],
            'title' => ['title' => 'Title', 'type' => 'text', 'default' => 'Pay with PaySum'],
            'api_key' => ['title' => 'Store API key', 'type' => 'password'],
            'webhook_secret' => ['title' => 'Webhook signing secret', 'type' => 'password'],
        ];
        $this->init_settings();
        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title', 'Pay with PaySum');
        add_action('woocommerce_update_options_payment_gateways_paysum', [$this, 'process_admin_options']);
    }

    public function is_available()
    {
        return parent::is_available() && $this->get_option('api_key') && $this->get_option('webhook_secret');
    }

    private function request(string $method, string $id = '', array $body = []): array
    {
        $args = [
            'method' => $method, 'timeout' => 15, 'redirection' => 0,
            'headers' => ['Authorization' => 'Bearer ' . $this->get_option('api_key'), 'Content-Type' => 'application/json'],
        ];
        if ($body) {
            $args['body'] = wp_json_encode($body);
        }
        $result = wp_remote_request(Checkout::ENDPOINT . ($id === '' ? '' : '/' . $id), $args);
        if (is_wp_error($result) || wp_remote_retrieve_response_code($result) !== ($method === 'POST' ? 201 : 200)) {
            throw new \RuntimeException('PaySum did not accept the request.');
        }
        $data = json_decode(wp_remote_retrieve_body($result), true);
        if (!is_array($data)) {
            throw new \RuntimeException('PaySum returned an invalid response.');
        }
        return $data;
    }

    private function locked(int $id, callable $action)
    {
        global $wpdb;
        $lock = 'paysum:' . substr(hash('sha256', $wpdb->prefix . ':' . $id), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== 1) {
            throw new \RuntimeException('Order is in use.');
        }
        try {
            return $action();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public function process_payment($order_id)
    {
        try {
            return $this->locked((int) $order_id, function () use ($order_id) {
                $order = wc_get_order($order_id);
                if (!$order || !$order->needs_payment() || $order->get_payment_method() !== $this->id) {
                    throw new \RuntimeException('Order is not payable.');
                }
                $ref = 'woo:' . $order->get_id();
                $id = $order->get_meta('_paysum_session');
                if (Checkout::sessionId($id)) {
                    $session = $this->request('GET', $id);
                    $status = $session['status'] ?? '';
                    $matches = ($session['id'] ?? '') === $id
                        && Checkout::matches($session, $ref, $order->get_currency(), $order->get_total());
                    if ($status === 'completed' && $matches && $order->payment_complete($id)) {
                        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
                    }
                    if ($status === 'pending' && $matches
                        && Checkout::url($session['url'] ?? null, $id)) {
                        return ['result' => 'success', 'redirect' => $session['url']];
                    }
                    if (!$matches || !in_array($status, ['expired', 'cancelled'], true)) {
                        throw new \RuntimeException('The existing checkout must be reconciled before retrying.');
                    }
                }
                if ((float) $order->get_total() <= 0) {
                    throw new \RuntimeException('Order total must be positive.');
                }
                $session = $this->request('POST', '', [
                    'line_items' => [['name' => 'Order #' . $order->get_order_number(), 'price' => (float) $order->get_total(), 'quantity' => 1]],
                    'client_reference_id' => $ref,
                    'success_url' => $this->get_return_url($order),
                    'cancel_url' => $order->get_checkout_payment_url(),
                    'webhook_url' => WC()->api_request_url('paysum'),
                    'disable_coupons' => true,
                    'disable_gift_cards' => true,
                ]);
                $previous = $id;
                $id = $session['id'] ?? '';
                if (!Checkout::url($session['url'] ?? null, $id)
                    || !Checkout::matches($session + ['client_reference_id' => $ref], $ref, $order->get_currency(), $order->get_total())) {
                    throw new \RuntimeException('The PaySum store currency must match the order currency.');
                }
                if (Checkout::sessionId($previous)) {
                    $order->add_meta_data('_paysum_previous_session', $previous);
                }
                $order->update_meta_data('_paysum_session', $id);
                $order->save();
                return ['result' => 'success', 'redirect' => $session['url']];
            });
        } catch (\RuntimeException $e) {
            wc_add_notice('PaySum could not open checkout. Check the payment status before trying again.', 'error');
            return ['result' => 'failure'];
        }
    }

    public function webhook(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json([], 405);
        }
        $secret = (string) $this->get_option('webhook_secret');
        $event = Checkout::event(file_get_contents('php://input', false, null, 0, 262145) ?: '', (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''), $secret);
        if (!$event) {
            wp_send_json([], 401);
        }
        $id = $event['data']['id'] ?? '';
        $ref = $event['data']['client_reference_id'] ?? '';
        if (($event['event'] ?? '') !== 'checkout.session.completed' || !Checkout::sessionId($id)
            || !is_string($ref) || !preg_match('/^woo:([1-9][0-9]{0,17})$/D', $ref, $m)) {
            wp_send_json([], 200);
        }
        try {
            $status = $this->locked((int) $m[1], function () use ($m, $id, $ref) {
                $order = wc_get_order((int) $m[1]);
                if (!$order || $order->get_payment_method() !== $this->id) {
                    return 404;
                }
                $previous = array_map(static function ($meta) {
                    return $meta->get_data()['value'];
                }, (array) $order->get_meta('_paysum_previous_session', false));
                if ($order->get_meta('_paysum_session') !== $id && !in_array($id, $previous, true)) {
                    return 404;
                }
                if ($order->is_paid()) {
                    if ($order->get_transaction_id() !== $id) {
                        return 409;
                    }
                    return 200;
                }
                if (!$order->needs_payment()) {
                    return 409;
                }
                $session = $this->request('GET', $id);
                if (($session['id'] ?? '') !== $id || ($session['status'] ?? '') !== 'completed'
                    || !Checkout::matches($session, $ref, $order->get_currency(), $order->get_total())) {
                    return 409;
                }
                return $order->payment_complete($id) ? 200 : 503;
            });
        } catch (\RuntimeException $e) {
            $status = 503;
        }
        wp_send_json([], $status);
    }
}
