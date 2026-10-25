<?php
/**
 * Agent API: status (see classes/SpcAgentApi.php).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/agents/SpcAgentApi.php';

class SpeedPackCoreAgStatusModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        SpcAgentApi::guard(SpcAgentSettings::K_CATALOG);
        $ok = (bool) Db::getInstance()->getValue('SELECT 1');
        SpcAgentApi::send(['status' => $ok ? 'pass' : 'fail', 'version' => SpcAgentApi::VERSION, 'time' => gmdate('c')], 'application/health+json', $ok ? 200 : 503);
    }
}
