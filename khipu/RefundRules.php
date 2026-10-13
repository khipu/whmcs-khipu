<?php

namespace KhipuWhmcs;

/**
 * Pure refund logic: amount mapping, Khipu error grammar and outcome
 * classification. No WHMCS and no network, so all of it is unit tested.
 */
class RefundRules
{
    const TYPE_FULL = 'full';
    const TYPE_PARTIAL = 'partial';

    const OUTCOME_OK = 'ok';
    const OUTCOME_REJECTED = 'rejected';
    const OUTCOME_UNKNOWN = 'unknown';

    /** What a second look at the payment says about a refund of unknown outcome. */
    const RESOLVED_CONFIRMED = 'confirmed';
    const RESOLVED_ABSENT = 'absent';
    const RESOLVED_LIKELY = 'likely';
    const RESOLVED_UNKNOWN = 'unknown';

    /**
     * Substring of Khipu's message for an account without the refund wallet,
     * cut before the noun so a change of wording does not break it. The real
     * signal is `field === 'receiver_id'`; this is the fallback.
     */
    const WALLET_DISABLED_NEEDLE = 'no está habilitada para hacer';

    /** Khipu's text for a payment with nothing left to refund (or past the 180-day window). */
    const NOT_REFUNDABLE_NEEDLE = 'no es reembolsable';

    /** Khipu's text for a payment id it does not know; it comes with a 400, not a 404. */
    const UNKNOWN_PAYMENT_NEEDLE = 'no existe';

    /** Below this two amounts are the same (Khipu returns 4 decimals). */
    const EPSILON = 0.00001;

    /**
     * Maps the amount WHMCS asks to refund onto the Khipu payment.
     *
     * The payment's `custom` carries the invoice amount and currency it was
     * created for (see PaymentCustom). Scaling by Khipu amount / invoice amount
     * covers both currency conversion ("Convert To For Processing") and CLP
     * rounding (an 11900.50 invoice is charged 11901).
     *
     * Rounding is cumulative: this refund is what the running total should
     * be after it, minus what the running total was before. Rounding each
     * refund on its own drifts: two refunds of 5950.25 on that 11901 payment
     * would each round to 5951, and the second would exceed what is left.
     * Cumulatively they are 5951 and 5950, and refunding the whole invoice,
     * in one go or in parts, always adds up to exactly what Khipu charged.
     *
     * @param string|float $amount          what WHMCS wants to refund
     * @param string       $currency        currency WHMCS expressed it in
     * @param array        $payment         GET /v3/payments/{id} body
     * @param string|float $alreadyRefunded what WHMCS already refunded on this
     *                                      transaction, in the same currency
     *
     * @return array array('ok' => true, 'amount' => string) or array('ok' => false, 'message' => string)
     */
    public static function toPaymentAmount($amount, $currency, array $payment, $alreadyRefunded = 0)
    {
        $currency = strtoupper(trim((string) $currency));
        $paymentCurrency = strtoupper((string) $payment['currency']);
        $paymentAmount = (float) $payment['amount'];
        $requested = (float) $amount;
        $before = max(0.0, (float) $alreadyRefunded);
        $origin = PaymentCustom::read(isset($payment['custom']) ? $payment['custom'] : null);

        if (null !== $origin && $origin['invoice_currency'] === $currency && $origin['invoice_amount'] > 0) {
            $invoiceAmount = $origin['invoice_amount'];
            $scale = function ($invoiceSide) use ($invoiceAmount, $paymentAmount, $paymentCurrency) {
                // The whole invoice maps to the whole payment, without float noise.
                $mapped = (abs($invoiceSide - $invoiceAmount) < 0.005)
                    ? $paymentAmount
                    : $invoiceSide * $paymentAmount / $invoiceAmount;

                return (float) Client::formatAmount($mapped, $paymentCurrency);
            };
            $mapped = $scale($before + $requested) - $scale($before);
        } elseif ($paymentCurrency === $currency) {
            $mapped = $requested;
        } else {
            return array(
                'ok' => false,
                'message' => 'La reversa está en ' . $currency . ' pero el pago de Khipu se cobró en '
                    . $paymentCurrency . '. No se envió nada a Khipu.',
            );
        }

        $formatted = Client::formatAmount($mapped, $paymentCurrency);

        if ((float) $formatted <= 0) {
            return array('ok' => false, 'message' => 'El monto a reversar debe ser mayor que cero. No se envió nada a Khipu.');
        }

        if ((float) $formatted > $paymentAmount + self::EPSILON) {
            return array('ok' => false, 'message' => 'El monto a reversar supera el monto del pago en Khipu. No se envió nada a Khipu.');
        }

        return array('ok' => true, 'amount' => $formatted);
    }

