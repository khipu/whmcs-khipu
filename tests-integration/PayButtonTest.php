<?php

use KhipuWhmcs\PayLink;
use KhipuWhmcs\PaymentCustom;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * The pay button and callbacks/khipu_redirect.php, creating real (unpaid)
 * payments in the demo account. Unpaid payments expire on their own.
 */
class PayButtonTest extends TestCase
{
    const REDIRECT = 'modules/gateways/callback/khipu_redirect.php';

    private function requirePublicUrl()
    {
        if (!Whmcs::hasPublicUrl()) {
            $this->markTestSkipped('Khipu only creates payments for a public https notify_url: run through dev/integration-test.sh, which starts the tunnel.');
        }
    }

    public function testPaymentIsCreatedFromWhmcsDataAndSignedCustom()
    {
        $this->requirePublicUrl();
        $invoiceId = Whmcs::invoice('CLP', '1000.50');

        $paymentId = Whmcs::createPayment($invoiceId);
        $payment = Whmcs::khipu()->getPayment($paymentId)['data'];

        // CLP has no cents: 1000.50 is charged 1001, and custom keeps the invoice amount.
        $this->assertSame('1001.0000', $payment['amount']);
        $this->assertSame('CLP', $payment['currency']);
        $this->assertSame((string) $invoiceId, $payment['transaction_id']);
        $this->assertSame(
            array('invoice_id' => (string) $invoiceId, 'invoice_amount' => 1000.5, 'invoice_currency' => 'CLP', 'amount' => 1001.0, 'currency' => 'CLP'),
            PaymentCustom::verify($payment['custom'], Whmcs::secret())
        );
        $this->assertNull(PaymentCustom::verify($payment['custom'], 'not-the-secret'));

        $base = rtrim(Whmcs::params()['systemurl'], '/') . '/';
        $this->assertSame($base . 'modules/gateways/callback/khipu.php', $payment['notify_url']);
        $this->assertSame('3.0', $payment['notify_api_version']);
        $this->assertSame($base . 'viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true', $payment['return_url']);
        $this->assertSame($base . 'viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true', $payment['cancel_url']);
    }

    public function testFormWithAmountsOrUrlsOfItsOwnIsIgnored()
    {
        $this->requirePublicUrl();
        $invoiceId = Whmcs::invoice('CLP', '2000');

        // 2.x created the payment from these fields; now only the signed id counts.
        $form = array_merge(Whmcs::payForm($invoiceId), array(
            'amount' => '1', 'currency' => 'USD', 'notify_url' => 'https://attacker.example/notify',
        ));
        $response = Whmcs::request('POST', self::REDIRECT, $form);
        $this->assertSame(303, $response['status']);

        $payment = Whmcs::khipu()->getPayment(substr($response['location'], -12))['data'];
        $this->assertSame('2000.0000', $payment['amount']);
        $this->assertSame('CLP', $payment['currency']);
        $this->assertStringNotContainsString('attacker', $payment['notify_url']);
    }

    public function testButtonSignedWithAnUntrimmedSecretStillWorks()
    {
        $this->requirePublicUrl();
        $invoiceId = Whmcs::invoice('CLP', '1500');

        $form = Whmcs::payForm($invoiceId, array('secret' => '  ' . Whmcs::secret() . "\n"));
        $response = Whmcs::request('POST', self::REDIRECT, $form);

        $this->assertSame(303, $response['status']);
        $this->assertStringStartsWith('https://', $response['location']);
    }

    public function testTamperedFormIsRefused()
    {
        $form = Whmcs::payForm(Whmcs::invoice('CLP', '1000'));
        $form['invoice_id'] = (string) ($form['invoice_id'] + 1);

        $response = Whmcs::request('POST', self::REDIRECT, $form);

        $this->assertSame(400, $response['status']);
    }

    public function testExpiredFormGoesBackToItsInvoice()
    {
        $invoiceId = Whmcs::invoice('CLP', '1000');
        $form = PayLink::encode($invoiceId, Whmcs::secret(), time() - PayLink::TTL - 60);

        $response = Whmcs::request('POST', self::REDIRECT, $form);

        $this->assertSame(303, $response['status']);
        $this->assertStringEndsWith('viewinvoice.php?id=' . $invoiceId, $response['location']);
    }

    public function testFormLeftOpenFromVersion2GoesBackToItsInvoice()
    {
        $invoiceId = Whmcs::invoice('CLP', '1000');

        $response = Whmcs::request('POST', self::REDIRECT, array(
            'transaction_id' => (string) $invoiceId, 'amount' => '1000', 'currency' => 'CLP',
        ));

        $this->assertSame(303, $response['status']);
        $this->assertStringEndsWith('viewinvoice.php?id=' . $invoiceId, $response['location']);
    }

    public function testCancelledInvoiceIsNotCharged()
    {
        $invoiceId = Whmcs::invoice('CLP', '1000');
        $form = Whmcs::payForm($invoiceId);
        $this->assertSame('success', localAPI('UpdateInvoice', array('invoiceid' => $invoiceId, 'status' => 'Cancelled'))['result']);

        $response = Whmcs::request('POST', self::REDIRECT, $form);

        $this->assertSame(303, $response['status']);
        $this->assertStringEndsWith('viewinvoice.php?id=' . $invoiceId, $response['location']);
    }

    public function testGetIsSentToTheClientArea()
    {
        $response = Whmcs::request('GET', self::REDIRECT);

        $this->assertSame(303, $response['status']);
        $this->assertStringEndsWith('clientarea.php', $response['location']);
    }

    public function testConvertedInvoiceChargesTheProcessingCurrencyAndSignsTheInvoiceAmount()
    {
        $this->requirePublicUrl();
        $clp = (string) Capsule::table('tblcurrencies')->where('code', 'CLP')->value('id');
        $rate = (float) Capsule::table('tblcurrencies')->where('code', 'CLP')->value('rate')
            / (float) Capsule::table('tblcurrencies')->where('code', 'USD')->value('rate');
        $previous = Whmcs::rawSetting('convertto');

        Whmcs::setRawSetting('convertto', $clp);
        try {
            $invoiceId = Whmcs::invoice('USD', '10.00');
            $payment = Whmcs::khipu()->getPayment(Whmcs::createPayment($invoiceId))['data'];
        } finally {
            Whmcs::setRawSetting('convertto', $previous);
        }

        $charged = round(10 * $rate);
        $this->assertSame('CLP', $payment['currency']);
        $this->assertEquals($charged, (float) $payment['amount']);
        $this->assertSame(
            array('invoice_id' => (string) $invoiceId, 'invoice_amount' => 10.0, 'invoice_currency' => 'USD', 'amount' => $charged, 'currency' => 'CLP'),
            PaymentCustom::verify($payment['custom'], Whmcs::secret())
        );
    }
}
