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
$configuration->setPlatform('whmcs-khipu', '2.6');

$client = new Khipu\ApiClient($configuration);
$payments = new Khipu\Client\PaymentsApi($client);

try {
    $opts = array(
        "transaction_id" => $_POST['transaction_id'],
        "return_url" => $_POST['return_url'],
        "cancel_url" => $_POST['cancel_url'],
        "notify_url" => $_POST['notify_url'],
        "payer_email" => $_POST['payer_email'],
        "notify_api_version" => "1.3",

    );
    $createPaymentResponse = $payments->paymentsPost(
        $_POST['payment_subject']
        , $_POST['currency']
        , $_POST['amount']
        , $opts);
    
} catch (\Khipu\ApiException $e) {
    echo "<html><body>";
    echo "<h1>Error " . $e->getCode() . ": " . $e->getMessage() . "</h1>";
    $error = $e->getResponseObject();
    if (method_exists($error, "getErrors")) {
        echo "<ul>";
        foreach ($error->getErrors() as $errorItem) {
            echo "<li><strong>" . $errorItem->getField() . "</strong>: " . $errorItem->getMessage() . "</li>";
        }
        echo "</ul>";
        return;
    }
    echo "</body></html>";
    return;
}

header('Location: ' . $createPaymentResponse->getPaymentUrl());

?>