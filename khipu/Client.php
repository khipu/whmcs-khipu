<?php

namespace KhipuWhmcs;

/**
 * Client for the Khipu v3 endpoints the module uses.
 *
 * Knows nothing about WHMCS: it only speaks HTTP and turns each response into
 * array('ok', 'http', 'data', 'errors', 'message', 'outcome').
 */
class Client
{
    /** A payer is waiting for the page; refunds and webhooks can wait longer. */
    const CHECKOUT_TIMEOUTS = array(5, 15);
    const BACKEND_TIMEOUTS = array(10, 20);
    /** Billing > Transactions List renders while this runs. */
    const DISPLAY_TIMEOUTS = array(3, 6);

    /**
     * Fields a 200 must carry to count as a Khipu answer. A proxy or WAF can
     * answer `200 {"message": "..."}` without ever reaching Khipu; without this
     * check that body would pass as a refund and be recorded with zeros.
     */
    const REQUIRED_PAYMENT_CREATE_FIELDS = array('payment_id', 'payment_url');
    const REQUIRED_PAYMENT_FIELDS = array('payment_id', 'receiver_id', 'status', 'status_detail', 'amount', 'currency');
    const REQUIRED_REFUND_FIELDS = array('id', 'payment_id', 'refunded_amount', 'total_refunded', 'remaining');
    const REQUIRED_BALANCE_FIELDS = array('balance', 'currency');

    /** @var string */
    private $apiKey;

    /** @var callable */
    private $transport;

    /**
     * @param string        $apiKey
     * @param callable|null $transport see Http; null uses cURL with BACKEND_TIMEOUTS
     */
    public function __construct($apiKey, $transport = null)
    {
        $this->apiKey = (string) $apiKey;
        $this->transport = (null === $transport)
            ? Http::curlTransport(self::BACKEND_TIMEOUTS[0], self::BACKEND_TIMEOUTS[1])
            : $transport;
    }

    /**
     * @param array $timeouts one of the *_TIMEOUTS constants
     *
     * @return self
     */
    public static function withTimeouts($apiKey, array $timeouts)
    {
        return new self($apiKey, Http::curlTransport($timeouts[0], $timeouts[1]));
    }

    /**
     * Decimals a payment amount is rounded to in this currency.
     *
     * Checkout, notification and refund all have to agree on it: Khipu charges
     * CLP in whole pesos.
     *
     * @param string $currency ISO 4217
     *
     * @return int
     */
    public static function amountPrecision($currency)
    {
        return ('CLP' === strtoupper((string) $currency)) ? 0 : 2;
    }

    /**
     * Amount as Khipu expects it: no thousands separator, '.' for decimals,
     * rounded to the currency precision.
     *
     * @param float|string $amount
     * @param string       $currency
     *
     * @return string
     */
    public static function formatAmount($amount, $currency)
    {
        $precision = self::amountPrecision($currency);

        return number_format(round((float) $amount, $precision), $precision, '.', '');
    }

    /**
     * POST /v3/payments
     *
     * @return array
     */
    public function createPayment(array $payload)
    {
        return $this->request('POST', '/payments', $payload, self::REQUIRED_PAYMENT_CREATE_FIELDS);
    }

    /**
     * GET /v3/payments/{id}
     *
     * @param string $paymentId
     *
     * @return array
     */
    public function getPayment($paymentId)
    {
        return $this->request('GET', '/payments/' . rawurlencode((string) $paymentId), null, self::REQUIRED_PAYMENT_FIELDS);
    }

    /**
     * POST /v3/refunds
     *
     * @param string      $paymentId
     * @param string      $type      RefundRules::TYPE_FULL or TYPE_PARTIAL
     * @param string|null $amount    required for partial, omitted for full
     *
     * @return array
     */
    public function refund($paymentId, $type, $amount = null)
    {
        $payload = array(
            'type' => (string) $type,
            'payment_id' => (string) $paymentId,
        );

        // `full` refunds whatever is left; sending `amount` with it breaks the contract.
        if (RefundRules::TYPE_PARTIAL === $type) {
            $payload['amount'] = (string) $amount;
        }

        return $this->request('POST', '/refunds', $payload, self::REQUIRED_REFUND_FIELDS);
    }

    /**
     * GET /v3/refund-wallet/balance
     *
     * A balance (even 0) means the account has the refund wallet enabled; a
     * 400 on `receiver_id` means it does not.
     *
     * @return array
     */
    public function getWalletBalance()
    {
        return $this->request('GET', '/refund-wallet/balance', null, self::REQUIRED_BALANCE_FIELDS);
    }

    /**
     * @param string     $method
     * @param string     $path
     * @param array|null $payload
     * @param array      $required fields the 200 must carry
     *
     * @return array
     */
    private function request($method, $path, $payload, array $required)
    {
        return self::interpret(Http::request($this->transport, $this->apiKey, $method, $path, $payload), $required);
    }

    /**
     * @param array $raw      array('status' => int, 'body' => string, 'error' => string)
     * @param array $required
     *
     * @return array
     */
    private static function interpret(array $raw, array $required)
    {
        $status = isset($raw['status']) ? (int) $raw['status'] : 0;
        $transportError = isset($raw['error']) ? (string) $raw['error'] : '';

        $result = array(
            'ok' => false,
            'http' => $status,
            'data' => array(),
            'errors' => array(),
            'message' => '',
            'outcome' => RefundRules::OUTCOME_UNKNOWN,
        );

        if ('' !== $transportError) {
            $result['message'] = $transportError;

            return $result;
        }

        $decoded = json_decode(isset($raw['body']) ? (string) $raw['body'] : '', true);

        if (200 === $status) {
            $missing = RefundRules::missingFields($decoded, $required);
            $bodyIsValid = is_array($decoded) && !$missing;
            $result['outcome'] = RefundRules::classifyOutcome($status, '', $bodyIsValid);

            if (!$bodyIsValid) {
                $result['message'] = is_array($decoded)
                    ? 'Respuesta incompleta de Khipu: faltan los campos ' . implode(', ', $missing) . '.'
                    : 'Respuesta inválida de Khipu (cuerpo vacío o mal formado).';

                return $result;
            }

            $result['ok'] = true;
            $result['data'] = $decoded;

            return $result;
        }

        $result['errors'] = RefundRules::parseErrors($decoded);
        $result['message'] = RefundRules::errorText($result['errors'], $status);
        // Without field errors, Khipu's top-level message is the readable reason.
        if (!$result['errors'] && is_array($decoded) && isset($decoded['message']) && is_scalar($decoded['message'])
            && '' !== trim((string) $decoded['message'])
        ) {
            $result['message'] = trim((string) $decoded['message']) . ' (HTTP ' . $status . ')';
        }
        $result['outcome'] = RefundRules::classifyOutcome($status, '', is_array($decoded));

        return $result;
    }
}
