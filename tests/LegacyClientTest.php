<?php

use KhipuWhmcs\LegacyClient;
use KhipuWhmcs\RefundRules;
use PHPUnit\Framework\TestCase;

class LegacyClientTest extends TestCase
{
    public function testSignsTheRequestLikeTheV2Client()
    {
        $transport = new FakeTransport(array(array('body' => array(
            'payment_id' => 'pay', 'receiver_id' => 1234, 'status' => 'done', 'status_detail' => 'normal', 'amount' => 1000.0,
            'currency' => 'CLP', 'notification_token' => 'to/k en', 'transaction_id' => '42',
        ))));
        $client = new LegacyClient('1234', 'secret', $transport);

        $result = $client->getPaymentByToken('to/k en');

        $expectedHash = hash_hmac(
            'sha256',
            'GET&https%3A%2F%2Fkhipu.com%2Fapi%2F2.0%2Fpayments&notification_token=to%2Fk%20en',
            'secret'
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('pay', $result['data']['payment_id']);
        $this->assertSame('GET', $transport->requests[0]['method']);
        $this->assertSame('https://khipu.com/api/2.0/payments?notification_token=to%2Fk%20en', $transport->requests[0]['url']);
        $this->assertContains('Authorization: 1234:' . $expectedHash, $transport->requests[0]['headers']);
    }

    public function testErrorIsReported()
    {
        $client = new LegacyClient('1234', 'secret', new FakeTransport(array(array('status' => 403, 'body' => '{}'))));

        $result = $client->getPaymentByToken('tok');

        $this->assertFalse($result['ok']);
        $this->assertSame(RefundRules::OUTCOME_REJECTED, $result['outcome']);
    }

    public function testTransportErrorIsUnknown()
    {
        $client = new LegacyClient('1234', 'secret', new FakeTransport(array(array('status' => 0, 'error' => 'timeout'))));

        $result = $client->getPaymentByToken('tok');

        $this->assertSame(RefundRules::OUTCOME_UNKNOWN, $result['outcome']);
        $this->assertSame('timeout', $result['message']);
    }
}
