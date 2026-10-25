<?php
/**
 * Agent API: openapi (see classes/SpcAgentApi.php).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/agents/SpcAgentApi.php';

class SpeedPackCoreAgOpenapiModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        SpcAgentApi::guard(SpcAgentSettings::K_OPENAPI);
        SpcAgentApi::send(SpcAgentApi::openapi($this->context), 'application/openapi+json');
    }
}
