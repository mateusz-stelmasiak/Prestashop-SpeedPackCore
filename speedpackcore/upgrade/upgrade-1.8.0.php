<?php
/**
 * SpeedPack Core 1.8.0: the page cache serves shoppers with a cart too (their cart refreshed on
 * the page) and warms cleared pages again; Optimize can delay third-party scripts (off until
 * switched on). The new settings get their defaults.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_8_0($module)
{
    foreach ([SpcPageCache::K_CARTS => 1, SpcWarm::K_ENABLED => 1, SpcOptimize::K_DELAY => 0, SpcOptimize::K_DELAY_TIMEOUT => 10] as $k => $v) {
        if (Configuration::get($k) === false) {
            Configuration::updateValue($k, $v);
        }
    }
    SpcPageCache::flush();

    return true;
}
