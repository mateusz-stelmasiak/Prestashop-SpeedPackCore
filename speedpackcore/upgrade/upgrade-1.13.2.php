<?php
/**
 * SpeedPack Core 1.13.2: the back office listener that gives Symfony pages the visitor's own
 * address behind Cloudflare (config/admin/services.yml) takes effect once Symfony's cache is
 * built again.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_13_2($module)
{
    Tools::clearSf2Cache();

    return true;
}
