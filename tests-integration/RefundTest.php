<?php

use KhipuWhmcs\Gateway;
use KhipuWhmcs\RefundRules;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\Balance;

/**
 * khipu_refund(), khipu_account_balance() and the wallet note of
 * khipu_config() against the demo account.
 *
 * The tests marked as such move demo money: they refund small amounts of a
 * paid payment created by `dev/integration-test.sh <version> seed`.
 */
class RefundTest extends TestCase
{
    /**
     * Calls the module the way WHMCS's "Refund through Gateway" does.
     *
     * @return array khipu_refund() result
     */
    private function refund($paymentId, $amount, array $override = array())
    {
        return khipu_refund(array_merge(Whmcs::params(), array(
            'transid' => $paymentId, 'amount' => $amount, 'currency' => 'CLP',
        ), $override));
    }

    public function testFullyRefundedPaymentIsDeclinedWithTheReason()
    {
        $fixture = Whmcs::fixture($this, function ($p) {
            return $p['remaining'] <= 0;
        }, 'Needs a fully refunded payment recorded by this module.');

        $result = $this->refund($fixture['row']->transid, '100.00');

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('reversado por completo', $result['rawdata']['resultado']);
        $this->assertStringContainsString('No se devolvió dinero', $result['rawdata']['resultado']);
    }

    public function testUnknownPaymentIsDeclined()
    {
        $result = $this->refund('zzzzzzzzzzzz', '100.00');

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('no encuentra este pago', $result['rawdata']['resultado']);
    }

    public function testRejectedApiKeyIsNamedAsSuch()
    {
        $result = $this->refund('zzzzzzzzzzzz', '100.00', array('api_key' => 'not-a-valid-key'));

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('API Key', $result['rawdata']['resultado']);
    }

    public function testRefundAlreadyInProgressSendsNothing()
    {
        $paymentId = 'refundlocked';
        $lock = 'khipu_whmcs_refund_' . md5($paymentId);
        $holder = Whmcs::holdLock($lock);

        try {
            $start = microtime(true);
            $result = $this->refund($paymentId, '100.00');
            $elapsed = microtime(true) - $start;
        } finally {
            Whmcs::release($holder, $lock);
        }

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('en curso', $result['rawdata']['resultado']);
        $this->assertLessThan(1, $elapsed, 'it must not wait for the lock nor call Khipu');
    }

    public function testConvertedRefundWithoutRatesSendsNothing()
    {
        $start = microtime(true);
        $result = $this->refund('zzzzzzzzzzzz', '4750.00', array('currency' => 'XXX', 'convertto' => '2', 'basecurrency' => 'USD'));

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('No se envió nada a Khipu', $result['rawdata']['resultado']);
        $this->assertLessThan(1, microtime(true) - $start, 'it must not call Khipu');
    }

    public function testWalletBalanceIsShownToWhmcs()
    {
        $wallet = Whmcs::khipu()->getWalletBalance();
        $balances = khipu_account_balance(Whmcs::params())->all();

        if (!$wallet['ok']) {
            $this->assertCount(0, $balances);

            return;
        }

        $this->assertCount(1, $balances);
        $this->assertInstanceOf(Balance::class, reset($balances));
    }

    public function testWalletBalanceWithARejectedKeyRendersNothing()
    {
        $this->assertCount(0, khipu_account_balance(array_merge(Whmcs::params(), array('api_key' => 'not-a-valid-key')))->all());
    }

    public function testConfigurationOutsideTheSettingsPageAsksKhipuNothing()
    {
        $this->assertArrayNotHasKey('UsageNotes', khipu_config());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSettingsPageShowsTheWalletBalance()
    {
        define('ADMINAREA', true);
        $_SERVER['SCRIPT_NAME'] = '/admin/configgateways.php';

        // The balance endpoint sometimes takes longer than the page's 6 s.
        for ($try = 0; $try < 3; $try++) {
            $note = khipu_config()['UsageNotes'];
            if (false === strpos($note['Value'], 'Recarga la página')) {
                break;
            }
        }

        // A balance slower than 6 s every time still shows the timeout note, which is correct.
        $timedOut = RefundRules::walletBalanceText(array('ok' => false, 'http' => 0, 'errors' => array()));
        $this->assertSame('System', $note['Type']);
        $this->assertContains($note['Value'], array(
            Gateway::html(RefundRules::walletBalanceText(Whmcs::khipu()->getWalletBalance())),
            Gateway::html($timedOut),
        ));
    }

    // --- demo money from here on ---------------------------------------------

    /**
     * The seeded payment, if it can still take $needed (in Khipu's currency).
     * Only that one: these tests refund for real, so any other paid payment in
     * the dev WHMCS is off limits.
     */
    private function refundable($needed)
    {
        return Whmcs::fixture($this, function ($p) use ($needed) {
            return Whmcs::isSeedInvoice($p['row']->invoiceid)
                && null !== $p['custom'] && 'CLP' === $p['custom']['invoice_currency'] && $p['remaining'] >= $needed;
        }, 'Needs the seeded payment with balance left: run dev/integration-test.sh 9.0.9 seed and pay it.');
    }

    /** @return float what WHMCS already refunded on this payment row */
    private function refundedInWhmcs($row)
    {
        return (float) Capsule::table('tblaccounts')->where('refundid', $row->id)->sum('amountout');
    }

    public function testPartialRefundsThroughWhmcsAddUpToTheCharge()
    {
        $fixture = $this->refundable(5);
        $row = $fixture['row'];
        $ratio = (float) $fixture['payment']['amount'] / $fixture['custom']['invoice_amount'];
        $before = $this->refundedInWhmcs($row);
        $khipuBefore = $fixture['remaining'];

        $this->assertSame('success', refundInvoicePayment($row->id, 1.25, true));
        $this->assertSame('success', refundInvoicePayment($row->id, 1.25, true));

        $after = Whmcs::khipu()->getPayment($row->transid)['data'];
        $khipuAfter = (float) $after['amount'] - (isset($after['total_refunded']) ? (float) $after['total_refunded'] : 0.0);

        // Rounded as one running total, not part by part.
        $expected = round(($before + 2.5) * $ratio) - round($before * $ratio);
        $this->assertEquals($expected, $khipuBefore - $khipuAfter, 'Khipu refunded ' . ($khipuBefore - $khipuAfter) . ' CLP');
        $this->assertEqualsWithDelta($before + 2.5, $this->refundedInWhmcs($row), 0.001);
    }

    public function testRefundIdIsWhatWhmcsRecords()
    {
        $fixture = $this->refundable(2);
        $row = $fixture['row'];
        $lastRefundRow = (int) Capsule::table('tblaccounts')->where('refundid', $row->id)->max('id');

        $this->assertSame('success', refundInvoicePayment($row->id, 1.00, true));

        $refundRow = Capsule::table('tblaccounts')->where('refundid', $row->id)->where('id', '>', $lastRefundRow)->first();
        $this->assertNotNull($refundRow);
        $refunds = Whmcs::khipu()->getPayment($row->transid)['data'];
        if (isset($refunds['refunds'])) {
            $this->assertContains($refundRow->transid, array_column($refunds['refunds'], 'id'));
        }
    }
}
