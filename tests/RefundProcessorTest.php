<?php

use KhipuWhmcs\Client;
use KhipuWhmcs\PaymentCustom;
use KhipuWhmcs\RefundProcessor;
use PHPUnit\Framework\TestCase;

class RefundProcessorTest extends TestCase
{
    private function paymentBody(array $overrides = array())
    {
        return array_merge(array(
            'payment_id' => 'pay123',
            'receiver_id' => 1234,
            'transaction_id' => '42',
            'status' => 'done',
            'status_detail' => 'normal',
            'amount' => '20000.0000',
            'currency' => 'CLP',
            'custom' => PaymentCustom::build(42, '20000.00', 'CLP', '20000', 'CLP', 'test-secret'),
        ), $overrides);
    }

    private function refundBody(array $overrides = array())
    {
        return array_merge(array(
            'id' => 'r-1', 'payment_id' => 'pay123', 'refunded_amount' => '5000.0000',
            'total_refunded' => '5000.0000', 'remaining' => '15000.0000', 'currency' => 'CLP',
        ), $overrides);
    }

    private function refundWith(FakeTransport $transport, $amount = '5000.00', $currency = 'CLP', $paymentId = 'pay123', $alreadyRefunded = 0)
    {
        $processor = new RefundProcessor(new Client('KEY', $transport));

        return $processor->refund($paymentId, $amount, $currency, $alreadyRefunded);
    }

