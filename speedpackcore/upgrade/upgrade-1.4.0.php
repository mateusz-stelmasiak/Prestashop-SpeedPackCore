<?php
/**
 * SpeedPack Core 1.4.0: the speed audit applies its configuration at the very start of a shop
 * request (actionDispatcherBefore), so that hook is registered.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0($module)
{
    return $module->registerHook('actionDispatcherBefore');
}
