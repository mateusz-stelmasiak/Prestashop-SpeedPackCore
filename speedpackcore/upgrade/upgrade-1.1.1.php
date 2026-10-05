<?php
/**
 * SpeedPack Core 1.1.1: the prefetch worker is served as a static file, so the controller that
 * used to print it is removed from shops upgrading from an earlier version (an uploaded zip
 * adds and replaces files but never deletes them).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_1($module)
{
    $old = _PS_MODULE_DIR_ . $module->name . '/controllers/front/sw.php';
    if (is_file($old)) {
        @unlink($old);
    }

    return true;
}
