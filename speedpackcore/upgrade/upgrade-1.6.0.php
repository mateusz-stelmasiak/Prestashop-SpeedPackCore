<?php
/**
 * SpeedPack Core 1.6.0: Core Web Vitals in Behaviour (new columns), and Reorder, a new part (its
 * settings, off until switched on, and its three display hooks).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_0($module)
{
    require_once dirname(__FILE__) . '/../classes/SpcBehaviour.php';
    require_once dirname(__FILE__) . '/../classes/SpcReorder.php';
    foreach ([SpcReorder::K_ENABLED => 0, SpcReorder::K_HOME => 1, SpcReorder::K_CART => 1, SpcReorder::K_ACCOUNT => 1, SpcReorder::K_PAYMENT => 1] as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, $value);
        }
    }

    return SpcBehaviourStore::install()
        && $module->registerHook('displayHome') && $module->registerHook('displayShoppingCartFooter') && $module->registerHook('displayCustomerAccount');
}
