<?php

# Required File Includes
include("../../../init.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");
require_once "../lib/lib-khipu/src/Khipu.php";

$gatewaymodule = "khipu"; # Enter your gateway module name here replacing template


$GATEWAY = getGatewayVariables($gatewaymodule);
if (!$GATEWAY["type"]) die("Module Not Activated"); # Checks gateway module is active before accepting callback


$api_version = $_POST['api_version'];


if($api_version == '1.2') {
    $Khipu = new Khipu();
    $Khipu->authenticate($GATEWAY['receiver_id'], $GATEWAY['secret']);
    $Khipu->setAgent('whmcs-2.2;;'.$GATEWAY['systemurl'].';;');
    $service = $Khipu->loadService('VerifyPaymentNotification');
    $service->setDataFromPost();
    if ($_POST['receiver_id'] != $GATEWAY['receiver_id']) {
        logTransaction($GATEWAY["name"],$_POST,"Unsuccessful");
        print 'rejected - Wrong receiver';
        exit(0);
    }

    $verify = $service->verify();
    if($verify['response'] != 'VERIFIED'){
        logTransaction($GATEWAY["name"],$_POST,"Unsuccessful");
        print 'rejected - not verified';
        exit(0);
    }

    $api_version = $_POST['api_version'];
    $receiver_id = $_POST['receiver_id'];
    $notification_id = $_POST['notification_id'];
    $subject = $_POST['subject'];
    $amount = $_POST['amount'];
    $currency = $_POST['currency'];
    $custom = $_POST['custom'];
    $transaction_id = $_POST['transaction_id'];
    $payer_email = $_POST['payer_email'];
    $fee = 0;

    $invoiceid = checkCbInvoiceID($transaction_id,$GATEWAY["name"]); # Checks invoice ID is a valid invoice number or ends processing
    checkCbTransID($notification_id); # Checks transaction number isn't already in the database and ends processing if it does
    addInvoicePayment($invoiceid,$notification_id,$amount,$fee,$gatewaymodule); # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    logTransaction($GATEWAY["name"],$_POST,"Successful"); # Save to Gateway Log: name, data array, status
    print "accepted";
} else if($api_version == '1.3') {
    $Khipu = new Khipu();
    $Khipu->authenticate($GATEWAY['receiver_id'], $GATEWAY['secret']);
    $Khipu->setAgent('whmcs-2.2;;'.$GATEWAY['systemurl'].';;');
    $service = $Khipu->loadService('GetPaymentNotification');
    $service->setDataFromPost();
    $response = json_decode($service->consult());
    if ($response->receiver_id != $GATEWAY['receiver_id']) {
        logTransaction($GATEWAY["name"],$_POST,"Unsuccessful");
        print 'rejected - Wrong receiver';
        exit(0);
    }

    $invoiceid = checkCbInvoiceID($response->transaction_id,$GATEWAY["name"]); # Checks invoice ID is a valid invoice number or ends processing
    checkCbTransID($response->notification_token); # Checks transaction number isn't already in the database and ends processing if it does
    addInvoicePayment($invoiceid,$response->notification_token,$response->amount,0,$gatewaymodule); # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    logTransaction($GATEWAY["name"],$_POST,"Successful"); # Save to Gateway Log: name, data array, status
    print "accepted";

} else {
    logTransaction($GATEWAY["name"],$_POST,"Unsuccessful");
    print 'rejected - invalid api version';
    exit(0);
}
?>
