<?php
/**
 * SpeedPack Core 1.6.3: the checkout script and stylesheet changed (a finished step opens from a
 * click anywhere on it), so PrestaShop's combined CSS and JS are made again.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_3($module)
{
    Media::clearCache();
    Configuration::updateValue('SPC_ASSETS_VERSION', '1.6.3');

    return true;
}
