<?php

use KhipuWhmcs\PaymentCustom;
use KhipuWhmcs\RefundRules;
use PHPUnit\Framework\TestCase;

class RefundRulesTest extends TestCase
{
    const SECRET = 'test-secret';

    private function payment($amount, $currency, $custom = null, array $extra = array())
    {
        $payment = array('payment_id' => 'pay', 'amount' => $amount, 'currency' => $currency);
        if (null !== $custom) {
            $payment['custom'] = $custom;
        }

        return array_merge($payment, $extra);
    }

    // --- toPaymentAmount ----------------------------------------------------

    public function testSameCurrencyWithoutCustomIsSentAsIs()
    {
        $result = RefundRules::toPaymentAmount('5000.00', 'CLP', $this->payment('20000.0000', 'CLP'));

        $this->assertSame(array('ok' => true, 'amount' => '5000'), $result);
    }

    public function testRefundingTheWholeInvoiceMapsToWhatKhipuCharged()
    {
        // CLP has no cents: an 11900.50 invoice was charged 11901.
        $payment = $this->payment('11901.0000', 'CLP', PaymentCustom::build(7, '11900.50', 'CLP', '11901', 'CLP', self::SECRET));

        $this->assertSame('11901', RefundRules::toPaymentAmount('11900.50', 'CLP', $payment)['amount']);
    }

    public function testConvertedInvoiceIsScaledByTheChargedRatio()
    {
        // USD invoice paid in CLP through "Convert To For Processing".
        $payment = $this->payment('9500.0000', 'CLP', PaymentCustom::build(7, '10.00', 'USD', '9500', 'CLP', self::SECRET));

        $this->assertSame('9500', RefundRules::toPaymentAmount('10.00', 'USD', $payment)['amount']);
        $this->assertSame('4750', RefundRules::toPaymentAmount('5.00', 'USD', $payment)['amount']);
        $this->assertSame('3135', RefundRules::toPaymentAmount('3.30', 'USD', $payment)['amount']);
    }

    public function testPartialRefundsRoundCumulatively()
    {
        // Rounded one by one, both halves would be 5951 and the second would exceed what is left.
        $payment = $this->payment('11901.0000', 'CLP', PaymentCustom::build(7, '11900.50', 'CLP', '11901', 'CLP', self::SECRET));

        $this->assertSame('5951', RefundRules::toPaymentAmount('5950.25', 'CLP', $payment)['amount']);
        $this->assertSame('5950', RefundRules::toPaymentAmount('5950.25', 'CLP', $payment, '5950.25')['amount']);
    }

    public function testConvertedRefundsInPartsAddUpToTheCharge()
    {
        $payment = $this->payment('9500.0000', 'CLP', PaymentCustom::build(7, '10.00', 'USD', '9500', 'CLP', self::SECRET));

        $parts = array();
        $before = 0;
        foreach (array('3.33', '3.33', '3.34') as $part) {
            $parts[] = RefundRules::toPaymentAmount($part, 'USD', $payment, $before)['amount'];
            $before += (float) $part;
        }

        $this->assertSame(array('3164', '3163', '3173'), $parts);
        $this->assertSame(9500, array_sum(array_map('intval', $parts)));
    }

    public function testCustomFromBeforeASecretRotationStillMapsRefunds()
    {
        // Verified when the webhook recorded the payment; a later rotation must not block refunds.
        $payment = $this->payment('9500.0000', 'CLP', PaymentCustom::build(7, '10.00', 'USD', '9500', 'CLP', 'previous-secret'));

        $this->assertSame('4750', RefundRules::toPaymentAmount('5.00', 'USD', $payment)['amount']);
    }

    public function testAmountAlreadyInThePaymentCurrencyIsNotScaledAgain()
    {
        // WHMCS may convert the refund itself before calling the module.
        $payment = $this->payment('9500.0000', 'CLP', PaymentCustom::build(7, '10.00', 'USD', '9500', 'CLP', self::SECRET));

        $this->assertSame('4000', RefundRules::toPaymentAmount('4000.00', 'CLP', $payment)['amount']);
    }

