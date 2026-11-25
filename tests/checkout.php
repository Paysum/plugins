<?php

require __DIR__ . '/../shared/Checkout.php';

use PaySum\Checkout\Checkout;

function check(bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException('Checkout check failed.');
    }
}

$id = 'cs_' . str_repeat('a', 32);
$session = ['id' => $id, 'client_reference_id' => 'woo:12', 'currency' => 'USD', 'total_amount' => '24.90'];
$body = json_encode(['event' => 'checkout.session.completed', 'data' => $session]);
$secret = 'fixture';
$now = time();
$sign = static fn ($t) => "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret);
check(Checkout::event($body, $sign($now), $secret)['data'] === $session);
check(Checkout::event($body . ' ', $sign($now), $secret) === null);
check(Checkout::event($body, $sign($now - 301), $secret) === null);
check(Checkout::event($body, $sign($now) . ',t=' . $now, $secret) === null);
check(Checkout::event($body, "v1=invalid," . $sign($now), $secret) !== null);
check(Checkout::matches($session, 'woo:12', 'USD', 24.90));
check(!Checkout::matches($session, 'woo:13', 'USD', 24.90));
check(!Checkout::matches($session, 'woo:12', 'EUR', 24.90));
check(!Checkout::matches($session, 'woo:12', 'USD', 24.89));
check(Checkout::url("https://demo.mypaysum.io/checkout?session=$id", $id));
check(!Checkout::url("https://demo.mypaysum.io.example.org/checkout?session=$id", $id));
check(!Checkout::url("https://demo.mypaysum.io/checkout?session={$id}0", $id));
echo "Checkout checks passed.\n";
