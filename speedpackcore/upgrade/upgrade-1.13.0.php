<?php
/**
 * SpeedPack Core 1.13.0: the AI agents tab. Its tables (ACP carts) and hooks (the agent routes,
 * robots.txt written again); nothing is published until "Make my shop agent-ready" is pressed.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_13_0($module)
{
    SpcAgentAcp::install();

    return $module->registerHook('moduleRoutes') && $module->registerHook('actionAdminMetaAfterWriteRobotsFile');
}
