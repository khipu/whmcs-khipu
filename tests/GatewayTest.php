<?php

use KhipuWhmcs\Gateway;
use PHPUnit\Framework\TestCase;

class GatewayTest extends TestCase
{
    public function testSystemUrlEndsInExactlyOneSlash()
    {
        // WHMCS 8 stores it with the trailing slash; 2.x appended another one.
        $this->assertSame('https://billing.example.com/', Gateway::systemUrl(array('systemurl' => 'https://billing.example.com/')));
        $this->assertSame('https://billing.example.com/', Gateway::systemUrl(array('systemurl' => 'https://billing.example.com')));
    }

    public function testInvoiceUrl()
    {
        $params = array('systemurl' => 'https://billing.example.com/');

        $this->assertSame('https://billing.example.com/viewinvoice.php?id=42', Gateway::invoiceUrl($params, '42'));
        $this->assertSame('https://billing.example.com/viewinvoice.php?id=42&paymentfailed=true', Gateway::invoiceUrl($params, 42, 'paymentfailed=true'));
        $this->assertSame('https://billing.example.com/viewinvoice.php?id=0', Gateway::invoiceUrl($params, '<script>'));
    }

    public function testIsConfiguredNeedsAllThreeCredentials()
    {
        $params = array('api_key' => 'k', 'receiver_id' => '1', 'secret' => 's');

        $this->assertTrue(Gateway::isConfigured($params));
        $this->assertFalse(Gateway::isConfigured(array_merge($params, array('api_key' => ' '))));
        $this->assertFalse(Gateway::isConfigured(array('receiver_id' => '1', 'secret' => 's')));
    }

    public function testParamOnlyKeepsScalars()
    {
        $this->assertSame('abc', Gateway::param(' abc '));
        $this->assertSame('', Gateway::param(array('abc')));
        $this->assertSame('', Gateway::param(null));
        $this->assertSame('12', Gateway::param(12));
    }

    public function testHtmlEscapesQuotes()
    {
        $this->assertSame('&quot;&gt;&lt;x&#039;', Gateway::html('"><x\''));
    }

    public function testTruncateIsMultibyteSafe()
    {
        $this->assertSame('Fac', Gateway::truncate('Factura', 3));
        $this->assertSame('ñá', Gateway::truncate('ñáé', 2));
    }
}
