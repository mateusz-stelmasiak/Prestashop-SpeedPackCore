<?php
/**
 * SpeedPack Core 1.7.0: two new parts, Page cache and Optimize, each off until switched on. Their
 * settings and the page cache's table are made, their hooks registered; PrestaShop's combined CSS
 * and JS are made again.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_7_0($module)
{
    // the parts as the module built them (with its context)
    $pageCache = $module->part('pageCache');
    $optimize = $module->part('optimize');
    if (!$pageCache || !$optimize || !$pageCache->install() || !$optimize->install()) {
        return false;
    }
    Media::clearCache();
    Configuration::updateValue('SPC_ASSETS_VERSION', '1.7.0');

    return true;
}