    /**
     * Undoes WHMCS's own conversion of a refund amount.
     *
     * With "Convert To For Processing", WHMCS converts the refund into the
     * processing currency at today's rate before calling the module (seen on
     * 9.0.9: 5 USD arrived as 4750.00 CLP). The payment was charged at the rate
     * of its day, so the amount goes back to the invoice currency with the same
     * rates WHMCS used, and toPaymentAmount() maps it with the payment's ratio.
     *
     * @param string|float $amount    what WHMCS passed, in the processing currency
     * @param string|float $fromRate  WHMCS rate of the processing currency
     * @param string|float $toRate    WHMCS rate of the invoice currency
     * @param float|null   $remaining invoice-currency amount WHMCS still lets refund, if known
     *
     * @return float|null null when a rate is unusable
     */
    public static function unconvert($amount, $fromRate, $toRate, $remaining = null)
    {
        if ((float) $fromRate <= 0 || (float) $toRate <= 0) {
            return null;
        }

        $value = round((float) $amount / (float) $fromRate * (float) $toRate, 2);

        // WHMCS rounded to half a processing-currency cent, which back in the
        // invoice currency can be several units (USD to CLP: about 4.75).
        // "Everything left" must stay exactly that.
        $tolerance = max(0.01, 0.005 / (float) $fromRate * (float) $toRate);
        if (null !== $remaining && abs($value - (float) $remaining) <= $tolerance) {
            return round((float) $remaining, 2);
        }

        return $value;
    }

    /**
     * Normalises Khipu's error body: {"status":400,"message":"...","errors":[{"field","message"}]}.
     *
     * @param mixed $body decoded body
     *
     * @return array list of array('field' => string, 'message' => string)
     */
    public static function parseErrors($body)
    {
        $out = array();

        if (!is_array($body) || !isset($body['errors']) || !is_array($body['errors'])) {
            return $out;
        }

        foreach ($body['errors'] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $out[] = array(
                'field' => (isset($error['field']) && is_scalar($error['field'])) ? (string) $error['field'] : '',
                'message' => (isset($error['message']) && is_scalar($error['message'])) ? (string) $error['message'] : '',
            );
        }

        return $out;
    }

