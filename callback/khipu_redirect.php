<?php

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
$gatewayParams = getGatewayVariables('khipu');


// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$configuration = new Khipu\Configuration();
$configuration->setSecret($gatewayParams['secret']);
$configuration->setReceiverId($gatewayParams['receiver_id']);
$configuration->setPlatform('whmcs-khipu', '2.4');

$client = new Khipu\ApiClient($configuration);
$payments = new Khipu\Client\PaymentsApi($client);


try {
    $createPaymentResponse = $payments->paymentsPost(
        $_POST['subject']
        , $_POST['currency']
        , $_POST['amount']
        , $_POST['transaction_id']
        , null
        , null
        , null
        , $_POST['return_url']
        , $_POST['cancel_url']
        , null
        , $_POST['notify_url']
        , $_POST['api_version']
        , null
        , null
        , null
        , $_POST['payer_email']
        , null
        , null
        , null
        , null
    );
} catch(\Khipu\ApiException $e) {
    //$this->khipu_error($e->getResponseObject());
    echo print_r($e->getResponseObject(), TRUE);
}

header('Location: '.$createPaymentResponse->getPaymentUrl());

?>