    public function testEarlierRefundsFeedTheRounding()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody(array('amount' => '11901.0000', 'custom' => PaymentCustom::build(42, '11900.50', 'CLP', '11901', 'CLP', 'test-secret')))),
            array('body' => $this->refundBody()),
        ));

        $this->refundWith($transport, '5950.25', 'CLP', 'pay123', '5950.25');

        $this->assertSame('5950', $transport->sent(1)['amount']);
    }

    public function testAcceptedRefundReturnsTheRefundId()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('body' => $this->refundBody()),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('success', $result['status']);
        $this->assertSame('r-1', $result['transid']);
        $this->assertSame(array('type' => 'partial', 'payment_id' => 'pay123', 'amount' => '5000'), $transport->sent(1));
        $this->assertSame('15000.0000', $result['rawdata']['restante_por_reversar']);
    }

    public function testQueuedRefundSurfacesTheWalletWarning()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('body' => $this->refundBody(array('message' => 'Saldo insuficiente en la billetera de devoluciones.'))),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Saldo insuficiente en la billetera de devoluciones.', $result['rawdata']['aviso_khipu']);
        $this->assertStringContainsString('en proceso', $result['rawdata']['resultado']);
    }

    public function testConvertedInvoiceRefundsInTheChargedCurrency()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody(array('amount' => '9500.0000', 'custom' => PaymentCustom::build(42, '10.00', 'USD', '9500', 'CLP', 'test-secret')))),
            array('body' => $this->refundBody()),
        ));

        $this->refundWith($transport, '10.00', 'USD');

        $this->assertSame('9500', $transport->sent(1)['amount']);
    }

    public function testMissingTransactionIdNeverCallsKhipu()
    {
        $transport = new FakeTransport(array());

        $result = $this->refundWith($transport, '10', 'CLP', '  ');

        $this->assertSame('declined', $result['status']);
        $this->assertCount(0, $transport->requests);
    }

    public function testUnknownPaymentIsDeclinedWithoutRefunding()
    {
        // A 2.x transaction id is a notification token, not a payment_id.
        $transport = new FakeTransport(array(array('status' => 404, 'body' => '{"status":404,"message":"No encontrado"}')));

        $result = $this->refundWith($transport, '10', 'CLP', 'legacy-token');

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('2.x', $result['rawdata']['resultado']);
        $this->assertCount(1, $transport->requests);
    }

    /**
     * @dataProvider badCredentials
     */
    public function testRejectedCredentialsAreNotBlamedOnThePayment($status)
    {
        $transport = new FakeTransport(array(array('status' => $status, 'body' => '')));

        $result = $this->refundWith($transport);

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('API Key', $result['rawdata']['resultado']);
        $this->assertStringNotContainsString('2.x', $result['rawdata']['resultado']);
    }

    public function badCredentials()
    {
        return array('401' => array(401), '403' => array(403));
    }

    public function testOtherLookupRejectionShowsKhipusReason()
    {
        $transport = new FakeTransport(array(array('status' => 400, 'body' => array('errors' => array(array('field' => 'id', 'message' => 'Identificador inválido.'))))));

        $result = $this->refundWith($transport);

        $this->assertSame('Khipu rechazó la consulta del pago: Identificador inválido. No se devolvió dinero.', $result['rawdata']['resultado']);
    }

    public function testUnreachableKhipuBeforeRefundingIsSafeToRetry()
    {
        $transport = new FakeTransport(array(array('status' => 0, 'error' => 'timeout')));

        $result = $this->refundWith($transport);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('puedes reintentar', $result['rawdata']['resultado']);
        $this->assertCount(1, $transport->requests);
    }

    public function testCurrencyMismatchNeverRefunds()
    {
        $transport = new FakeTransport(array(array('body' => $this->paymentBody())));

        $result = $this->refundWith($transport, '10.00', 'EUR');

        $this->assertSame('declined', $result['status']);
        $this->assertCount(1, $transport->requests);
    }

    public function testRejectedRefundIsDeclinedWithKhipusReason()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 400, 'body' => array('errors' => array(array('field' => 'amount', 'message' => 'El monto supera el saldo.')))),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('declined', $result['status']);
        $this->assertSame('Khipu rechazó la reversa: El monto supera el saldo. No se devolvió dinero.', $result['rawdata']['resultado']);
    }

    public function testDisabledWalletIsExplained()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 400, 'body' => array('errors' => array(array('field' => 'receiver_id', 'message' => 'La cuenta de cobro con ID 1234 no está habilitada para hacer devoluciones.')))),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('soporte@khipu.com', $result['rawdata']['resultado']);
    }

    public function testNotRefundableIsExplainedFromTheStatusDetail()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody(array('status_detail' => 'fully-refunded'))),
            array('status' => 400, 'body' => array('errors' => array(array('field' => 'payment_id', 'message' => 'El pago no es reembolsable')))),
            array('body' => array('balance' => '1000.0000', 'currency' => 'CLP', 'add_funds_url' => 'https://khipu.com/dashboard/bills')),
        ));

        $result = $this->refundWith($transport);

        $this->assertStringContainsString('reversado por completo', $result['rawdata']['resultado']);
        $this->assertSame('https://payment-api.khipu.com/v3/refund-wallet/balance', $transport->requests[2]['url']);
    }

    public function testNotRefundableOnADisabledWalletBlamesTheWallet()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 400, 'body' => array('errors' => array(array('field' => 'payment_id', 'message' => 'El pago con ID pay123 no es reembolsable')))),
            array('status' => 400, 'body' => array('errors' => array(array('field' => 'receiver_id', 'message' => 'La cuenta de cobro con ID 1234 no está habilitada para hacer reversas.')))),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('billetera de reversas no está habilitada', $result['rawdata']['resultado']);
        $this->assertStringNotContainsString('180 días', $result['rawdata']['resultado']);
    }

    public function testNotRefundableSurvivesAnUnreachableBalance()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 400, 'body' => array('errors' => array(array('field' => 'payment_id', 'message' => 'El pago no es reembolsable')))),
            array('status' => 0, 'error' => 'timeout'),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('declined', $result['status']);
        $this->assertStringContainsString('180 días', $result['rawdata']['resultado']);
    }

    public function testServerErrorThatRefundedAnywayIsConfirmed()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 500, 'body' => '{"message":"Internal error"}'),
            array('body' => $this->paymentBody(array(
                'status_detail' => 'partially-refunded',
                'refunds' => array(array('id' => 'r-9', 'status' => 'pending', 'amount' => '5000.0000', 'currency' => 'CLP')),
            ))),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('success', $result['status']);
        $this->assertSame('r-9', $result['transid']);
    }

    public function testTimeoutWithNothingVisibleYetIsNotCalledSafeToRetry()
    {
        // The timed-out request may still commit on Khipu's side.
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 0, 'error' => 'Operation timed out'),
            array('body' => $this->paymentBody()),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('en unos minutos', $result['rawdata']['resultado']);
        $this->assertStringNotContainsString('Puedes reintentar', $result['rawdata']['resultado']);
    }

    public function testAmbiguousChangeWarnsNotToRetry()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 502, 'body' => ''),
            array('body' => $this->paymentBody(array('status_detail' => 'partially-refunded'))),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('NO reintentes', $result['rawdata']['resultado']);
    }

    public function testUnverifiableOutcomeWarnsNotToRetry()
    {
        $transport = new FakeTransport(array(
            array('body' => $this->paymentBody()),
            array('status' => 0, 'error' => 'timeout'),
            array('status' => 0, 'error' => 'timeout'),
        ));

        $result = $this->refundWith($transport);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('RESULTADO DESCONOCIDO', $result['rawdata']['resultado']);
        $this->assertStringContainsString('soporte@khipu.com', $result['rawdata']['resultado']);
    }
}
