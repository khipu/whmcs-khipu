<?php

function khipu_config() {
    $configarray = array(
     "FriendlyName" => array("Type" => "System", "Value"=>"khipu"),
     "receiver_id" => array("FriendlyName" => "ID Cobrador", "Type" => "text", "Size" => "20", ),
     "secret" => array("FriendlyName" => "Llave secreta", "Type" => "text", "Size" => "20", ),
    );
	return $configarray;
}

function khipu_link($params) {
    require_once "./lib/lib-khipu/src/Khipu.php";

    $Khipu = new Khipu();
    $Khipu->authenticate($params['receiver_id'], $params['secret']);
    $Khipu->setAgent('whmcs-2.2;;'.$params['systemurl'].';;');

    $khipu_service = $Khipu->loadService('CreatePaymentPage');

    $data = array(
        'subject' => $params['description'],
        'body' => '',
        'amount' => intval($params['amount']),
        'return_url' => $params['systemurl'].'/viewinvoice.php?id='.$params['invoiceid'].'&paymentsuccess=true',
        'cancel_url' => $params['systemurl'].'/viewinvoice.php?id='.$params['invoiceid'].'&paymentsuccess=false',
        'payer_email' => $params['clientdetails']['email'],
        'picture_url' => '',
        'custom' => '',
        'notify_url' => $params['systemurl'].'/modules/gateways/callback/khipu.php',
        'transaction_id' => $params['invoiceid']
    );

    foreach ($data as $name => $value) {
        $khipu_service->setParameter($name, $value);
    }

	return $khipu_service->renderForm();
}

?>
