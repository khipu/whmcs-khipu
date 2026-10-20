<?php

use KhipuWhmcs\PaymentCustom;
use PHPUnit\Framework\TestCase;

class PaymentCustomTest extends TestCase
{
    public function testRoundTrip()
    {
        $this->assertSame(
            array('invoice_id' => '42', 'invoice_amount' => 10.5, 'invoice_currency' => 'USD', 'amount' => 9975.0, 'currency' => 'CLP'),
            PaymentCustom::verify(PaymentCustom::build(42, '10.5', 'usd', '9975', 'clp', 'secret'), 'secret')
        );
    }

    public function testOtherSecretDoesNotVerify()
    {
        $this->assertNull(PaymentCustom::verify(PaymentCustom::build(42, '10.5', 'USD', '9975', 'CLP', 'secret'), 'other'));
    }

    public function testEveryFieldIsCoveredBySignature()
    {
        $custom = json_decode(PaymentCustom::build(42, '10.5', 'USD', '9975', 'CLP', 'secret'), true);

        foreach (array('whmcs_invoice_id' => '43', 'invoice_amount' => '99.00', 'invoice_currency' => 'EUR', 'amount' => '1', 'currency' => 'USD') as $field => $value) {
            $tampered = $custom;
            $tampered[$field] = $value;

            $this->assertNull(PaymentCustom::verify(json_encode($tampered), 'secret'), $field);
        }
    }

    public function testReadIgnoresTheSignature()
    {
        $this->assertSame(
            array('invoice_id' => '42', 'invoice_amount' => 10.5, 'invoice_currency' => 'USD', 'amount' => 9975.0, 'currency' => 'CLP'),
            PaymentCustom::read(PaymentCustom::build(42, '10.5', 'USD', '9975', 'CLP', 'rotated-away'))
        );
    }

    public function testNoSecretNeverVerifies()
    {
        $this->assertNull(PaymentCustom::verify(PaymentCustom::build(42, '10.5', 'USD', '9975', 'CLP', ''), ''));
    }

    /**
     * @dataProvider notOurs
     */
    public function testAnythingElseIsNotOurs($custom)
    {
        $this->assertNull(PaymentCustom::verify($custom, 'secret'));
        $this->assertNull(PaymentCustom::read($custom));
    }

    public function notOurs()
    {
        return array(
            'null' => array(null),
            'empty' => array(''),
            'array' => array(array('whmcs_invoice_id' => '1')),
            'free text' => array('Pedido 42'),
            'unsigned 3.0.0 draft' => array('{"whmcs_invoice_id":"42","invoice_amount":"10.00","invoice_currency":"USD"}'),
            'numbers instead of strings' => array('{"whmcs_invoice_id":42,"invoice_amount":10,"invoice_currency":"USD","amount":9500,"currency":"CLP","sig":"x"}'),
        );
    }
}
