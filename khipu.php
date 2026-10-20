<?php
/**
 * Khipu payment gateway for WHMCS 8.13 LTS and 9.x.
 *
 * Payments and refunds go through the Khipu API v3. The logic lives in
 * khipu/ (no WHMCS dependencies, unit tested); this file only maps WHMCS
 * parameters onto it.
 *
 * @see docs/technical-manual.md
 */

use KhipuWhmcs\Client;
use KhipuWhmcs\Gateway;
use KhipuWhmcs\PayLink;
use KhipuWhmcs\RefundProcessor;
use KhipuWhmcs\RefundRules;
use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\Balance;
use WHMCS\Module\Gateway\BalanceCollection;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/khipu/autoload.php';

function khipu_MetaData()
{
    return array(
        'DisplayName' => 'Khipu',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function khipu_config()
{
    // `receiver_id` and `secret` keep their 2.x names so upgraded installs keep their values.
    $config = array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Khipu',
        ),
        'api_key' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '40',
            'Description' => 'En tu cuenta Khipu: Opciones de la cuenta → Para integrar Khipu a tu sitio web.',
        ),
        'receiver_id' => array(
            'FriendlyName' => 'ID Cobrador',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'En el mismo lugar que la API Key.',
        ),
        'secret' => array(
            'FriendlyName' => 'Llave secreta',
            'Type' => 'password',
            'Size' => '40',
            'Description' => 'Verifica las notificaciones de pago de Khipu.',
        ),
    );

    $walletNote = khipu_wallet_note();
    if ('' !== $walletNote) {
        $config['UsageNotes'] = array('Type' => 'System', 'Value' => $walletNote);
    }

    return $config;
}

/**
 * Refund wallet balance shown on top of the gateway settings. Only that page
 * asks Khipu: a slow answer must not delay any other page that loads this
 * configuration.
 */
function khipu_wallet_note()
{
    static $loading = false;

    $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';
    if ($loading || !defined('ADMINAREA') || 'configgateways.php' !== $script) {
        return '';
    }

    // getGatewayVariables() can load this configuration again.
    $loading = true;
    try {
        if (!function_exists('getGatewayVariables')) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
        }
        $params = getGatewayVariables(Gateway::MODULE);
    } finally {
        $loading = false;
    }

    $apiKey = Gateway::param(isset($params['api_key']) ? $params['api_key'] : '');
    if ('' === $apiKey) {
        return '';
    }

    return Gateway::html(RefundRules::walletBalanceText(Client::withTimeouts($apiKey, Client::DISPLAY_TIMEOUTS)->getWalletBalance()));
}

/**
 * Pay button on the invoice. Renders a signed form and makes no network call:
 * WHMCS renders this on every invoice view.
 */
function khipu_link($params)
{
    if (!Gateway::isConfigured($params)) {
        return '<p>' . Gateway::html('El pago con Khipu no está disponible en este momento.') . '</p>';
    }

    // Trimmed like everywhere else, or the redirect would reject every link.
    $form = PayLink::encode($params['invoiceid'], Gateway::param($params['secret']), time());

    if (null === $form) {
        return '';
    }

    $html = '<form method="post" action="' . Gateway::html(Gateway::systemUrl($params) . 'modules/gateways/callback/khipu_redirect.php') . '">';
    foreach ($form as $name => $value) {
        $html .= '<input type="hidden" name="' . Gateway::html($name) . '" value="' . Gateway::html($value) . '">';
    }
    $html .= '<input type="image" src="https://s3.amazonaws.com/static.khipu.com/buttons/2015/150x50-transparent.png"'
        . ' alt="' . Gateway::html(isset($params['langpaynow']) ? $params['langpaynow'] : 'Pagar con Khipu') . '">';
    $html .= '</form>';

    return $html;
}

/**
 * Refund through the Khipu refund wallet. See KhipuWhmcs\RefundProcessor.
 */
