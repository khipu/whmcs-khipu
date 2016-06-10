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

    $data = array(
        'payment_subject' => $params['description'],
        'currency' => $params['currency'],
        'amount' => khipu_number_format($params['amount'], $params['currency']),
        'return_url' => $params['systemurl'].'/viewinvoice.php?id='.$params['invoiceid'].'&paymentsuccess=true',
        'cancel_url' => $params['systemurl'].'/viewinvoice.php?id='.$params['invoiceid'].'&paymentsuccess=false',
        'payer_email' => $params['clientdetails']['email'],
        'notify_url' => $params['systemurl'].'/modules/gateways/callback/khipu.php',
        'transaction_id' => $params['invoiceid']
    );

    $form = "<form method='POST' action='". $params['systemurl'].'/modules/gateways/callback/khipu_redirect.php' . "'>";
    foreach ($data as $name => $value) {
        $form .= "<input type='hidden' name='". $name. "' value='" . htmlspecialchars($value) . "'>";
    }
    $form .= "<input type='image' src='https://s3.amazonaws.com/static.khipu.com/buttons/2015/150x50-transparent.png' alt='Paga con tu banco'>";
    $form .= "</form>";

	return $form;
}

function khipu_number_format($number, $currency) {
    if($currency == 'CLP') {
        return number_format($number, 0, '', '');
    } else {
        return number_format($number, 2, '.', '');
    }
}

?>