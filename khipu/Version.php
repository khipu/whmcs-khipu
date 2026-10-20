<?php

namespace KhipuWhmcs;

/**
 * Module and API version numbers, in one place.
 *
 * The User-Agent of every Khipu call is built from these, so a version bump
 * only has to touch PLUGIN (VersionTest checks the docs stay in sync).
 */
class Version
{
    const PLUGIN = '3.0.0';

    /** Khipu API the module talks to. */
    const API = '3.0';

    const USER_AGENT = 'khipu-api-php-client/' . self::API . '|whmcs-khipu/' . self::PLUGIN;
}
