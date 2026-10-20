<?php

namespace KhipuWhmcs;

/**
 * Signs the invoice "pay" form, so only a form WHMCS rendered can start a
 * payment.
 *
 * Version 2.x posted amount, currency, return_url and notify_url as plain
 * hidden fields and created the payment with whatever arrived. Now the form
 * carries only the invoice id, an expiry and an HMAC over both; the amount is
 * read from WHMCS when the payer clicks. The HMAC key is derived from the
 * merchant secret, so it never leaves the server.
 */
class PayLink
{
    /** Seconds a rendered form stays valid; an older one is sent back to the invoice. */
    const TTL = 7200;

    const INVALID = 'invalid';
    const EXPIRED = 'expired';

    /**
     * @param string|int $invoiceId
     * @param string     $secret    merchant secret
     * @param int        $now       Unix time
     *
     * @return array|null array('invoice_id', 'exp', 'sig') as form fields; null without a secret
     */
    public static function encode($invoiceId, $secret, $now)
    {
        $invoiceId = (string) $invoiceId;

        if ('' === (string) $secret || !ctype_digit($invoiceId)) {
            return null;
        }

        $exp = (string) ((int) $now + self::TTL);

        return array('invoice_id' => $invoiceId, 'exp' => $exp, 'sig' => self::sign($invoiceId, $exp, $secret));
    }

    /**
     * @param mixed  $invoiceId posted `invoice_id`
     * @param mixed  $exp       posted `exp`
     * @param mixed  $sig       posted `sig`
     * @param string $secret
     * @param int    $now
     *
     * @return array array('ok' => bool, 'reason' => '', INVALID or EXPIRED, 'invoice_id' => string)
     *               `invoice_id` is set on EXPIRED too: it is authentic, just old,
     *               so the payer can be sent back to that invoice.
     */
    public static function decode($invoiceId, $exp, $sig, $secret, $now)
    {
        $invalid = array('ok' => false, 'reason' => self::INVALID, 'invoice_id' => '');

        if ('' === (string) $secret || !is_string($invoiceId) || !is_string($exp) || !is_string($sig)
            || !ctype_digit($invoiceId) || !ctype_digit($exp)
        ) {
            return $invalid;
        }

        if (!hash_equals(self::sign($invoiceId, $exp, $secret), $sig)) {
            return $invalid;
        }

        if ((int) $exp < (int) $now) {
            return array('ok' => false, 'reason' => self::EXPIRED, 'invoice_id' => $invoiceId);
        }

        return array('ok' => true, 'reason' => '', 'invoice_id' => $invoiceId);
    }

    /**
     * @param string $invoiceId
     * @param string $exp
     * @param string $secret
     *
     * @return string hex
     */
    private static function sign($invoiceId, $exp, $secret)
    {
        // A key of its own, so a signature made here is never a valid Khipu one.
        $key = hash_hmac('sha256', 'whmcs-khipu-pay-link-v1', (string) $secret, true);

        return hash_hmac('sha256', $invoiceId . '|' . $exp, $key);
    }
}
