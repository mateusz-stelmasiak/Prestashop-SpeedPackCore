<?php
/**
 * SpeedPack Core 1.5.0: Behaviour, a new part. Its tables, its settings (recording off until the
 * shop owner switches it on) and the order hook that marks the visit an order came from.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_5_0($module)
{
    require_once dirname(__FILE__) . '/../classes/SpcBehaviour.php';
    foreach ([SpcBehaviour::K_ENABLED => 0, SpcBehaviour::K_CONSENT => 0, SpcBehaviour::K_CUSTOMER => 0, SpcBehaviour::K_KEEP => 90] as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, $value);
        }
    }

    // the footer credit and the llms.txt section (both off until switched on)
    return SpcBehaviourStore::install() && $module->registerHook('actionValidateOrder')
        && $module->registerHook('displayFooter') && $module->registerHook('displayLlmsTxt');
}
