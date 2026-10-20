<?php

namespace KhipuWhmcs;

/**
 * Pure webhook logic: signature, payload shape, and whether a confirmed Khipu
 * payment should be applied to an invoice.
 *
 * The notification only says "look at payment X". What gets recorded comes
 * from GET /v3/payments/{id}, which needs the API Key, so a replayed or forged
 * notification can never record money Khipu did not receive.
 */
class Notification
{
    const RECORD = 'record';
    /** Valid, but nothing to record: answer 200 so Khipu stops retrying. */
    const IGNORE = 'ignore';
    /** Not applicable now but may be later: answer 503 so Khipu retries. */
    const RETRY = 'retry';
    const REJECT = 'reject';

    /** Fields every v3 notification must carry, as scalars. */
    private static $requiredFields = array('payment_id', 'receiver_id', 'transaction_id');

    /**
     * Verifies `X-Khipu-Signature: t=<ts>,s=<base64 HMAC-SHA256("<t>.<body>")>`.
     *
     * A missing, partial or junk header ends here as false, never as a PHP
     * error: the URL is public and gets probed.
     *
     * @param string $rawBody exactly as received
     * @param mixed  $header
     * @param string $secret
     *
     * @return bool
     */
    public static function verifySignature($rawBody, $header, $secret)
    {
        // Without a secret anything would verify.
        if ('' === (string) $secret || !is_string($header)) {
            return false;
        }

        $t = '';
        $s = '';

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (2 !== count($pair)) {
                continue;
            }

            if ('t' === $pair[0]) {
                $t = $pair[1];
            } elseif ('s' === $pair[0]) {
                $s = $pair[1];
            }
        }