    public function testUnrelatedCurrencyIsRefused()
    {
        $result = RefundRules::toPaymentAmount('10.00', 'EUR', $this->payment('9500.0000', 'CLP', PaymentCustom::build(7, '10.00', 'USD', '9500', 'CLP', self::SECRET)));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('EUR', $result['message']);
    }

    public function testZeroAfterRoundingIsRefused()
    {
        $result = RefundRules::toPaymentAmount('0.40', 'CLP', $this->payment('1000.0000', 'CLP'));

        $this->assertFalse($result['ok']);
    }

    public function testMoreThanThePaymentIsRefused()
    {
        $result = RefundRules::toPaymentAmount('1001', 'CLP', $this->payment('1000.0000', 'CLP'));

        $this->assertFalse($result['ok']);
    }

    // --- unconvert ----------------------------------------------------------

    public function testUnconvertUndoesWhmcsConversion()
    {
        // WHMCS 9.0.9 passed a 5 USD refund as 4750.00 CLP (CLP rate 950, USD 1).
        $this->assertSame(5.0, RefundRules::unconvert('4750.00', '950.00000', '1.00000'));
        $this->assertSame(3.33, RefundRules::unconvert('3163.50', 950, 1));
    }

    public function testUnconvertSnapsToWhatIsLeft()
    {
        // CLP invoice processed in USD: 9999 CLP went out as 10.50 USD and comes back as 10000.
        $this->assertSame(9999.0, RefundRules::unconvert('10.50', '0.00105', '1', 9999));
        $this->assertSame(10000.0, RefundRules::unconvert('10.50', '0.00105', '1', 20000));
        // 9990 of 9999 left goes out as 10.49 and comes back 9990.48: an intentional partial, not snapped.
        $this->assertSame(9990.48, RefundRules::unconvert('10.49', '0.00105', '1', 9999));
    }

    public function testUnconvertNeedsUsableRates()
    {
        $this->assertNull(RefundRules::unconvert('10', 0, 1));
        $this->assertNull(RefundRules::unconvert('10', 950, null));
    }

    // --- error grammar ------------------------------------------------------

    public function testParseErrorsKeepsOnlyWellFormedItems()
    {
        $errors = RefundRules::parseErrors(array('errors' => array(
            array('field' => 'amount', 'message' => 'Muy alto'),
            'basura',
            array('field' => array('x'), 'message' => 'Sin campo'),
        )));

        $this->assertSame(array(
            array('field' => 'amount', 'message' => 'Muy alto'),
            array('field' => '', 'message' => 'Sin campo'),
        ), $errors);
        $this->assertSame(array(), RefundRules::parseErrors('texto'));
        $this->assertSame(array(), RefundRules::parseErrors(array('errors' => 'texto')));
    }

    public function testWalletDisabledIsRecognisedByFieldOrMessage()
    {
        $this->assertTrue(RefundRules::isWalletDisabled(array(array('field' => 'receiver_id', 'message' => 'x'))));
        $this->assertTrue(RefundRules::isWalletDisabled(array(array('field' => '', 'message' => 'La cuenta de cobro con ID 1 no está habilitada para hacer reversas.'))));
        $this->assertFalse(RefundRules::isWalletDisabled(array(array('field' => 'payment_id', 'message' => 'El pago no es reembolsable'))));
    }

    public function testNotRefundableIsNotWalletDisabled()
    {
        $errors = array(array('field' => 'payment_id', 'message' => 'El pago no es reembolsable'));

        $this->assertTrue(RefundRules::isNotRefundable($errors));
        $this->assertFalse(RefundRules::isWalletDisabled($errors));
    }

    public function testErrorTextFallsBackToTheHttpCode()
    {
        $this->assertSame('a. b', RefundRules::errorText(array(array('field' => '', 'message' => 'a'), array('field' => '', 'message' => 'b.')), 400));
        $this->assertSame('Error HTTP 401', RefundRules::errorText(array(), 401));
    }

