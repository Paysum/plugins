<?php

namespace IPS\paysum\extensions\nexus\Gateway;

use PaySum\Checkout\Checkout;

defined('\IPS\SUITE_UNIQUE_KEY') || exit;

require_once dirname(__DIR__, 3) . '/Checkout.php';

class _paysum extends \IPS\nexus\Gateway
{
    public function supports($feature)
    {
        return in_array($feature, ['auth', 'capture'], true);
    }

    public function canStoreCards($adminCreatableOnly = false)
    {
        return false;
    }

    public function canAdminCharge(\IPS\nexus\Customer $customer)
    {
        return false;
    }

    public function settings(&$form)
    {
        $settings = json_decode($this->settings, true) ?: [];
        $form->add(new \IPS\Helpers\Form\Password('paysum_api_key', $settings['api_key'] ?? '', true));
        $form->add(new \IPS\Helpers\Form\Password('paysum_webhook_secret', $settings['webhook_secret'] ?? '', true));
    }

    public function testSettings($settings)
    {
        $result = [
            'api_key' => trim((string) ($settings['paysum_api_key'] ?? $settings['api_key'] ?? '')),
            'webhook_secret' => trim((string) ($settings['paysum_webhook_secret'] ?? $settings['webhook_secret'] ?? '')),
        ];
        if (strpos($result['api_key'], 'sk_') !== 0 || strpos($result['webhook_secret'], 'whsec_') !== 0) {
            throw new \InvalidArgumentException('Enter a PaySum store API key and webhook signing secret.');
        }
        return $result;
    }

    public function paymentScreen(\IPS\nexus\Invoice $invoice, \IPS\nexus\Money $amount, ?\IPS\nexus\Customer $member = null, $recurrings = [], $type = 'checkout')
    {
        return '<p>Continue to PaySum to pay this invoice.</p>';
    }

    public static function request(string $key, string $id = '', array $data = []): array
    {
        $ch = curl_init(Checkout::ENDPOINT . ($id !== '' ? '/' . $id : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        ]);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $session = is_string($body) ? json_decode($body, true) : null;
        if ($status !== ($data ? 201 : 200) || !is_array($session)) {
            throw new \RuntimeException('PaySum did not accept the request.');
        }
        return $session;
    }

    public function auth(\IPS\nexus\Transaction $transaction, $values, ?\IPS\nexus\Fraud\MaxMind\Request $maxMind = null, $recurrings = [], $source = null)
    {
        $settings = json_decode($this->settings, true) ?: [];
        $invoice = $transaction->invoice;
        if ($recurrings || (float) $transaction->amount->amountAsString() <= 0) {
            throw new \LogicException('PaySum supports one-time payments only.');
        }
        if (!$transaction->id) {
            $transaction->save();
        }
        $reference = 'ips:' . $transaction->id;
        $callback = (string) \IPS\Http\Url::internal('app=paysum&module=gateway&controller=webhook', 'front');
        $total = $transaction->amount->amountAsString();
        try {
            if (!empty($transaction->gw_id)) {
                if (!Checkout::sessionId($transaction->gw_id)) {
                    throw new \RuntimeException('Invalid PaySum session.');
                }
                $current = self::request($settings['api_key'] ?? '', $transaction->gw_id);
                if (($current['id'] ?? '') !== $transaction->gw_id || !Checkout::matches($current, $reference, $transaction->amount->currency, $total)) {
                    throw new \RuntimeException('Invalid PaySum session.');
                }
                if (($current['status'] ?? '') === 'pending' && Checkout::url($current['url'] ?? null, $transaction->gw_id)) {
                    \IPS\Output::i()->redirect(\IPS\Http\Url::external($current['url']));
                    return;
                }
                if (($current['status'] ?? '') === 'completed') {
                    \IPS\Output::i()->redirect($invoice->url());
                    return;
                }
                throw new \LogicException('This checkout is closed. Return to the invoice to start a new payment.');
            }
            $session = self::request($settings['api_key'] ?? '', '', [
                'line_items' => [['name' => 'Invoice #' . $invoice->id, 'price' => (float) $total, 'quantity' => 1]],
                'client_reference_id' => $reference,
                'success_url' => (string) $invoice->url(),
                'cancel_url' => (string) $invoice->checkoutUrl(),
                'webhook_url' => $callback,
                'disable_coupons' => true,
                'disable_gift_cards' => true,
            ]);
            if (!Checkout::url($session['url'] ?? null, $session['id'] ?? null)
                || !Checkout::matches($session + ['client_reference_id' => $reference], $reference, $transaction->amount->currency, $total)) {
                throw new \RuntimeException('Invalid PaySum session.');
            }
            $transaction->gw_id = $session['id'];
            $transaction->save();
        } catch (\RuntimeException $e) {
            throw new \LogicException('PaySum could not open checkout. Check the store currency and gateway settings.');
        }
        \IPS\Output::i()->redirect(\IPS\Http\Url::external($session['url']));
    }

    public function capture(\IPS\nexus\Transaction $transaction)
    {
    }

    public function void(\IPS\nexus\Transaction $transaction)
    {
        throw new \LogicException('Cancel or refund the payment in PaySum.');
    }

    public function extraData(\IPS\nexus\Transaction $transaction)
    {
        return [];
    }
}