    /**
     * Whether the errors say the account has no refund wallet.
     *
     * "no es reembolsable" does NOT count: it is about the payment, and reading
     * it as a disabled wallet would send the merchant after the wrong problem.
     *
     * @return bool
     */
    public static function isWalletDisabled(array $errors)
    {
        foreach ($errors as $error) {
            if (isset($error['field']) && 'receiver_id' === $error['field']) {
                return true;
            }
            if (isset($error['message']) && false !== stripos($error['message'], self::WALLET_DISABLED_NEEDLE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public static function isNotRefundable(array $errors)
    {
        foreach ($errors as $error) {
            if (isset($error['message']) && false !== stripos($error['message'], self::NOT_REFUNDABLE_NEEDLE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool whether the errors say Khipu does not know the payment
     */
    public static function isUnknownPayment(array $errors)
    {
        foreach ($errors as $error) {
            if (isset($error['message']) && false !== stripos($error['message'], self::UNKNOWN_PAYMENT_NEEDLE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Readable text from the errors; the HTTP code when there are none.
     *
     * @param int $httpCode
     *
     * @return string
     */
    public static function errorText(array $errors, $httpCode)
    {
        $parts = array();

        foreach ($errors as $error) {
            if (isset($error['message']) && '' !== rtrim($error['message'], '. ')) {
                $parts[] = rtrim($error['message'], '. ');
            }
        }

        // Khipu's messages carry no final period: joined bare they run together.
        return $parts ? implode('. ', $parts) : 'Error HTTP ' . (int) $httpCode;
    }

    /**
     * Reduces a Khipu response to ok / rejected / unknown.
     *
     * Only a 4xx confirms Khipu did nothing. A 5xx, a timeout or an unreadable
     * 200 leave it UNKNOWN: none of them proves the refund was not made, so
     * calling that a failure invites a second refund.
     *
     * @param int    $httpCode
     * @param string $transportError empty when the request completed
     * @param bool   $bodyIsValid
     *
     * @return string one of the OUTCOME_* constants
     */
    public static function classifyOutcome($httpCode, $transportError, $bodyIsValid)
    {
        if ('' !== (string) $transportError) {
            return self::OUTCOME_UNKNOWN;
        }

        $code = (int) $httpCode;

        if (200 === $code) {
            return $bodyIsValid ? self::OUTCOME_OK : self::OUTCOME_UNKNOWN;
        }

        if ($code >= 400 && $code < 500) {
            return self::OUTCOME_REJECTED;
        }

        return self::OUTCOME_UNKNOWN;
    }

    /**
     * Required fields missing from a response body. A null field counts as
     * missing: null is not an amount.
     *
     * @param mixed $data
     *
     * @return array names of the missing fields
     */
    public static function missingFields($data, array $required)
    {
        if (!is_array($data)) {
            return $required;
        }

        $missing = array();
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * What a payment says about its refunds, to compare before and after a
     * refund of unknown outcome. `refunds` only appears once the payment has
     * one, so its absence means "none yet" or "API without the field".
     *
     * @param array $payment GET /v3/payments/{id} body
     *
     * @return array array('refunds' => array(id => amount), 'has_refunds_field' => bool,
     *               'status_detail' => string|null)
     */
    public static function refundSnapshot(array $payment)
    {
        $refunds = array();

        if (isset($payment['refunds']) && is_array($payment['refunds'])) {
            foreach ($payment['refunds'] as $refund) {
                if (is_array($refund) && isset($refund['id']) && is_scalar($refund['id'])) {
                    $refunds[(string) $refund['id']] = isset($refund['amount']) ? (float) $refund['amount'] : null;
                }
            }
        }

        return array(
            'refunds' => $refunds,
            'has_refunds_field' => isset($payment['refunds']) && is_array($payment['refunds']),
            'status_detail' => isset($payment['status_detail']) ? trim((string) $payment['status_detail']) : null,
        );
    }

    /**
     * Decides what happened to a refund whose response was lost.
     *
     * @param array  $before refundSnapshot() taken before the POST
     * @param array  $after  refundSnapshot() taken after it
     * @param string $amount amount that was sent
     *
     * @return array array('state' => RESOLVED_*, 'refund_id' => string)
     */
    public static function resolveUnknown(array $before, array $after, $amount)
    {
        $new = array_diff_key($after['refunds'], $before['refunds']);

        if (count($new) === 1) {
            $id = (string) key($new);
            $newAmount = reset($new);

            if (null !== $newAmount && abs($newAmount - (float) $amount) < self::EPSILON) {
                return array('state' => self::RESOLVED_CONFIRMED, 'refund_id' => $id);
            }
        }

        if ($new || $after['status_detail'] !== $before['status_detail']) {
            return array('state' => self::RESOLVED_LIKELY, 'refund_id' => '');
        }

        // Same refunds and same state: nothing visible yet. With the field
        // present the list is authoritative for now; without it only an
        // untouched 'normal' payment is. "Not yet" is all either can say.
        if ($after['has_refunds_field'] || 'normal' === $after['status_detail']) {
            return array('state' => self::RESOLVED_ABSENT, 'refund_id' => '');
        }

        return array('state' => self::RESOLVED_UNKNOWN, 'refund_id' => '');
    }

    /**
     * Why Khipu says a payment "no es reembolsable", from its status_detail.
     *
     * @param string|null $statusDetail
     *
     * @return string
     */
    public static function explainNotRefundable($statusDetail)
    {
        switch (trim((string) $statusDetail)) {
            case 'fully-refunded':
                return 'Khipu indica que este pago ya fue reversado por completo.';
            case 'normal':
                return 'Khipu indica que este pago no tiene reversas: lo más probable es que hayan pasado más de 180 días desde su conciliación.';
            case 'partially-refunded':
                return 'Khipu indica que este pago tiene reversas parciales y aún le queda monto: lo más probable es que hayan pasado más de 180 días desde su conciliación.';
            default:
                return 'Causas posibles: el pago ya fue reversado por completo, se reversó por otro medio o pasaron más de 180 días desde su conciliación.';
        }
    }
}