function khipu_refund($params)
{
    $apiKey = Gateway::param(isset($params['api_key']) ? $params['api_key'] : '');

    if ('' === $apiKey) {
        return array(
            'status' => 'error',
            'rawdata' => array('resultado' => 'Falta la API Key en la configuración de Khipu. No se envió nada a Khipu.'),
            'transid' => '',
        );
    }

    if (!khipu_refund_lock(Gateway::param($params['transid']))) {
        return array(
            'status' => 'error',
            'rawdata' => array(
                'resultado' => 'Ya hay una reversa de este pago en curso. No se envió nada a Khipu: espera a que termine y revisa la transacción antes de reintentar.',
                'payment_id' => Gateway::param($params['transid']),
            ),
            'transid' => '',
        );
    }

    $amount = $params['amount'];
    $currency = Gateway::param($params['currency']);
    $alreadyRefunded = 0;
    $invoiceCurrency = Gateway::param(isset($params['basecurrency']) ? $params['basecurrency'] : '');
    $converted = '' !== Gateway::param(isset($params['convertto']) ? $params['convertto'] : '')
        && '' !== $invoiceCurrency && strtoupper($invoiceCurrency) !== strtoupper($currency);
    $unconverted = null;

    try {
        $original = Capsule::table('tblaccounts')
            ->where('gateway', Gateway::MODULE)
            ->where('transid', Gateway::param($params['transid']))
            ->where('amountin', '>', 0)
            ->orderBy('id')
            ->first();

        // Earlier refunds of this transaction, for the cumulative rounding in RefundRules.
        if ($original) {
            $alreadyRefunded = (float) Capsule::table('tblaccounts')->where('refundid', $original->id)->sum('amountout');
        }

        if ($converted) {
            $unconverted = RefundRules::unconvert(
                $amount,
                Capsule::table('tblcurrencies')->where('code', $currency)->value('rate'),
                Capsule::table('tblcurrencies')->where('code', $invoiceCurrency)->value('rate'),
                $original ? (float) $original->amountin - $alreadyRefunded : null
            );
        }
    } catch (Exception $e) {
        // Without conversion this only costs the cumulative rounding; with it, see below.
    }

    if ($converted) {
        // WHMCS's amount is at today's rate: sent as is, the refund would not
        // match the share of the original charge that WHMCS records.
        if (null === $unconverted) {
            return array(
                'status' => 'error',
                'rawdata' => array(
                    'resultado' => 'No se pudo llevar el monto a la moneda de la factura (tipo de cambio de WHMCS no disponible). No se envió nada a Khipu.',
                    'payment_id' => Gateway::param($params['transid']),
                ),
                'transid' => '',
            );
        }

        $amount = $unconverted;
        $currency = $invoiceCurrency;
    }

    $processor = new RefundProcessor(Client::withTimeouts($apiKey, Client::BACKEND_TIMEOUTS));

    return $processor->refund($params['transid'], $amount, $currency, $alreadyRefunded);
}

/**
 * Claims the refund of a payment for this request, without waiting.
 *
 * Two refunds of one payment at once (a double click is enough) would both
 * read the same earlier refunds, and refunds are not idempotent. The lock is
 * held until the request ends, after WHMCS has written its refund row, so the
 * next refund sees this one. Waiting instead would just run a double click twice.
 *
 * @param string $paymentId
 *
 * @return bool false if another refund of this payment is in progress or the
 *              lock could not be taken
 */
function khipu_refund_lock($paymentId)
{
    $name = 'khipu_whmcs_refund_' . md5((string) $paymentId);

    try {
        $row = Capsule::connection()->selectOne('SELECT GET_LOCK(?, 0) AS l', array($name));
    } catch (Exception $e) {
        return false;
    }

    if (!$row || 1 !== (int) $row->l) {
        return false;
    }

    register_shutdown_function(function () use ($name) {
        try {
            Capsule::connection()->select('SELECT RELEASE_LOCK(?)', array($name));
        } catch (Exception $e) {
            // The connection is gone and took the lock with it.
        }
    });

    return true;
}

/**
 * Refund wallet balance, shown in Billing > Transactions List (WHMCS 8.2+).
 *
 * Empty when the wallet is not enabled or Khipu does not answer: that page
 * must render either way.
 */
function khipu_account_balance(array $params = array()): BalanceCollection
{
    $balances = array();
    $apiKey = Gateway::param(isset($params['api_key']) ? $params['api_key'] : '');

    if ('' !== $apiKey) {
        $result = Client::withTimeouts($apiKey, Client::DISPLAY_TIMEOUTS)->getWalletBalance();

        if ($result['ok']) {
            $balances[] = Balance::factory((float) $result['data']['balance'], strtoupper((string) $result['data']['currency']));
        }
    }

    return BalanceCollection::factoryFromItems(...$balances);
}
