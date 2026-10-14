<?php
/**
 * One-time seed for the integration suite, with a person paying in the demo bank:
 *
 * 1. A payment from the pay button (1000.50 CLP, charged 1001). Paid, the
 *    webhook must record it for 1000.50 under its payment_id. It is also the
 *    payment RefundTest refunds from.
 * 2. A payment created straight through the API with a `custom` forged without
 *    the secret, claiming a much larger invoice amount. Paid, the webhook must
 *    refuse it and leave its invoice unpaid.
 *
 * Run through dev/integration-test.sh <version> seed.
 */

use KhipuWhmcs\Gateway;
use KhipuWhmcs\PaymentCustom;
use WHMCS\Database\Capsule;

chdir('/var/www/html');
require '/var/www/html/init.php';
require_once '/var/www/html/includes/gatewayfunctions.php';
require_once '/var/www/html/modules/gateways/khipu.php';
require_once __DIR__ . '/support/Whmcs.php';

Whmcs::ensureCurrencies();

if (!Whmcs::hasPublicUrl()) {
    fwrite(STDERR, "WHMCS's System URL is not public https: run this through dev/integration-test.sh.\n");
    exit(1);
}

$genuineInvoice = Whmcs::invoice('CLP', '1000.50', Whmcs::SEED_DESCRIPTION);
$genuine = Whmcs::createPayment($genuineInvoice);

$forgedInvoice = Whmcs::invoice('CLP', '900000', 'Prueba de custom falsificado');
$created = Whmcs::khipu()->createPayment(array(
    'subject' => 'Prueba de custom falsificado',
    'currency' => 'CLP',
    'amount' => 1000,
    'transaction_id' => (string) $forgedInvoice,
    'custom' => PaymentCustom::build($forgedInvoice, '900000', 'CLP', '1000', 'CLP', 'not-the-merchant-secret'),
    'notify_url' => Gateway::systemUrl(Whmcs::params()) . 'modules/gateways/callback/khipu.php',
    'notify_api_version' => '3.0',
));
if (!$created['ok']) {
    fwrite(STDERR, 'Khipu refused the forged payment: ' . $created['message'] . "\n");
    exit(1);
}
$forged = $created['data']['payment_id'];
$since = Whmcs::lastLogId();

echo "Pay both in the demo bank:\n";
echo "  1) https://khipu.com/payment/info/{$genuine}   (invoice {$genuineInvoice}, \$1.001)\n";
echo "  2) https://khipu.com/payment/info/{$forged}   (invoice {$forgedInvoice}, \$1.000)\n";
echo "Waiting up to 20 minutes for Khipu's notifications...\n";

$deadline = time() + 1200;
$genuineDone = $forgedDone = false;
while (time() < $deadline && !($genuineDone && $forgedDone)) {
    sleep(10);
    $genuineDone = $genuineDone || Whmcs::rows($genuine) > 0;
    foreach (Whmcs::logSince($since) as $entry) {
        if (false !== strpos($entry->data, $forged) && 'Rejected' === $entry->result) {
            $forgedDone = true;
        }
    }
}

// Unpaid payments say nothing about the webhook: report that instead of
// checks that would fail (or pass) for the wrong reason.
$unpaid = array();
foreach (array($genuine => $genuineInvoice, $forged => $forgedInvoice) as $paymentId => $invoiceId) {
    $lookup = Whmcs::khipu()->getPayment($paymentId);
    if ($lookup['ok'] && 'done' !== $lookup['data']['status']) {
        $unpaid[] = $paymentId . ' (invoice ' . $invoiceId . ')';
    }
}
if ($unpaid) {
    echo "  NOT PAID in time, still pending at Khipu: " . implode(', ', $unpaid) . "\n";
    echo "  Nothing was checked. Run the seed again when you can pay right away: these links die with this tunnel.\n";
    exit(2);
}

$checks = array(
    'genuine payment recorded' => 1 === Whmcs::rows($genuine),
    'genuine payment recorded for the invoice amount (1000.50)' => abs((float) Capsule::table('tblaccounts')->where('transid', $genuine)->value('amountin') - 1000.50) < 0.001,
    'genuine invoice paid' => 'Paid' === Capsule::table('tblinvoices')->where('id', $genuineInvoice)->value('status'),
    'forged payment refused by the webhook' => $forgedDone,
    'forged payment not recorded' => 0 === Whmcs::rows($forged),
    'forged invoice still unpaid' => 'Unpaid' === Capsule::table('tblinvoices')->where('id', $forgedInvoice)->value('status'),
);

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . "\n";
    $failed += $ok ? 0 : 1;
}

exit($failed ? 1 : 0);
