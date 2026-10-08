<?php
/**
 * SpeedPack Core 1.9.0: picture sizes and fonts without waiting (on with Optimize), an optional
 * CDN address, and Cloudflare purged with the page cache. The kept pages are made again.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_9_0($module)
{
    foreach ([SpcOptimize::K_DIMS => 1, SpcOptimize::K_FONTS => 1] as $k => $v) {
        if (Configuration::get($k) === false) {
            Configuration::updateValue($k, $v);
        }
    }
    SpcPageCache::flush();

    return true;
}