        if ('' === $t || '' === $s) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $t . '.' . (string) $rawBody, (string) $secret, true));

        return hash_equals($expected, $s);
    }

    /**
     * @param string $rawBody
     *
     * @return array|null the decoded notification, or null if its shape is wrong
     */
    public static function parse($rawBody)
    {
        $data = json_decode((string) $rawBody, true);

        if (!is_array($data)) {
            return null;
        }

        // Scalars only: an array here would reach the database layer as is.
        foreach (self::$requiredFields as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field]) || '' === (string) $data[$field]) {
                return null;
            }
        }

        return $data;
    }

    /**
     * @param mixed $a
     * @param mixed $b
     *
     * @return bool
     */
    public static function sameId($a, $b)
    {
        return is_scalar($a) && is_scalar($b) && trim((string) $a) === trim((string) $b) && '' !== trim((string) $a);
    }

    /**
     * Decides what to do with a payment Khipu confirmed through the API.
     *
     * @param array  $payment    GET payment body (v3, or v2 for legacy)
     * @param string $receiverId merchant receiver id from the gateway settings
     * @param array  $expected   fields the payment must match, e.g. the ids the
     *                           notification named
     * @param string $secret     merchant secret, to verify `custom`
     * @param bool   $legacy     payment created by 2.x: no `custom`, the amount
     *                           Khipu charged is recorded as is (2.x behaviour)
     *
     * @return array array('action' => RECORD|IGNORE|RETRY|REJECT, 'reason' => string,
     *               'invoice_id' => string, 'amount' => string, 'payment_id' => string);
     *               on RECORD, `reason` is a warning for the log, or empty
     */
    public static function evaluate(array $payment, $receiverId, array $expected, $secret, $legacy = false)
    {
        $result = array('action' => self::REJECT, 'reason' => '', 'invoice_id' => '', 'amount' => '', 'payment_id' => '');

        if (!isset($payment['receiver_id']) || !self::sameId($payment['receiver_id'], $receiverId)) {
            $result['reason'] = 'El pago pertenece a otra cuenta de cobro (receiver_id).';

            return $result;
        }

        foreach ($expected as $field => $value) {
            if (!isset($payment[$field]) || !self::sameId($payment[$field], $value)) {
                $result['reason'] = 'El pago no coincide con la notificación (' . $field . ').';

                return $result;
            }
        }

        if (!isset($payment['payment_id']) || !is_scalar($payment['payment_id']) || '' === (string) $payment['payment_id']) {
            $result['reason'] = 'Khipu no informó el payment_id.';

            return $result;
        }
        $result['payment_id'] = (string) $payment['payment_id'];

        $status = isset($payment['status']) ? (string) $payment['status'] : '';
        if ('done' !== $status) {
            // The notification may beat the payment's own state change.
            $result['action'] = self::RETRY;
            $result['reason'] = 'El pago aún no está confirmado en Khipu (estado «' . $status . '»).';

            return $result;
        }

        $detail = isset($payment['status_detail']) ? (string) $payment['status_detail'] : '';
        if ('marked-paid-by-receiver' === $detail) {
            $result['action'] = self::IGNORE;
            $result['reason'] = 'El comercio marcó este cobro como pagado por otro medio en Khipu: no hubo transferencia que registrar.';

            return $result;
        }

        $transactionId = isset($payment['transaction_id']) && is_scalar($payment['transaction_id'])
            ? trim((string) $payment['transaction_id']) : '';

        if ('' === $transactionId || !ctype_digit($transactionId)) {
            $result['reason'] = 'El transaction_id del pago no es un número de factura.';

            return $result;
        }

        if ($legacy) {
            $result['action'] = self::RECORD;
            $result['invoice_id'] = $transactionId;
            $result['amount'] = (string) (float) $payment['amount'];
            $result['reason'] = self::outsideRefundWarning($payment, $detail);

            return $result;
        }

        $origin = PaymentCustom::verify(isset($payment['custom']) ? $payment['custom'] : null, $secret);

        if (null === $origin) {
            $result['reason'] = 'El pago no lo creó este módulo con la Llave secreta actual (campo custom ausente o con firma inválida).'
                . ' Si cambiaste la Llave secreta mientras el pago estaba en curso, regístralo a mano.';

            return $result;
        }

        if ($origin['invoice_id'] !== $transactionId) {
            $result['reason'] = 'El campo custom del pago apunta a otra factura.';

            return $result;
        }

        if (strtoupper((string) $payment['currency']) !== $origin['currency']
            || abs((float) $payment['amount'] - $origin['amount']) > RefundRules::EPSILON
        ) {
            $result['reason'] = 'Khipu cobró ' . (string) $payment['amount'] . ' ' . (string) $payment['currency']
                . ', distinto de lo que este módulo pidió (' . $origin['amount'] . ' ' . $origin['currency'] . ').';

            return $result;
        }

        $result['action'] = self::RECORD;
        $result['invoice_id'] = $transactionId;
        // The invoice-currency amount the payment was created for: Khipu
        // charges exactly what was asked, and only this survives conversion.
        $result['amount'] = number_format($origin['invoice_amount'], 2, '.', '');
        $result['reason'] = self::outsideRefundWarning($payment, $detail);

        return $result;
    }

    /**
     * Warning for a payment that was marked for refund or voided from the Khipu
     * portal before WHMCS recorded it (its notification kept failing).
     *
     * The payment is still recorded: Khipu did receive the money, and "marked
     * for refund" is not "paid out" (a refund the wallet never funds expires).
     * Not recording it would answer 200 and stop Khipu's retries for good;
     * recorded, the merchant only has to add the refund once it settles.
     *
     * @param string $detail
     *
     * @return string empty unless the payment has refunds or was voided
     */
    private static function outsideRefundWarning(array $payment, $detail)
    {
        // An empty status_detail is also read as "has refunds"; an absent one
        // says nothing.
        if ('reversed' === $detail) {
            $what = 'el pago se anuló desde Khipu';
        } elseif ('fully-refunded' === $detail) {
            $what = 'se pidió la reversa total del pago desde Khipu';
        } elseif ('partially-refunded' === $detail || (isset($payment['status_detail']) && '' === $payment['status_detail'])) {
            $what = 'se hicieron reversas de este pago desde Khipu'
                . (isset($payment['total_refunded']) && is_scalar($payment['total_refunded'])
                    ? ' (' . (string) $payment['total_refunded'] . ' ' . (isset($payment['currency']) ? (string) $payment['currency'] : '') . ')'
                    : '');
        } else {
            return '';
        }

        return 'ATENCIÓN: antes de que WHMCS registrara este pago, ' . $what . '.'
            . ' Cuando esa reversa se concrete, regístrala en la factura con «Sólo registro (devolución procesada manualmente)»;'
            . ' si expira, no hay nada que registrar.';
    }
}
