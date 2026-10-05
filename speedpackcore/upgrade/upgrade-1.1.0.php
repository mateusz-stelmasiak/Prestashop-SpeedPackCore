<?php
/**
 * SpeedPack Core 1.1.0: the Cache section and instant quantity changes in the cart.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    Configuration::updateValue('SPC_IC_QTY', 1);
    foreach ($module->parts() as $part) {
        if ($part instanceof SpcCache) {
            $part->install();
        }
    }

    return $module->registerHooks();
}
