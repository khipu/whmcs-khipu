<?php
/**
 * Pay button target: creates the Khipu payment for an invoice and sends the
 * payer to Khipu.
 *
 * Everything sent to Khipu is read from WHMCS here, never from the form: the
 * form only proves it was rendered by khipu_link() (see KhipuWhmcs\PayLink).
 */

use KhipuWhmcs\Client;
use KhipuWhmcs\Gateway;
use KhipuWhmcs\PayLink;
use KhipuWhmcs\PaymentCustom;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../khipu/autoload.php';

$gatewayParams = getGatewayVariables(Gateway::MODULE);

if (empty($gatewayParams['type'])) {
    die('Module Not Activated');
}

if ('POST' !== (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) {
    Gateway::redirect(Gateway::systemUrl($gatewayParams) . 'clientarea.php');
}

$link = PayLink::decode(
    isset($_POST['invoice_id']) ? $_POST['invoice_id'] : null,
    isset($_POST['exp']) ? $_POST['exp'] : null,
    isset($_POST['sig']) ? $_POST['sig'] : null,
    Gateway::param($gatewayParams['secret']),
    time()
);

if (!$link['ok']) {
    if (PayLink::EXPIRED === $link['reason']) {
        // Authentic but old: the invoice page renders a fresh one.
        Gateway::redirect(Gateway::invoiceUrl($gatewayParams, $link['invoice_id']));
    }

    // A 2.x form still open in a browser posts the invoice id as transaction_id.
    $legacyInvoiceId = Gateway::param(isset($_POST['transaction_id']) ? $_POST['transaction_id'] : '');
    if ('' !== $legacyInvoiceId && ctype_digit($legacyInvoiceId)) {
        Gateway::redirect(Gateway::invoiceUrl($gatewayParams, $legacyInvoiceId));
    }

    Gateway::errorPage(400, 'Este enlace de pago no es válido. Vuelve a la factura e inténtalo de nuevo.', Gateway::systemUrl($gatewayParams) . 'clientarea.php');
}

$invoiceId = (int) $link['invoice_id'];
$invoiceUrl = Gateway::invoiceUrl($gatewayParams, $invoiceId);

if (!Gateway::isConfigured($gatewayParams)) {
    Gateway::errorPage(503, 'El pago con Khipu no está disponible en este momento.', $invoiceUrl);
}

$invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));

if (!is_array($invoice) || !isset($invoice['result']) || 'success' !== $invoice['result']) {
    Gateway::errorPage(404, 'No encontramos esta factura.', Gateway::systemUrl($gatewayParams) . 'clientarea.php');
}

// Paid, cancelled or moved to another gateway since the page was rendered.
if ('Unpaid' !== $invoice['status'] || Gateway::MODULE !== $invoice['paymentmethod']) {
    Gateway::redirect($invoiceUrl);
}

// The same parameters WHMCS hands to khipu_link(): current balance, already
// converted when the gateway has "Convert To For Processing" set.
$params = getGatewayVariables(Gateway::MODULE, $invoiceId);
$currency = strtoupper(Gateway::param($params['currency']));
$amount = Client::formatAmount($params['amount'], $currency);

if ((float) $amount <= 0) {
    Gateway::redirect($invoiceUrl);
}

$invoiceAmount = isset($params['basecurrencyamount']) && '' !== Gateway::param($params['basecurrencyamount'])
    ? $params['basecurrencyamount'] : $params['amount'];
$invoiceCurrency = isset($params['basecurrency']) && '' !== Gateway::param($params['basecurrency'])
    ? $params['basecurrency'] : $currency;

$payload = array(
    'subject' => Gateway::truncate(Gateway::param($params['description']), 255),
    'currency' => $currency,
    'amount' => (float) $amount,
    'transaction_id' => (string) $invoiceId,
    'custom' => PaymentCustom::build($invoiceId, $invoiceAmount, $invoiceCurrency, $amount, $currency, Gateway::param($gatewayParams['secret'])),
    'return_url' => Gateway::invoiceUrl($gatewayParams, $invoiceId, 'paymentsuccess=true'),
    'cancel_url' => Gateway::invoiceUrl($gatewayParams, $invoiceId, 'paymentfailed=true'),
    'notify_url' => Gateway::systemUrl($gatewayParams) . 'modules/gateways/callback/khipu.php',
    'notify_api_version' => '3.0',
);

$email = isset($params['clientdetails']['email']) ? Gateway::param($params['clientdetails']['email']) : '';
if ('' !== $email) {
    $payload['payer_email'] = $email;
}

$client = Client::withTimeouts(Gateway::param($gatewayParams['api_key']), Client::CHECKOUT_TIMEOUTS);
$response = $client->createPayment($payload);
$paymentUrl = $response['ok'] ? (string) $response['data']['payment_url'] : '';

if (0 === strpos($paymentUrl, 'https://')) {
    Gateway::redirect($paymentUrl);
}

// Khipu's reasons talk about the merchant setup (notify_url, credentials), so
// they go to the gateway log, not to the payer.
$errors = array();
foreach ($response['errors'] as $error) {
    $errors[] = ('' !== $error['field'] ? $error['field'] . ': ' : '') . $error['message'];
}
logTransaction($gatewayParams['name'], array(
    'resultado' => 'No se pudo crear el pago en Khipu.',
    'factura' => $invoiceId,
    'http' => $response['http'],
    'detalle' => $response['ok'] ? 'La respuesta no trae una payment_url https.' : $response['message'],
    'errores' => implode(' | ', $errors),
), 'Error');

Gateway::errorPage(502, 'No pudimos iniciar el pago con Khipu. Inténtalo de nuevo en unos minutos o elige otro medio de pago.', $invoiceUrl);