    public function testClassifyOutcome()
    {
        $this->assertSame(RefundRules::OUTCOME_OK, RefundRules::classifyOutcome(200, '', true));
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, RefundRules::classifyOutcome(200, '', false));
        $this->assertSame(RefundRules::OUTCOME_REJECTED, RefundRules::classifyOutcome(400, '', true));
        $this->assertSame(RefundRules::OUTCOME_REJECTED, RefundRules::classifyOutcome(404, '', false));
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, RefundRules::classifyOutcome(500, '', true));
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, RefundRules::classifyOutcome(0, 'timeout', false));
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, RefundRules::classifyOutcome(302, '', true));
    }

    public function testMissingFieldsTreatsNullAsMissing()
    {
        $this->assertSame(array('b'), RefundRules::missingFields(array('a' => '1', 'b' => null), array('a', 'b')));
        $this->assertSame(array('a'), RefundRules::missingFields('x', array('a')));
        $this->assertSame(array(), RefundRules::missingFields(array('a' => 0), array('a')));
    }

    // --- resolveUnknown -----------------------------------------------------

    private function snapshot($refunds = null, $detail = 'normal')
    {
        $payment = array('status_detail' => $detail);
        if (null !== $refunds) {
            $payment['refunds'] = $refunds;
        }

        return RefundRules::refundSnapshot($payment);
    }

    public function testNewRefundWithTheSameAmountIsConfirmed()
    {
        $before = $this->snapshot();
        $after = $this->snapshot(array(array('id' => 'r1', 'amount' => '5000.0000', 'status' => 'pending')), 'partially-refunded');

        $this->assertSame(
            array('state' => RefundRules::RESOLVED_CONFIRMED, 'refund_id' => 'r1'),
            RefundRules::resolveUnknown($before, $after, '5000')
        );
    }

    public function testOlderRefundsDoNotCount()
    {
        $old = array('id' => 'r0', 'amount' => '1000.0000');
        $before = $this->snapshot(array($old), 'partially-refunded');
        $after = $this->snapshot(array($old, array('id' => 'r1', 'amount' => '2000.0000')), 'partially-refunded');

        $this->assertSame('r1', RefundRules::resolveUnknown($before, $after, '2000')['refund_id']);
    }

    public function testNewRefundWithAnotherAmountIsOnlyLikely()
    {
        $before = $this->snapshot();
        $after = $this->snapshot(array(array('id' => 'r1', 'amount' => '3000.0000')), 'partially-refunded');

        $this->assertSame(RefundRules::RESOLVED_LIKELY, RefundRules::resolveUnknown($before, $after, '5000')['state']);
    }

    public function testChangedStatusWithoutTheRefundsFieldIsLikely()
    {
        $this->assertSame(
            RefundRules::RESOLVED_LIKELY,
            RefundRules::resolveUnknown($this->snapshot(), $this->snapshot(null, 'partially-refunded'), '5000')['state']
        );
    }

    public function testUntouchedNormalPaymentMeansNothingWasRefunded()
    {
        $this->assertSame(
            RefundRules::RESOLVED_ABSENT,
            RefundRules::resolveUnknown($this->snapshot(), $this->snapshot(), '5000')['state']
        );
    }

    public function testUnchangedRefundsListMeansNothingWasRefunded()
    {
        $list = array(array('id' => 'r0', 'amount' => '1000.0000'));

        $this->assertSame(
            RefundRules::RESOLVED_ABSENT,
            RefundRules::resolveUnknown($this->snapshot($list, 'partially-refunded'), $this->snapshot($list, 'partially-refunded'), '5000')['state']
        );
    }

    public function testPartiallyRefundedWithoutTheFieldCannotBeDecided()
    {
        $this->assertSame(
            RefundRules::RESOLVED_UNKNOWN,
            RefundRules::resolveUnknown($this->snapshot(null, 'partially-refunded'), $this->snapshot(null, 'partially-refunded'), '5000')['state']
        );
    }

    public function testExplainNotRefundableNamesTheCause()
    {
        $this->assertStringContainsString('por completo', RefundRules::explainNotRefundable('fully-refunded'));
        $this->assertStringContainsString('180 días', RefundRules::explainNotRefundable('normal'));
        $this->assertStringContainsString('Causas posibles', RefundRules::explainNotRefundable(null));
    }
}
