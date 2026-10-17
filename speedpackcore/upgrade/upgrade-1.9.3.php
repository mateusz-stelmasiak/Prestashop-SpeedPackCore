<?php
/**
 * SpeedPack Core 1.9.3: WebP and AVIF copies were left with PHP's own umask, 0600 on many hosts, so
 * the web server answered 403 for them. Every copy already made is made readable like its picture.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_9_3($module)
{
    @set_time_limit(300);
    SpcImages::repairAll();

    return true;
}
