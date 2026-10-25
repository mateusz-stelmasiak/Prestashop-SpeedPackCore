<?php
/**
 * Agent API: products (see classes/SpcAgentApi.php).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/agents/SpcAgentApi.php';

class SpeedPackCoreAgProductsModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        SpcAgentApi::guard(SpcAgentSettings::K_CATALOG);
        $lang = (int) $this->context->language->id;
        SpcAgentApi::send(SpcAgentApi::products($this->context, $lang, (int) Tools::getValue('page', 1), (int) Tools::getValue('limit', 50), trim((string) Tools::getValue('q'))));
    }
}
