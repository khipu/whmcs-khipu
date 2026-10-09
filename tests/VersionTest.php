<?php

use KhipuWhmcs\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function testUserAgentCarriesThePluginVersion()
    {
        $this->assertSame('khipu-api-php-client/3.0|whmcs-khipu/' . Version::PLUGIN, Version::USER_AGENT);
    }

    public function testVersionIsSemantic()
    {
        $this->assertRegExp('/^\d+\.\d+\.\d+$/', Version::PLUGIN);
    }
}
