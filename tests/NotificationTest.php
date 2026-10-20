<?php

use KhipuWhmcs\Notification;
use KhipuWhmcs\PaymentCustom;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    const SECRET = 'test-secret';

    // Published example of the Khipu webhook docs (payment-webhook.md).
    const DOC_SECRET = '1a4cbbbeb8bdb7e1d73572b9cc43ce4ce18f79d9';
    const DOC_HEADER = 't=1711965600393,s=rq08YUGSaWa1D0RAwhMnJTRHMNmY4ToCn2Gd6G9LaDg=';
    const DOC_BODY = '{"payment_id":"zfxnocsow6mz","receiver_id":990939,"subject":"TEST_COBRO","amount":"1000.0000","discount":"0.0000","currency":"CLP","receipt_url":"https:\/\/s3.amazonaws.com\/staging.notifications.khipu.com\/CPKH-1804240956-zfxnocsow6mz.pdf","bank":"DemoBank","bank_id":"Bawdf","payer_name":"Cobrador de desarrollo #990.939","payer_email":"test@khipu.com","personal_identifier":"44.444.444-4","bank_account_number":"000000000000444444444","out_of_date_conciliation":false,"transaction_id":"15f836bd-e8a7-4d12-b2f1-56403012b555","authorizer_operation_code":"AUT-20240418-0001","responsible_user_email":"test@khipu.com","payment_method":"simplified_transfer","conciliation_date":"2024-04-18T13:56:54.859Z"}';

    // --- verifySignature ----------------------------------------------------

    public function testKhipuDocumentationExampleVerifies()
    {
        $this->assertTrue(Notification::verifySignature(self::DOC_BODY, self::DOC_HEADER, self::DOC_SECRET));
    }

    public function testTamperedBodyFails()
    {
        $body = str_replace('"amount":"1000.0000"', '"amount":"9000.0000"', self::DOC_BODY);

        $this->assertFalse(Notification::verifySignature($body, self::DOC_HEADER, self::DOC_SECRET));
    }

    public function testReformattedBodyFails()
    {
        // The signature covers the raw bytes, not the JSON value.
        $body = json_encode(json_decode(self::DOC_BODY, true), JSON_UNESCAPED_SLASHES);

        $this->assertEquals(json_decode(self::DOC_BODY, true), json_decode($body, true));

        $this->assertFalse(Notification::verifySignature($body, self::DOC_HEADER, self::DOC_SECRET));
    }

    public function testOtherSecretFails()
    {
        $this->assertFalse(Notification::verifySignature(self::DOC_BODY, self::DOC_HEADER, 'otra'));
    }

    public function testEmptySecretNeverVerifies()
    {
        $header = 't=1,s=' . base64_encode(hash_hmac('sha256', '1.' . self::DOC_BODY, '', true));

        $this->assertFalse(Notification::verifySignature(self::DOC_BODY, $header, ''));
    }

    /**
     * @dataProvider malformedHeaders
     */
    public function testMalformedHeadersFailWithoutErrors($header)
    {
        $this->assertFalse(Notification::verifySignature(self::DOC_BODY, $header, self::DOC_SECRET));
    }

    public function malformedHeaders()
    {
        return array(
            'missing' => array(null),
            'empty' => array(''),
            'array' => array(array('t=1')),
            'no signature' => array('t=1711965600393'),
            'no timestamp' => array('s=rq08YUGSaWa1D0RAwhMnJTRHMNmY4ToCn2Gd6G9LaDg='),
            'junk' => array('garbage,,=,='),
        );
    }

    public function testHeaderWithSpacesAndOtherOrderVerifies()
    {
        $this->assertTrue(Notification::verifySignature(
            self::DOC_BODY,
            's=rq08YUGSaWa1D0RAwhMnJTRHMNmY4ToCn2Gd6G9LaDg=, t=1711965600393',
            self::DOC_SECRET
        ));
    }

    // --- parse --------------------------------------------------------------

    public function testParseRequiresScalarIds()
    {
        $this->assertNotNull(Notification::parse(self::DOC_BODY));
        $this->assertNull(Notification::parse('no json'));
        $this->assertNull(Notification::parse('{"payment_id":"p","receiver_id":1}'));
        $this->assertNull(Notification::parse('{"payment_id":["p"],"receiver_id":1,"transaction_id":"7"}'));
        $this->assertNull(Notification::parse('{"payment_id":"","receiver_id":1,"transaction_id":"7"}'));
    }

    // --- evaluate -----------------------------------------------------------

    private function payment(array $overrides = array())
    {
        return array_merge(array(
            'payment_id' => 'pay123',
            'receiver_id' => 990939,
            'transaction_id' => '42',
            'status' => 'done',
            'status_detail' => 'normal',
            'amount' => '11901.0000',
            'currency' => 'CLP',
            'custom' => PaymentCustom::build(42, '11900.50', 'CLP', '11901', 'CLP', self::SECRET),
        ), $overrides);
    }

    private function expected()
    {
        return array('payment_id' => 'pay123', 'transaction_id' => '42');
    }

    public function testDonePaymentRecordsTheInvoiceAmount()
    {
        $result = Notification::evaluate($this->payment(), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::RECORD, $result['action']);
        $this->assertSame('42', $result['invoice_id']);
        $this->assertSame('pay123', $result['payment_id']);
        $this->assertSame('11900.50', $result['amount']);
    }

    public function testConvertedPaymentRecordsTheInvoiceCurrencyAmount()
    {
        $payment = $this->payment(array('amount' => '9500.0000', 'custom' => PaymentCustom::build(42, '10', 'USD', '9500', 'CLP', self::SECRET)));

        $this->assertSame('10.00', Notification::evaluate($payment, '990939', $this->expected(), self::SECRET)['amount']);
    }

    public function testOtherReceiverIsRejected()
    {
        $result = Notification::evaluate($this->payment(), '111', $this->expected(), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
        $this->assertStringContainsString('receiver_id', $result['reason']);
    }

    public function testPaymentNotMatchingTheNotificationIsRejected()
    {
        $result = Notification::evaluate($this->payment(), '990939', array('payment_id' => 'pay123', 'transaction_id' => '43'), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
    }

    public function testUnconfirmedPaymentIsRetried()
    {
        $result = Notification::evaluate($this->payment(array('status' => 'verifying')), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::RETRY, $result['action']);
    }

    public function testPaymentMarkedPaidByTheMerchantIsIgnored()
    {
        $result = Notification::evaluate($this->payment(array('status_detail' => 'marked-paid-by-receiver')), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::IGNORE, $result['action']);
    }

    /**
     * @dataProvider markedForRefund
     */
    public function testPaymentVoidedOrRefundedBeforeRecordingIsRecordedWithAWarning($detail)
    {
        // Marked for refund is not paid out: a refund the wallet never funds
        // expires. Dropping the payment with a 200 would stop Khipu's retries.
        $result = Notification::evaluate($this->payment(array('status_detail' => $detail)), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::RECORD, $result['action']);
        $this->assertSame('11900.50', $result['amount']);
        $this->assertStringContainsString('ATENCIÓN', $result['reason']);
    }

    public function markedForRefund()
    {
        return array('voided' => array('reversed'), 'fully refunded' => array('fully-refunded'));
    }

    public function testPartiallyRefundedPaymentIsRecordedWithAWarning()
    {
        $payment = $this->payment(array('status_detail' => 'partially-refunded', 'total_refunded' => '5000.0000'));

        $result = Notification::evaluate($payment, '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::RECORD, $result['action']);
        $this->assertSame('11900.50', $result['amount']);
        $this->assertStringContainsString('5000.0000 CLP', $result['reason']);
        $this->assertStringContainsString('Sólo registro', $result['reason']);
    }

    public function testEmptyStatusDetailOfOlderApisAlsoWarns()
    {
        $result = Notification::evaluate($this->payment(array('status_detail' => '')), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::RECORD, $result['action']);
        $this->assertStringContainsString('ATENCIÓN', $result['reason']);
    }

    public function testMissingStatusDetailDoesNotWarn()
    {
        $payment = $this->payment();
        unset($payment['status_detail']);

        $this->assertSame('', Notification::evaluate($payment, '990939', $this->expected(), self::SECRET)['reason']);
    }

    public function testNormalPaymentCarriesNoWarning()
    {
        $this->assertSame('', Notification::evaluate($this->payment(), '990939', $this->expected(), self::SECRET)['reason']);
    }

    public function testCustomForgedWithoutTheSecretIsRejected()
    {
        // Anyone with the API Key can create a payment; only the secret makes its custom ours.
        $forged = PaymentCustom::build(42, '900000.00', 'CLP', '11901', 'CLP', 'guessed-secret');

        $result = Notification::evaluate($this->payment(array('custom' => $forged)), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
    }

    public function testCustomCopiedOntoACheaperChargeIsRejected()
    {
        // A genuine custom read from a real payment, reused on a 1-peso one.
        $result = Notification::evaluate($this->payment(array('amount' => '1.0000')), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
        $this->assertStringContainsString('distinto', $result['reason']);
    }

    public function testCustomForAnotherCurrencyIsRejected()
    {
        $result = Notification::evaluate($this->payment(array('currency' => 'USD')), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
    }

    public function testPaymentWithoutOurCustomIsRejected()
    {
        $result = Notification::evaluate($this->payment(array('custom' => '')), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
    }

    public function testCustomForAnotherInvoiceIsRejected()
    {
        $result = Notification::evaluate($this->payment(array('custom' => PaymentCustom::build(41, '100', 'CLP', '100', 'CLP', self::SECRET))), '990939', $this->expected(), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
    }

    public function testNonNumericTransactionIdIsRejected()
    {
        $payment = $this->payment(array('transaction_id' => '15f836bd'));

        $result = Notification::evaluate($payment, '990939', array('payment_id' => 'pay123'), self::SECRET);

        $this->assertSame(Notification::REJECT, $result['action']);
    }

    public function testLegacyPaymentRecordsWhatKhipuCharged()
    {
        $payment = $this->payment(array('custom' => '', 'notification_token' => 'tok', 'amount' => 11900.0));

        $result = Notification::evaluate($payment, '990939', array('notification_token' => 'tok'), self::SECRET, true);

        $this->assertSame(Notification::RECORD, $result['action']);
        $this->assertSame('11900', $result['amount']);
        $this->assertSame('pay123', $result['payment_id']);
    }
}
