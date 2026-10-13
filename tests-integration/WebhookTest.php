<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * callbacks/khipu.php against real payments of the demo account. Every
 * notification here is signed by the test; what gets recorded still depends
 * on what Khipu's API says about the payment.
 */
class WebhookTest extends TestCase
{
    const WEBHOOK = 'modules/gateways/callback/khipu.php';

    public function testGetIsRefused()
    {
        $this->assertSame(405, Whmcs::request('GET', self::WEBHOOK)['status']);
    }

    public function testWrongSignatureIsRefusedAndLogged()
    {
        $since = Whmcs::lastLogId();

        $response = Whmcs::notify(array('payment_id' => 'abcdefghijkl', 'receiver_id' => Whmcs::receiverId(), 'transaction_id' => '1'), 'not-the-secret');

        $this->assertSame(400, $response['status']);
        $log = Whmcs::logSince($since);
        $this->assertCount(1, $log);
        $this->assertSame('Invalid Signature', $log[0]->result);
    }

    public function testOtherReceiverIsRefused()
    {
        $response = Whmcs::notify(array('payment_id' => 'abcdefghijkl', 'receiver_id' => '1', 'transaction_id' => '1'));

        $this->assertSame(400, $response['status']);
        $this->assertSame('Wrong receiver', $response['body']);
    }

    public function testNotificationWithoutIdsIsRefused()
    {
        $this->assertSame(400, Whmcs::notify(array('payment_id' => array('x'), 'receiver_id' => Whmcs::receiverId()))['status']);
    }

    public function testUnknownPaymentIsRefused()
    {
        $response = Whmcs::notify(array('payment_id' => 'zzzzzzzzzzzz', 'receiver_id' => Whmcs::receiverId(), 'transaction_id' => '1'));

        $this->assertSame(400, $response['status']);
    }

    public function testUnpaidPaymentIsRetriedAndNotRecorded()
    {
        if (!Whmcs::hasPublicUrl()) {
            $this->markTestSkipped('Needs the tunnel to create a payment: run through dev/integration-test.sh.');
        }
        $invoiceId = Whmcs::invoice('CLP', '1000');
        $paymentId = Whmcs::createPayment($invoiceId);

        $response = Whmcs::notify(array('payment_id' => $paymentId, 'receiver_id' => Whmcs::receiverId(), 'transaction_id' => (string) $invoiceId));

        // 503 so Khipu tries again once the payment is done.
        $this->assertSame(503, $response['status']);
        $this->assertSame(0, Whmcs::rows($paymentId));
        $this->assertSame('Unpaid', Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status'));
    }

    public function testRecordedPaymentIsNotRecordedTwice()
    {
        $fixture = Whmcs::fixture($this, function ($p) {
            return isset($p['payment']['notify_api_version']) && '3.0' === $p['payment']['notify_api_version'];
        }, 'Needs a payment recorded by this module: run dev/integration-test.sh 9.0.9 seed once.');
        $paymentId = $fixture['row']->transid;

        $response = Whmcs::notify(array('payment_id' => $paymentId, 'receiver_id' => Whmcs::receiverId(), 'transaction_id' => (string) $fixture['row']->invoiceid));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Already recorded', $response['body']);
        $this->assertSame(1, Whmcs::rows($paymentId));
    }

    public function testConcurrentNotificationIsSentBackForLater()
    {
        $paymentId = 'lockedpaymnt';
        $lock = 'khipu_whmcs_' . md5($paymentId);
        $holder = Whmcs::holdLock($lock);

        try {
            $start = microtime(true);
            $response = Whmcs::notify(array('payment_id' => $paymentId, 'receiver_id' => Whmcs::receiverId(), 'transaction_id' => '1'));
            $waited = microtime(true) - $start;
        } finally {
            Whmcs::release($holder, $lock);
        }

        $this->assertSame(503, $response['status']);
        $this->assertSame('Notification already being processed', $response['body']);
        $this->assertGreaterThanOrEqual(9, $waited);
    }

    public function testVersion2NotificationIsLookedUpWithTheV2Api()
    {
        // Proves the v2 HMAC lookup against the live API on every run.
        $fixture = Whmcs::fixture($this, function ($p) {
            return isset($p['payment']['notify_api_version']) && '1.3' === $p['payment']['notify_api_version'];
        }, 'Needs a payment created by version 2.x and recorded by 3.x (the upgrade test of the manual).');
        $since = Whmcs::lastLogId();

        $response = Whmcs::request('POST', self::WEBHOOK, array(
            'api_version' => '1.3', 'notification_token' => $fixture['payment']['notification_token'],
        ));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Already recorded', $response['body'], 'a failed v2 lookup answers 400 or 503 instead');
        $this->assertSame(1, Whmcs::rows($fixture['row']->transid));
        $this->assertCount(0, Whmcs::logSince($since));
    }

    public function testVersion2PaymentRecordedWithoutGatewayIsNotRecordedAgain()
    {
        // 2.x passed an undefined gateway variable to addInvoicePayment().
        $token = 'legacytoken' . bin2hex(random_bytes(6));
        Capsule::table('tblaccounts')->insert(array(
            'userid' => Whmcs::clientId('CLP'), 'currency' => 0, 'gateway' => '', 'date' => date('Y-m-d H:i:s'),
            'description' => 'Invoice Payment (2.x)', 'amountin' => 1, 'transid' => $token, 'invoiceid' => 0, 'type' => '',
        ));

        try {
            $response = Whmcs::request('POST', self::WEBHOOK, array('api_version' => '1.3', 'notification_token' => $token));
        } finally {
            Capsule::table('tblaccounts')->where('transid', $token)->delete();
        }

        $this->assertSame(200, $response['status']);
        $this->assertSame('Already recorded', $response['body']);
    }
}
