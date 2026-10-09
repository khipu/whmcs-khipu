<?php

// Explicit list instead of a Composer autoloader: the module ships no vendor/
// and nothing here should depend on another gateway's autoloader.
foreach (array(
    'Version', 'Http', 'RefundRules', 'PaymentCustom', 'Client', 'LegacyClient',
    'Notification', 'PayLink', 'RefundProcessor', 'Gateway',
) as $khipuWhmcsClass) {
    require_once __DIR__ . '/' . $khipuWhmcsClass . '.php';
}
unset($khipuWhmcsClass);
