<?php
/**
 * Khipu payment notification (webhook). Public URL.
 *
 * API 3.0 notifications are signed JSON; API 1.3 ones (payments created by
 * version 2.x before upgrading) are a form post with a notification token.
 * Either way the amount recorded comes from asking Khipu about the payment,
 * never from the notification itself.
 *
 * Status codes matter: Khipu retries anything but a 200 for up to two days.
 * 200 = done or nothing to do, 4xx = refused, 503 = try again later.
 */

use KhipuWhmcs\Client;
use KhipuWhmcs\Gateway;
use KhipuWhmcs\LegacyClient;
use KhipuWhmcs\Notification;
use KhipuWhmcs\RefundRules;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../khipu/autoload.php';

/** Seconds a notification waits for another one of the same payment. */
const KHIPU_LOCK_TIMEOUT = 10;

$gatewayParams = getGatewayVariables(Gateway::MODULE);

if (empty($gatewayParams['type'])) {
    // 503 and not die(): a module switched off for a moment must not lose payments.
    Gateway::respond(503, 'Module Not Activated');
}

if ('POST' !== (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) {
    Gateway::respond(405, 'Method Not Allowed');
}

$receiverId = Gateway::param($gatewayParams['receiver_id']);
$secret = Gateway::param($gatewayParams['secret']);

if (isset($_POST['api_version']) && '1.3' === $_POST['api_version']) {
    khipu_cb_handle_legacy($gatewayParams, $receiverId, $secret);
}

khipu_cb_handle_v3($gatewayParams, $receiverId, $secret);

/**
 * API 3.0: verify the signature, then confirm the payment through the API.
 */
function khipu_cb_handle_v3(array $gatewayParams, $receiverId, $secret)
{
    $rawBody = (string) file_get_contents('php://input');
    $signature = isset($_SERVER['HTTP_X_KHIPU_SIGNATURE']) ? $_SERVER['HTTP_X_KHIPU_SIGNATURE'] : null;

    if (!Notification::verifySignature($rawBody, $signature, $secret)) {
        // Logged because a wrong "Llave secreta" fails every real notification here.
        khipu_cb_log($gatewayParams, array('resultado' => 'Firma inválida: revisa la Llave secreta en la configuración de Khipu.'), 'Invalid Signature');
        Gateway::respond(400, 'Invalid signature');
    }

    $notice = Notification::parse($rawBody);

    if (null === $notice) {
        khipu_cb_log($gatewayParams, array('resultado' => 'Notificación sin payment_id, receiver_id o transaction_id.'), 'Rejected');
        Gateway::respond(400, 'Invalid notification');
    }

    $paymentId = (string) $notice['payment_id'];

    if (!Notification::sameId($notice['receiver_id'], $receiverId)) {
        khipu_cb_log($gatewayParams, array('resultado' => 'La notificación es de otra cuenta de cobro: revisa el ID Cobrador.', 'payment_id' => $paymentId), 'Rejected');
        Gateway::respond(400, 'Wrong receiver');
    }

    khipu_cb_with_lock($paymentId, function () use ($gatewayParams, $receiverId, $notice, $paymentId) {
        if (khipu_cb_is_recorded($paymentId)) {
            Gateway::respond(200, 'Already recorded');
        }

        $client = Client::withTimeouts(Gateway::param($gatewayParams['api_key']), Client::BACKEND_TIMEOUTS);
        $payment = $client->getPayment($paymentId);

        if (!$payment['ok']) {
            khipu_cb_lookup_failed($gatewayParams, $paymentId, $payment);
        }

        $decision = Notification::evaluate($payment['data'], $receiverId, array(
            'payment_id' => $paymentId,
            'transaction_id' => $notice['transaction_id'],
        ), Gateway::param($gatewayParams['secret']));

        khipu_cb_apply($gatewayParams, $decision, $payment['data'], '3.0');
    });
}

/**
 * API 1.3, sent by payments 2.x created: only a token, looked up with the v2 API.
 */
function khipu_cb_handle_legacy(array $gatewayParams, $receiverId, $secret)
{
    $token = Gateway::param(isset($_POST['notification_token']) ? $_POST['notification_token'] : '');

    if ('' === $token) {
        Gateway::respond(400, 'Missing notification_token');
    }

    // 2.x recorded the token as the transaction id, under an undefined gateway
    // variable: its rows may not say khipu, so this check spans every gateway.
    if (khipu_cb_is_recorded($token, true)) {
        Gateway::respond(200, 'Already recorded');
    }

    $legacy = new LegacyClient($receiverId, $secret);
    $payment = $legacy->getPaymentByToken($token);

    if (!$payment['ok']) {
        khipu_cb_lookup_failed($gatewayParams, '', $payment);
    }

    $paymentId = (string) $payment['data']['payment_id'];

    khipu_cb_with_lock($paymentId, function () use ($gatewayParams, $receiverId, $secret, $token, $payment, $paymentId) {
        if (khipu_cb_is_recorded($paymentId)) {
            Gateway::respond(200, 'Already recorded');
        }

        $decision = Notification::evaluate($payment['data'], $receiverId, array('notification_token' => $token), $secret, true);

        khipu_cb_apply($gatewayParams, $decision, $payment['data'], '1.3');
    });
}

/**
 * Records the payment, or answers why not.
 *
 * @param array  $decision   Notification::evaluate() result
 * @param array  $payment    the payment as Khipu reported it
 * @param string $apiVersion
 */
function khipu_cb_apply(array $gatewayParams, array $decision, array $payment, $apiVersion)
{
    // Ids, amounts and state only: the payment also carries the payer's name,
    // RUT and bank account, which the gateway log does not need.
    $log = array(
        'resultado' => $decision['reason'],
        'payment_id' => isset($payment['payment_id']) ? (string) $payment['payment_id'] : '',
        'transaction_id' => isset($payment['transaction_id']) ? (string) $payment['transaction_id'] : '',
        'monto_khipu' => (isset($payment['amount']) ? (string) $payment['amount'] : '') . ' ' . (isset($payment['currency']) ? (string) $payment['currency'] : ''),
        'estado' => (isset($payment['status']) ? (string) $payment['status'] : '') . ' / ' . (isset($payment['status_detail']) ? (string) $payment['status_detail'] : ''),
        'api_notificacion' => $apiVersion,
    );

    $refusals = array(
        Notification::RETRY => array('Pending', 503, 'Payment not confirmed yet'),
        Notification::IGNORE => array('Ignored', 200, 'Nothing to record'),
        Notification::REJECT => array('Rejected', 400, 'Notification rejected'),
    );

    if (isset($refusals[$decision['action']])) {
        list($status, $code, $text) = $refusals[$decision['action']];
        khipu_cb_log($gatewayParams, $log, $status);
        Gateway::respond($code, $text);
    }

    // Both end the request on their own: an unknown invoice (die) or a
    // payment already recorded under this id.
    $invoiceId = checkCbInvoiceID($decision['invoice_id'], $gatewayParams['name']);
    checkCbTransID($decision['payment_id']);

    addInvoicePayment($invoiceId, $decision['payment_id'], $decision['amount'], 0, Gateway::MODULE);

    // addInvoicePayment() does not report failures: check that the row exists,
    // or a 200 here would make Khipu stop notifying a payment WHMCS never saw.
    if (!khipu_cb_is_recorded($decision['payment_id'])) {
        $log['resultado'] = 'WHMCS no registró el pago; se respondió 503 para que Khipu reintente.';
        khipu_cb_log($gatewayParams, $log, 'Error');
        Gateway::respond(503, 'Could not record payment');
    }

    $log['resultado'] = trim('Pago registrado en la factura ' . $invoiceId . ' por ' . $decision['amount'] . '. ' . $decision['reason']);
    khipu_cb_log($gatewayParams, $log, 'Successful');
    Gateway::respond(200, 'Notification received correctly');
}

/**
 * Answers a failed payment lookup: refused by Khipu (4xx) or try again (503).
 *
 * @param string $paymentId
 */
function khipu_cb_lookup_failed(array $gatewayParams, $paymentId, array $result)
{
    $rejected = RefundRules::OUTCOME_REJECTED === $result['outcome'];

    khipu_cb_log($gatewayParams, array(
        'resultado' => $rejected
            ? 'Khipu no entregó el pago con las credenciales configuradas: revisa la API Key, el ID Cobrador y la Llave secreta.'
            : 'No se pudo consultar el pago en Khipu; se respondió 503 para que reintente.',
        'payment_id' => $paymentId,
        'http' => $result['http'],
        'detalle' => $result['message'],
    ), $rejected ? 'Rejected' : 'Error');

    Gateway::respond($rejected ? 400 : 503, 'Payment lookup failed');
}

/**
 * Runs $fn holding a MySQL lock on the payment.
 *
 * Khipu retries until it gets an answer, so two notifications for the same
 * payment can arrive together; each would see it unrecorded and add it twice.
 *
 * @param string   $paymentId
 * @param callable $fn
 */
function khipu_cb_with_lock($paymentId, $fn)
{
    $name = 'khipu_whmcs_' . md5($paymentId);
    $row = Capsule::connection()->selectOne('SELECT GET_LOCK(?, ?) AS l', array($name, KHIPU_LOCK_TIMEOUT));

    if (!$row || 1 !== (int) $row->l) {
        Gateway::respond(503, 'Notification already being processed');
    }

    // Every path ends in exit(), which skips `finally`; MySQL drops the lock
    // when the connection closes at the end of the request anyway.
    register_shutdown_function(function () use ($name) {
        try {
            Capsule::connection()->select('SELECT RELEASE_LOCK(?)', array($name));
        } catch (Exception $e) {
            // The connection is gone and took the lock with it.
        }
    });

    call_user_func($fn);
}

/**
 * @param string $transactionId
 * @param bool   $anyGateway    also match rows recorded under another or no gateway
 *
 * @return bool whether a transaction with this id is already recorded
 */
function khipu_cb_is_recorded($transactionId, $anyGateway = false)
{
    if ('' === (string) $transactionId) {
        return false;
    }

    $query = Capsule::table('tblaccounts')->where('transid', (string) $transactionId);

    if (!$anyGateway) {
        $query->where('gateway', Gateway::MODULE);
    }

    return $query->count() > 0;
}

/**
 * @param array  $data
 * @param string $status
 */
function khipu_cb_log(array $gatewayParams, array $data, $status)
{
    logTransaction($gatewayParams['name'], $data, $status);
}
