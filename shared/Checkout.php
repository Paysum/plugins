<?php

namespace PaySum\Checkout;

final class Checkout
{
    public const ENDPOINT = 'https://api.paysum.io/api/checkout-api/sessions';

    public static function sessionId($id): bool
    {
        return is_string($id) && preg_match('/^cs_[a-f0-9]{32}$/D', $id) === 1;
    }

    public static function url($url, $id): bool
    {
        return self::sessionId($id) && is_string($url) && preg_match(
            '~^https://[a-z0-9-]+\.mypaysum\.io/checkout\?session=' . $id . '$~D',
            $url
        ) === 1;
    }

    public static function matches(array $session, string $reference, string $currency, $total): bool
    {
        return ($session['client_reference_id'] ?? null) === $reference
            && ($session['currency'] ?? null) === $currency
            && is_numeric($session['total_amount'] ?? null) && is_numeric($total)
            && is_finite((float) $total) && (float) $total > 0
            && abs((float) $session['total_amount'] - (float) $total) < 0.000001;
    }

    public static function event(string $body, string $signature, string $secret): ?array
    {
        if ($secret === '' || strlen($body) > 262144 || strlen($signature) > 2048) {
            return null;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signature) as $field) {
            [$name, $value] = array_pad(explode('=', trim($field), 2), 2, '');
            if ($name === 't') {
                if ($timestamp !== null || !ctype_digit($value) || strlen($value) > 12) {
                    return null;
                }
                $timestamp = $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || abs(time() - (int) $timestamp) > 300) {
            return null;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $event = json_decode($body, true);
                return is_array($event) ? $event : null;
            }
        }
        return null;
    }
}
