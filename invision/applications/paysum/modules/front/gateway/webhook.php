<?php

namespace IPS\paysum\modules\front\gateway;

use IPS\paysum\extensions\nexus\Gateway\paysum;
use PaySum\Checkout\Checkout;

defined('\IPS\SUITE_UNIQUE_KEY') || exit;

require_once dirname(__DIR__, 3) . '/Checkout.php';

class _webhook extends \IPS\Dispatcher\Controller
{
    protected function manage()
    {
        $status = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $this->complete() : 405;
        \IPS\Output::i()->sendOutput('{}', $status, 'application/json');
    }

    private function complete(): int
    {
        $body = file_get_contents('php://input', false, null, 0, 262145) ?: '';
        $header = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
        $unsigned = strlen($body) <= 262144 ? json_decode($body, true) : null;
        $ref = $unsigned['data']['client_reference_id'] ?? '';
        $id = $unsigned['data']['id'] ?? '';
        if ($header === '' || !is_string($ref) || !preg_match('/^ips:([1-9][0-9]{0,17})$/D', $ref, $match) || !Checkout::sessionId($id)) {
            return 401;
        }
        $transactionId = (int) $match[1];
        $verified = false;
        try {
            $transaction = \IPS\nexus\Transaction::load($transactionId);
            $gateway = $transaction->method;
            if (!$gateway instanceof paysum) {
                return 401;
            }
            $settings = json_decode($gateway->settings, true) ?: [];
            $event = Checkout::event($body, $header, $settings['webhook_secret'] ?? '');
            if (!$event) {
                return 401;
            }
            $verified = true;
            if (($event['event'] ?? '') !== 'checkout.session.completed') {
                return 200;
            }
            if ($transaction->gw_id !== $id) {
                return 409;
            }
            if ($transaction->status === $transaction::STATUS_PAID) {
                return 200;
            }
            if ($transaction->status !== $transaction::STATUS_PENDING) {
                return 409;
            }
            $session = paysum::request($settings['api_key'] ?? '', $id);
            if (($session['id'] ?? '') !== $id || ($session['status'] ?? '') !== 'completed'
                || !Checkout::matches($session, $ref, $transaction->amount->currency, $transaction->amount->amountAsString())) {
                return 409;
            }
            $db = \IPS\Db::i();
            $lock = 'paysum:' . substr(hash('sha256', \IPS\SUITE_UNIQUE_KEY . ':' . $transactionId), 0, 40);
            if ((int) $db->query("SELECT GET_LOCK('{$lock}', 5)")->fetch_row()[0] !== 1) {
                return 503;
            }
            try {
                $status = $db->select('t_status', 'nexus_transactions', ['t_id=? AND t_gw_id=?', $transactionId, $id])->first();
                if ($status === $transaction::STATUS_PAID) {
                    return 200;
                }
                if ($status !== $transaction::STATUS_PENDING) {
                    return 409;
                }
                $transaction->approve();
                return 200;
            } finally {
                $db->query("SELECT RELEASE_LOCK('{$lock}')");
            }
        } catch (\OutOfRangeException $e) {
            return $verified ? 404 : 401;
        } catch (\Throwable $e) {
            return 503;
        }
    }
}
