<?php

namespace KhipuWhmcs;

/**
 * The `custom` field of every Khipu payment this module creates.
 *
 * It records the invoice amount and currency the payment was created for.
 * Khipu only knows the amount it charged, which differs from the invoice when
 * WHMCS converts currency ("Convert To For Processing") or when CLP rounds
 * cents away.
 *
 * It is signed with a key derived from the merchant secret, and the signature
 * covers what Khipu was asked to charge. Anyone holding the API Key (another
 * integration of the same account, or a leaked key) can create payments with
 * any `custom`; without the secret they cannot make one this module accepts,
 * and a signed one copied from a real payment only fits that same charge.
 */
class PaymentCustom
{
    /**
     * @param string|int   $invoiceId
     * @param string|float $invoiceAmount   in the invoice currency
     * @param string       $invoiceCurrency
     * @param string       $amount          what Khipu is asked to charge, as sent
     * @param string       $currency        currency Khipu charges in
     * @param string       $secret          merchant secret
     *
     * @return string JSON
     */
    public static function build($invoiceId, $invoiceAmount, $invoiceCurrency, $amount, $currency, $secret)
    {
        $data = array(
            'whmcs_invoice_id' => (string) $invoiceId,
            'invoice_amount' => number_format((float) $invoiceAmount, 2, '.', ''),
            'invoice_currency' => strtoupper((string) $invoiceCurrency),
            'amount' => (string) $amount,
            'currency' => strtoupper((string) $currency),
        );
        $data['sig'] = self::sign($data, $secret);

        return json_encode($data);
    }

    /**
     * Reads and verifies a `custom`. For the webhook: what it returns decides how
     * much is credited, so only a signature made with the current secret counts.
     *
     * @param mixed  $custom the payment's `custom` field
     * @param string $secret merchant secret
     *
     * @return array|null see read(); null if it is not ours or its signature does not verify
     */
    public static function verify($custom, $secret)
    {
        $data = self::decode($custom);

        if (null === $data || '' === (string) $secret || !hash_equals(self::sign($data, $secret), $data['sig'])) {
            return null;
        }

        return self::fields($data);
    }

    /**
     * Reads a `custom` without checking its signature. For refunds: the payment
     * was verified when the webhook recorded it, and a refund is started by an
     * admin, so a secret rotated since must not stop it.
     *
     * @param mixed $custom the payment's `custom` field
     *
     * @return array|null array('invoice_id' => string, 'invoice_amount' => float,
     *                    'invoice_currency' => string, 'amount' => float, 'currency' => string),
     *                    or null if it is not ours
     */
    public static function read($custom)
    {
        $data = self::decode($custom);

        return (null === $data) ? null : self::fields($data);
    }

    /**
     * @param mixed $custom
     *
     * @return array|null the decoded fields, all strings and well formed
     */
    private static function decode($custom)
    {
        if (!is_string($custom) || '' === $custom) {
            return null;
        }

        $data = json_decode($custom, true);

        if (!is_array($data)) {
            return null;
        }

        foreach (array('whmcs_invoice_id', 'invoice_amount', 'invoice_currency', 'amount', 'currency', 'sig') as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                return null;
            }
        }

        if (!ctype_digit($data['whmcs_invoice_id'])
            || !is_numeric($data['invoice_amount']) || !is_numeric($data['amount'])
            || !preg_match('/^[A-Z]{3}$/', $data['invoice_currency']) || !preg_match('/^[A-Z]{3}$/', $data['currency'])
        ) {
            return null;
        }

        return $data;
    }

    /**
     * @return array
     */
    private static function fields(array $data)
    {
        return array(
            'invoice_id' => $data['whmcs_invoice_id'],
            'invoice_amount' => (float) $data['invoice_amount'],
            'invoice_currency' => $data['invoice_currency'],
            'amount' => (float) $data['amount'],
            'currency' => $data['currency'],
        );
    }

    /**
     * @param string $secret
     *
     * @return string hex
     */
    private static function sign(array $data, $secret)
    {
        // A key of its own, so this signature is never valid anywhere else.
        $key = hash_hmac('sha256', 'whmcs-khipu-custom-v1', (string) $secret, true);

        return hash_hmac('sha256', implode('|', array(
            $data['whmcs_invoice_id'], $data['invoice_amount'], $data['invoice_currency'], $data['amount'], $data['currency'],
        )), $key);
    }
}
