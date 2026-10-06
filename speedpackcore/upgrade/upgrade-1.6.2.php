<?php
/**
 * SpeedPack Core 1.6.2: the reorder card's and checkout's new stylesheets reach the shop at once
 * (PrestaShop's combined CSS and JS are made again; they are named after the list of files, not
 * their content), and the new cart list endpoint.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_2($module)
{
    Media::clearCache();
    Configuration::updateValue('SPC_ASSETS_VERSION', '1.6.2');

    return true;
}
