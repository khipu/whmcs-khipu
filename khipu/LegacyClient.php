<?php

namespace KhipuWhmcs;

/**
 * Looks up a payment by the notification token of Khipu's notification API
 * 1.3, used by payments that version 2.x created.
 *
 * Kept for the upgrade window only: a payment created before upgrading still
 * notifies the old way, and dropping it would leave a paid invoice unpaid.
 * Authenticates like the v2 client did: HMAC-SHA256 of the request with the
 * merchant secret, sent as `Authorization: <receiver_id>:<hash>`.
 */
class LegacyClient
{
    const URL = 'https://khipu.com/api/2.0/payments';

    const REQUIRED_FIELDS = array('payment_id', 'receiver_id', 'status', 'status_detail', 'amount', 'currency', 'notification_token');

    /** @var string */
    private $receiverId;

    /** @var string */
    private $secret;

    /** @var callable */
    private $transport;

    /**
     * @param string        $receiverId
     * @param string        $secret
     * @param callable|null $transport see Http
     */
    public function __construct($receiverId, $secret, $transport = null)
    {
        $this->receiverId = (string) $receiverId;
        $this->secret = (string) $secret;
        $this->transport = (null === $transport)
            ? Http::curlTransport(Client::BACKEND_TIMEOUTS[0], Client::BACKEND_TIMEOUTS[1])
            : $transport;
    }

    /**
     * GET /api/2.0/payments?notification_token=...
     *
     * @param string $token
     *
     * @return array same shape as Client results
     */
    public function getPaymentByToken($token)
    {
        $token = (string) $token;
        $toSign = 'GET&' . rawurlencode(self::URL) . '&notification_token=' . rawurlencode($token);

        $headers = array(
            'Authorization: ' . $this->receiverId . ':' . hash_hmac('sha256', $toSign, $this->secret),
            'User-Agent: ' . Version::USER_AGENT,
            'Accept: application/json',
        );

        $raw = call_user_func($this->transport, 'GET', self::URL . '?notification_token=' . rawurlencode($token), $headers, null);

        $status = isset($raw['status']) ? (int) $raw['status'] : 0;
        $error = isset($raw['error']) ? (string) $raw['error'] : '';
        $decoded = ('' === $error) ? json_decode(isset($raw['body']) ? (string) $raw['body'] : '', true) : null;
        $ok = 200 === $status && is_array($decoded) && !RefundRules::missingFields($decoded, self::REQUIRED_FIELDS);

        return array(
            'ok' => $ok,
            'http' => $status,
            'data' => $ok ? $decoded : array(),
            'errors' => $ok ? array() : RefundRules::parseErrors($decoded),
            'message' => $ok ? '' : ('' !== $error ? $error : 'Error HTTP ' . $status),
            'outcome' => RefundRules::classifyOutcome($status, $error, $ok),
        );
    }
}
