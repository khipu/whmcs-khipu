<?php

use KhipuWhmcs\Client;
use KhipuWhmcs\RefundRules;
use KhipuWhmcs\Version;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testAmountPrecisionIsZeroOnlyForClp()
    {
        $this->assertSame(0, Client::amountPrecision('CLP'));
        $this->assertSame(0, Client::amountPrecision('clp'));
        $this->assertSame(2, Client::amountPrecision('USD'));
    }

    public function testFormatAmountRoundsToCurrencyPrecision()
    {
        $this->assertSame('11901', Client::formatAmount('11900.50', 'CLP'));
        $this->assertSame('11900', Client::formatAmount(11900.49, 'CLP'));
        $this->assertSame('10.50', Client::formatAmount('10.5', 'USD'));
        $this->assertSame('1234567.00', Client::formatAmount(1234567, 'USD'));
    }

    public function testCreatePaymentPostsJsonWithApiKeyAndUserAgent()
    {
        $transport = new FakeTransport(array(array('body' => array(
            'payment_id' => 'abc123', 'payment_url' => 'https://khipu.com/payment/info/abc123',
        ))));
        $client = new Client('KEY', $transport);

        $result = $client->createPayment(array('subject' => 'Factura #1', 'amount' => 1000, 'currency' => 'CLP'));

        $this->assertTrue($result['ok']);
        $this->assertSame('abc123', $result['data']['payment_id']);
        $this->assertSame('POST', $transport->requests[0]['method']);
        $this->assertSame('https://payment-api.khipu.com/v3/payments', $transport->requests[0]['url']);
        $this->assertContains('x-api-key: KEY', $transport->requests[0]['headers']);
        $this->assertContains('User-Agent: ' . Version::USER_AGENT, $transport->requests[0]['headers']);
        $this->assertContains('Content-Type: application/json', $transport->requests[0]['headers']);
        $this->assertSame('Factura #1', $transport->sent(0)['subject']);
    }

    public function testA200WithoutRequiredFieldsIsNotOk()
    {
        // What a proxy or WAF answers without ever reaching Khipu.
        $client = new Client('KEY', new FakeTransport(array(array('body' => array('message' => 'ok')))));

        $result = $client->createPayment(array());

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, $result['outcome']);
        $this->assertStringContainsString('payment_id', $result['message']);
    }

    public function testA200WithUnreadableBodyIsUnknown()
    {
        $client = new Client('KEY', new FakeTransport(array(array('body' => '<html>gateway</html>'))));

        $result = $client->getWalletBalance();

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, $result['outcome']);
    }

    public function testValidationErrorIsParsedAndRejected()
    {
        $client = new Client('KEY', new FakeTransport(array(array('status' => 400, 'body' => array(
            'status' => 400,
            'message' => 'Error de validación',
            'errors' => array(array('field' => 'notify_url', 'message' => 'La URL no es válida')),
        )))));

        $result = $client->createPayment(array());

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['http']);
        $this->assertSame(RefundRules::OUTCOME_REJECTED, $result['outcome']);
        $this->assertSame(array(array('field' => 'notify_url', 'message' => 'La URL no es válida')), $result['errors']);
        $this->assertSame('La URL no es válida', $result['message']);
    }

    public function testErrorWithoutFieldErrorsUsesKhipusMessage()
    {
        $client = new Client('KEY', new FakeTransport(array(array('status' => 401, 'body' => array('message' => 'API Key inválida')))));

        $result = $client->getWalletBalance();

        $this->assertSame('API Key inválida (HTTP 401)', $result['message']);
        $this->assertSame(RefundRules::OUTCOME_REJECTED, $result['outcome']);
    }

    public function testTransportErrorIsUnknownAndKeepsTheReason()
    {
        $client = new Client('KEY', new FakeTransport(array(array('status' => 0, 'error' => 'Operation timed out'))));

        $result = $client->refund('pay', RefundRules::TYPE_PARTIAL, '10');

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, $result['outcome']);
        $this->assertSame('Operation timed out', $result['message']);
    }

    public function testPaymentWithoutStatusDetailIsNotOk()
    {
        // status_detail decides whether a payment is recorded; a 200 without it is truncated.
        $client = new Client('KEY', new FakeTransport(array(array('body' => array(
            'payment_id' => 'pay', 'receiver_id' => 1, 'status' => 'done', 'amount' => '1000.0000', 'currency' => 'CLP',
        )))));

        $result = $client->getPayment('pay');

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, $result['outcome']);
        $this->assertStringContainsString('status_detail', $result['message']);
    }

    public function testGetPaymentEncodesTheId()
    {
        $transport = new FakeTransport(array(array('status' => 404, 'body' => '')));
        $client = new Client('KEY', $transport);

        $client->getPayment('../refunds?x=1');

        $this->assertSame('GET', $transport->requests[0]['method']);
        $this->assertSame('https://payment-api.khipu.com/v3/payments/..%2Frefunds%3Fx%3D1', $transport->requests[0]['url']);
        $this->assertNull($transport->requests[0]['body']);
    }

    public function testPartialRefundSendsTheAmount()
    {
        $transport = new FakeTransport(array(array('body' => array(
            'id' => 'r1', 'payment_id' => 'pay', 'refunded_amount' => '50.0000',
            'total_refunded' => '50.0000', 'remaining' => '150.0000', 'currency' => 'CLP',
        ))));
        $client = new Client('KEY', $transport);

        $result = $client->refund('pay', RefundRules::TYPE_PARTIAL, '50');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://payment-api.khipu.com/v3/refunds', $transport->requests[0]['url']);
        $this->assertSame(array('type' => 'partial', 'payment_id' => 'pay', 'amount' => '50'), $transport->sent(0));
    }

    public function testFullRefundOmitsTheAmount()
    {
        $transport = new FakeTransport(array(array('status' => 400, 'body' => '{}')));
        $client = new Client('KEY', $transport);

        $client->refund('pay', RefundRules::TYPE_FULL, '50');

        $this->assertSame(array('type' => 'full', 'payment_id' => 'pay'), $transport->sent(0));
    }

    public function testWalletBalance()
    {
        $transport = new FakeTransport(array(array('body' => array(
            'balance' => '5000.0000', 'currency' => 'CLP', 'add_funds_url' => 'https://khipu.com/dashboard/bills',
        ))));
        $client = new Client('KEY', $transport);

        $result = $client->getWalletBalance();

        $this->assertTrue($result['ok']);
        $this->assertSame('5000.0000', $result['data']['balance']);
        $this->assertSame('https://payment-api.khipu.com/v3/refund-wallet/balance', $transport->requests[0]['url']);
    }
}
