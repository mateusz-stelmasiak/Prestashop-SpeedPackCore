<?php
/**
 * SpeedPack Core 1.2.0: the speed audit and SmartPrefetch 2.0.
 *
 * The Redis class in override/ is written again so it knows the audit key (the audit's own
 * requests can then be answered without the cache), SmartPrefetch gets its prerender setting,
 * and the settings page offers the audit once.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    SpcAudit::key();
    Configuration::updateValue(SpcAudit::K_DONE, 0);
    if (Configuration::get(SpcSmartPrefetch::K_PRERENDER) === false) {
        Configuration::updateValue(SpcSmartPrefetch::K_PRERENDER, 1);
    }
    $parts = $module->parts();
    // a failure leaves the old Redis class in place: the shop keeps its cache, only the audit
    // measures both sides with it
    $parts['cache']->refreshRedisClass();

    return true;
}
