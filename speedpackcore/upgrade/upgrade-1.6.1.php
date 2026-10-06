<?php
/**
 * SpeedPack Core 1.6.1: the shopper's path on orders and carts (visits remember their cart; the
 * order and back-office header hooks), and summaries of finished checkout steps (on by default).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_1($module)
{
    require_once dirname(__FILE__) . '/../classes/SpcBehaviour.php';
    require_once dirname(__FILE__) . '/../classes/SpcReorder.php';
    foreach ([SpcBehaviour::K_PATHS => 1, SpcReorder::K_SUMMARY => 1] as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, $value);
        }
    }

    return SpcBehaviourStore::install()
        && $module->registerHook('displayAdminOrderMain') && $module->registerHook('displayAdminOrder') && $module->registerHook('displayBackOfficeHeader');
}
