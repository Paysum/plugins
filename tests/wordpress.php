<?php

define('ABSPATH', __DIR__);

function add_shortcode($name, $handler)
{
    $GLOBALS['shortcodes'][$name] = $handler;
}

function shortcode_atts($defaults, $attributes, $name)
{
    return array_merge($defaults, array_intersect_key($attributes, $defaults));
}

function wp_parse_url($url)
{
    return parse_url($url);
}

function esc_url($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function esc_html($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

require __DIR__ . '/../wordpress/paysum-checkout/paysum-checkout.php';

$render = $GLOBALS['shortcodes']['paysum_checkout'];
$button = $render(['url' => 'https://demo.mypaysum.io/checkout?a=b&c=d', 'label' => '<Buy>']);
if (strpos($button, 'a=b&amp;c=d') === false || strpos($button, '&lt;Buy&gt;') === false
    || $render(['url' => 'https://demo.mypaysum.io.example.net/checkout']) !== ''
    || $render(['url' => 'https://demo.mypaysum.io@evil.tld/checkout']) !== '') {
    throw new RuntimeException('WordPress shortcode check failed.');
}
echo "WordPress shortcode checks passed.\n";
