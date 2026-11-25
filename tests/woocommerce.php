<?php

define('ABSPATH', __DIR__);

#[AllowDynamicProperties]
class WC_Payment_Gateway
{
    public function init_settings() {}
    public function get_option($key, $default = '') { return $key === 'api_key' ? 'fixture' : $default; }
    public function get_return_url($order) { return 'https://example.org/return'; }
}

function add_action(...$args) {}
function wc_add_notice(...$args) {}
function wc_get_order($id) { return $GLOBALS['order']; }
function WC() { return new class { public function api_request_url($endpoint) { return 'https://example.org/wc-api/' . $endpoint; } }; }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($value) { return $value[0]; }
function wp_remote_retrieve_body($value) { return json_encode($value[1]); }
function wp_remote_request($url, $args)
{
    $GLOBALS['requests'][] = [$url, $args];
    return array_shift($GLOBALS['responses']);
}

require __DIR__ . '/../shared/Checkout.php';
require __DIR__ . '/../woocommerce/paysum-woocommerce/Gateway.php';

$wpdb = new class {
    public $prefix = 'test';
    public function prepare(...$args) { return $args[0]; }
    public function get_var($sql) { return 1; }
};
$order = new class {
    public $session = '';
    public $paid = false;
    public function needs_payment() { return !$this->paid; }
    public function get_payment_method() { return 'paysum'; }
    public function get_id() { return 12; }
    public function get_meta($name) { return $this->session; }
    public function get_currency() { return 'USD'; }
    public function get_total() { return '29.90'; }
    public function get_order_number() { return '12'; }
    public function get_checkout_payment_url() { return 'https://example.org/pay'; }
    public function update_meta_data($key, $value) { $this->session = $value; }
    public function save() {}
    public function payment_complete($id) { return $this->paid = $id === $this->session; }
};
$id = 'cs_' . str_repeat('b', 32);
$fixture = ['id' => $id, 'url' => "https://demo.mypaysum.io/checkout?session=$id", 'currency' => 'USD', 'total_amount' => '29.90'];
$responses = [
    [201, $fixture],
    [200, $fixture + ['status' => 'pending', 'client_reference_id' => 'woo:12']],
    [200, $fixture + ['status' => 'completed', 'client_reference_id' => 'woo:12']],
];
$gateway = new \PaySum\WooCommerce\Gateway();
$first = $gateway->process_payment(12);
$second = $gateway->process_payment(12);
$third = $gateway->process_payment(12);
$request = json_decode($requests[0][1]['body'], true);
if ($first !== ['result' => 'success', 'redirect' => $fixture['url']] || $second !== $first
    || !$order->paid || $third['redirect'] !== 'https://example.org/return'
    || $request['line_items'][0]['price'] !== 29.9 || !$request['disable_coupons']
    || array_column(array_column($requests, 1), 'method') !== ['POST', 'GET', 'GET']) {
    throw new RuntimeException('WooCommerce checkout check failed.');
}
echo "WooCommerce checkout checks passed.\n";
