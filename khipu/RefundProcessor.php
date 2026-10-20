<?php

namespace KhipuWhmcs;

/**
 * Runs a WHMCS refund against Khipu and answers in the shape khipu_refund()
 * must return: array('status' => success|declined|error, 'rawdata', 'transid').
 *
 * WHMCS records the refund only on `success`, and the admin only sees
 * "refund failed" otherwise, with the detail in the gateway log. So each
 * `rawdata['resultado']` says plainly whether money moved and whether retrying
 * is safe: refund requests are not idempotent, two requests are two refunds.
 */
class RefundProcessor
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param string       $paymentId WHMCS transaction id = Khipu payment_id
     * @param string|float $amount          amount WHMCS wants to refund
     * @param string       $currency        currency WHMCS expressed it in
     * @param string|float $alreadyRefunded what WHMCS already refunded on this transaction
     *
     * @return array
     */
    public function refund($paymentId, $amount, $currency, $alreadyRefunded = 0)
    {
        $paymentId = trim((string) $paymentId);
        $log = array(
            'payment_id' => $paymentId,
            'monto_solicitado' => (string) $amount . ' ' . (string) $currency,
        );

        if ('' === $paymentId) {
            return $this->answer('declined', 'La transacción no tiene ID de pago de Khipu. No se envió nada a Khipu.', $log);
        }

        $before = $this->client->getPayment($paymentId);

        if (!$before['ok']) {
            $log['http'] = $before['http'];
            $log['detalle_khipu'] = $before['message'];

            if (401 === $before['http'] || 403 === $before['http']) {
                return $this->answer('declined', 'Khipu rechazó la API Key configurada: revísala en la configuración de Khipu. No se devolvió dinero.', $log);
            }

            // The text only counts on a rejection: a 5xx saying it is a retry, not a verdict.
            if (404 === $before['http']
                || (RefundRules::OUTCOME_REJECTED === $before['outcome'] && RefundRules::isUnknownPayment($before['errors']))
            ) {
                return $this->answer('declined', 'Khipu no encuentra este pago. Si lo registró la versión 2.x del módulo, su ID no es un payment_id: haz la reversa desde el portal de Khipu. No se devolvió dinero.', $log);
            }

            if (RefundRules::OUTCOME_REJECTED === $before['outcome']) {
                return $this->answer('declined', 'Khipu rechazó la consulta del pago: ' . rtrim($before['message'], '. ') . '. No se devolvió dinero.', $log);
            }

            return $this->answer('error', 'No se pudo consultar el pago en Khipu. No se devolvió dinero; puedes reintentar.', $log);
        }

        $payment = $before['data'];
        $mapped = RefundRules::toPaymentAmount($amount, $currency, $payment, $alreadyRefunded);

        if (!$mapped['ok']) {
            return $this->answer('declined', $mapped['message'], $log);
        }

        $sent = $mapped['amount'];
        $log['monto_khipu'] = $sent . ' ' . (string) $payment['currency'];

        // Always partial with an explicit amount: `full` refunds whatever Khipu
        // has left, which WHMCS cannot know if something was refunded outside it.
        $result = $this->client->refund($paymentId, RefundRules::TYPE_PARTIAL, $sent);
        $log['http'] = $result['http'];

        if ($result['ok']) {
            return $this->succeeded($result['data'], $log);
        }

        $log['detalle_khipu'] = $result['message'];

        if (RefundRules::OUTCOME_REJECTED === $result['outcome']) {
            if (RefundRules::isWalletDisabled($result['errors'])) {
                return $this->answer('declined', 'La billetera de reversas no está habilitada en tu cuenta Khipu. Solicítala a soporte@khipu.com. No se devolvió dinero.', $log);
            }

            $text = 'Khipu rechazó la reversa: ' . rtrim($result['message'], '. ') . '.';
            if (RefundRules::isNotRefundable($result['errors'])) {
                // An account without the refund wallet can also get "no es
                // reembolsable"; the balance endpoint tells the two apart.
                $wallet = $this->client->getWalletBalance();
                if (!$wallet['ok'] && RefundRules::isWalletDisabled($wallet['errors'])) {
                    return $this->answer('declined', 'La billetera de reversas no está habilitada en tu cuenta Khipu. Solicítala a soporte@khipu.com. No se devolvió dinero.', $log);
                }

                $text .= ' ' . RefundRules::explainNotRefundable(isset($payment['status_detail']) ? $payment['status_detail'] : null);
            }

            return $this->answer('declined', $text . ' No se devolvió dinero.', $log);
        }

        return $this->resolveUnknown($paymentId, $payment, $sent, $log);
    }

    /**
     * @return array
     */
    private function succeeded(array $data, array $log)
    {
        $log['refund_id'] = (string) $data['id'];
        $log['reversado'] = (string) $data['refunded_amount'];
        $log['restante_por_reversar'] = (string) $data['remaining'];

        $text = 'Reversa aceptada por Khipu.';

        // The normal case, not a shortage: refunds start pending and are
        // completed in Khipu's daily refund batch, even with balance to spare.
        // The wallet is debited then; an unfunded refund expires.
        if (isset($data['message']) && is_scalar($data['message']) && '' !== trim((string) $data['message'])) {
            $log['aviso_khipu'] = (string) $data['message'];
            $text .= ' Quedó en proceso y se concreta en el ciclo diario de reversas de Khipu, si la billetera tiene saldo para las reversas del día. Revisa el saldo en Facturación > Listado de transacciones (Billing > Transactions List).';
        }

        return array_merge($this->answer('success', $text, $log), array('transid' => (string) $data['id']));
    }

    /**
     * A 5xx, a timeout or an unreadable 200: Khipu may have refunded anyway.
     * The payment is read again and its refunds compared with the ones it had
     * before the request.
     *
     * @param string $paymentId
     * @param string $sent
     *
     * @return array
     */
    private function resolveUnknown($paymentId, array $payment, $sent, array $log)
    {
        $after = $this->client->getPayment($paymentId);
        $resolution = array('state' => RefundRules::RESOLVED_UNKNOWN, 'refund_id' => '');

        if ($after['ok']) {
            $resolution = RefundRules::resolveUnknown(
                RefundRules::refundSnapshot($payment),
                RefundRules::refundSnapshot($after['data']),
                $sent
            );
        }

        switch ($resolution['state']) {
            case RefundRules::RESOLVED_CONFIRMED:
                $log['refund_id'] = $resolution['refund_id'];

                return array_merge(
                    $this->answer('success', 'La respuesta de Khipu no fue concluyente, pero al consultar el pago confirma la reversa.', $log),
                    array('transid' => $resolution['refund_id'])
                );

            case RefundRules::RESOLVED_ABSENT:
                // Not "safe to retry": a request that timed out may still be
                // committing on Khipu's side, and refunds are not idempotent.
                return $this->answer('error', 'La respuesta de Khipu no fue concluyente y al consultar el pago todavía no aparece esta reversa, pero puede seguir procesándose. Revisa el pago en el portal de Khipu en unos minutos y reintenta solo si no aparece.', $log);

            case RefundRules::RESOLVED_LIKELY:
                return $this->answer('error', 'RESULTADO DESCONOCIDO: la respuesta de Khipu no fue concluyente y el pago muestra cambios en sus reversas, así que es probable que SÍ se haya reversado. NO reintentes: revisa el pago en el portal de Khipu y, si corresponde, registra la reversa en WHMCS sin enviarla a la pasarela.', $log);

            default:
                return $this->answer('error', 'RESULTADO DESCONOCIDO: no se pudo confirmar si Khipu procesó la reversa. NO reintentes antes de revisar el pago en el portal de Khipu o escribir a soporte@khipu.com con el payment_id.', $log);
        }
    }

    /**
     * @param string $status
     * @param string $text
     *
     * @return array
     */
    private function answer($status, $text, array $log)
    {
        return array(
            'status' => $status,
            'rawdata' => array_merge(array('resultado' => $text), $log),
            'transid' => '',
        );
    }
}
