<?php
// Runs inside the dev WHMCS container (see dev/integration-test.sh), against
// the real Khipu API with the account configured in that WHMCS.
chdir('/var/www/html');
require '/var/www/html/init.php';
require_once '/var/www/html/includes/gatewayfunctions.php';
require_once '/var/www/html/includes/invoicefunctions.php';
require_once '/var/www/html/modules/gateways/khipu.php';
require_once __DIR__ . '/support/Whmcs.php';

$params = getGatewayVariables('khipu');
if (empty($params['type']) || !\KhipuWhmcs\Gateway::isConfigured($params)) {
    fwrite(STDERR, "Activate Khipu in the dev WHMCS and fill API Key, ID Cobrador and Llave secreta first.\n");
    exit(1);
}
unset($params);

Whmcs::ensureCurrencies();
