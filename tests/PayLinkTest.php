<?php

use KhipuWhmcs\PayLink;
use PHPUnit\Framework\TestCase;

class PayLinkTest extends TestCase
{
    const NOW = 1760000000;

    public function testRoundTrip()
    {
        $form = PayLink::encode(42, 'secret', self::NOW);

        $result = PayLink::decode($form['invoice_id'], $form['exp'], $form['sig'], 'secret', self::NOW + 60);

        $this->assertSame(array('ok' => true, 'reason' => '', 'invoice_id' => '42'), $result);
        $this->assertSame((string) (self::NOW + PayLink::TTL), $form['exp']);
    }

    public function testAnotherInvoiceIdIsInvalid()
    {
        $form = PayLink::encode(42, 'secret', self::NOW);

        $this->assertSame(PayLink::INVALID, PayLink::decode('43', $form['exp'], $form['sig'], 'secret', self::NOW)['reason']);
    }

    public function testExtendedExpiryIsInvalid()
    {
        $form = PayLink::encode(42, 'secret', self::NOW);

        $this->assertSame(PayLink::INVALID, PayLink::decode('42', (string) ($form['exp'] + 3600), $form['sig'], 'secret', self::NOW)['reason']);
    }

    public function testOtherSecretIsInvalid()
    {
        $form = PayLink::encode(42, 'secret', self::NOW);

        $this->assertSame(PayLink::INVALID, PayLink::decode('42', $form['exp'], $form['sig'], 'otra', self::NOW)['reason']);
    }

    public function testExpiredLinkStillTellsWhichInvoice()
    {
        $form = PayLink::encode(42, 'secret', self::NOW);

        $result = PayLink::decode('42', $form['exp'], $form['sig'], 'secret', self::NOW + PayLink::TTL + 1);

        $this->assertSame(array('ok' => false, 'reason' => PayLink::EXPIRED, 'invoice_id' => '42'), $result);
    }

    public function testNoSecretNoLink()
    {
        $this->assertNull(PayLink::encode(42, '', self::NOW));
        $this->assertSame(PayLink::INVALID, PayLink::decode('42', '1', 'x', '', self::NOW)['reason']);
    }

    public function testNonNumericInvoiceIsNeverSigned()
    {
        $this->assertNull(PayLink::encode('42 OR 1=1', 'secret', self::NOW));
    }

    /**
     * @dataProvider junk
     */
    public function testJunkInputIsInvalid($invoiceId, $exp, $sig)
    {
        $this->assertSame(PayLink::INVALID, PayLink::decode($invoiceId, $exp, $sig, 'secret', self::NOW)['reason']);
    }

    public function junk()
    {
        return array(
            'nulls' => array(null, null, null),
            'arrays' => array(array('42'), array('1'), array('x')),
            'empty' => array('', '', ''),
            'non numeric' => array('4x', '1', 'abc'),
            'negative expiry' => array('42', '-1', 'abc'),
        );
    }
}
