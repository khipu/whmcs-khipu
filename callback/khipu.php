<?php

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';


$gatewayParams = getGatewayVariables('khipu');

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}


if($_POST['api_version'] == '1.3') {
    $configuration = new Khipu\Configuration();
    $configuration->setSecret($gatewayParams['secret']);
    $configuration->setReceiverId($gatewayParams['receiver_id']);
    $configuration->setPlatform('whmcs-khipu', '2.6');

    $client = new Khipu\ApiClient($configuration);
    $payments = new Khipu\Client\PaymentsApi($client);


    $paymentsResponse =  $payments->paymentsGet($_POST['notification_token']);

    if ($paymentsResponse->getReceiverId() != $gatewayParams['receiver_id']) {
        logTransaction($gatewayParams["name"],$_POST,"Unsuccessful");
        print 'rejected - Wrong receiver';
        exit(0);
    }

    $invoiceid = checkCbInvoiceID($paymentsResponse->getTransactionId(),$gatewayParams["name"]); # Checks invoice ID is a valid invoice number or ends processing
    checkCbTransID($paymentsResponse->getNotificationToken()); # Checks transaction number isn't already in the database and ends processing if it does
    addInvoicePayment($invoiceid,$paymentsResponse->getNotificationToken(),$paymentsResponse->getAmount(),0,$gatewaymodule); # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    logTransaction($gatewayParams["name"],$_POST,"Successful"); # Save to Gateway Log: name, data array, status
    print "accepted";

} else {
    logTransaction($gatewayParams["name"],$_POST,"Unsuccessful");
    print 'rejected - invalid api version';
    exit(0);
}
?>