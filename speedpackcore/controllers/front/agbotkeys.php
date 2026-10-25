<?php
/**
 * /.well-known/http-message-signatures-directory: the public key the shop's bots sign with, in
 * a signed answer (classes/SpcBotAuth.php). 404 when Web Bot Auth is off.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'speedpackcore/classes/agents/SpcBotAuth.php';

class SpeedPackCoreAgBotkeysModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!SpcBotAuth::ready()) {
            http_response_code(404);
            header('Cache-Control: no-store');
            exit;
        }
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : Tools::getShopDomainSsl();
        list($body, $headers) = SpcBotAuth::directoryResponse(preg_replace('/:443$/', '', $host));
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        header('Access-Control-Allow-Origin: *');
        echo $body;
        exit;
    }
}
