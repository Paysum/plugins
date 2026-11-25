<?php
/*
Plugin Name: PaySum for WooCommerce
Description: Accept payments through PaySum hosted checkout.
Version: 0.1.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Author: PaySum
License: GPL-2.0-or-later
*/

defined('ABSPATH') || exit;

add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) {
    $registry->register(new class extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        protected $name = 'paysum';

        public function initialize()
        {
            $this->settings = get_option('woocommerce_paysum_settings', []);
        }

        public function is_active()
        {
            return $this->get_setting('enabled') === 'yes'
                && $this->get_setting('api_key') && $this->get_setting('webhook_secret');
        }

        public function get_payment_method_script_handles()
        {
            wp_register_script('paysum-blocks', plugins_url('blocks.js', __FILE__), ['wc-blocks-registry', 'wc-settings', 'wp-element'], '0.1.0', true);
            return ['paysum-blocks'];
        }

        public function get_payment_method_data()
        {
            return ['title' => $this->get_setting('title', 'Pay with PaySum')];
        }
    });
});

add_action('plugins_loaded', static function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once __DIR__ . '/Checkout.php';
    require_once __DIR__ . '/Gateway.php';
    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = \PaySum\WooCommerce\Gateway::class;
        return $gateways;
    });
    add_action('woocommerce_api_paysum', static function () {
        $gateway = WC()->payment_gateways()->payment_gateways()['paysum'] ?? null;
        if (!$gateway instanceof \PaySum\WooCommerce\Gateway) {
            wp_send_json([], 503);
        }
        $gateway->webhook();
    });
});
