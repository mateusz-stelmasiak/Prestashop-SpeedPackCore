<?php
/**
 * /acp/carts...: the ACP carts service (classes/SpcAgentAcp.php). JSON in and out, Bearer keys made by
 * the merchant; 404 when switched off (SpeedPack, AI agents).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'speedpackcore/classes/agents/SpcAgentAcp.php';

class SpeedPackCoreAgAcpModuleFrontController extends ModuleFrontController
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
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        if (!SpcAgentSettings::acpCartsReady()) {
            http_response_code(404);
            exit;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? Tools::strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[str_replace('_', '-', Tools::strtolower(substr($k, 5)))] = (string) $v;
            }
        }
        // Apache in front of PHP-FPM drops Authorization; the module's .htaccess block passes it on as an env
        foreach (['REDIRECT_HTTP_AUTHORIZATION', 'HTTP_AUTHORIZATION'] as $k) {
            if (!isset($headers['authorization']) && !empty($_SERVER[$k])) {
                $headers['authorization'] = (string) $_SERVER[$k];
            }
        }
        if (!isset($headers['authorization']) && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (Tools::strtolower($k) === 'authorization') {
                    $headers['authorization'] = (string) $v;
                }
            }
        }
        $id = (string) Tools::getValue('cart', '');
        $op = (string) Tools::getValue('op', '');
        $raw = in_array($method, ['POST', 'PUT'], true) ? (string) file_get_contents('php://input', false, null, 0, 262144) : '';
        list($status, $body, $extra) = SpcAgentAcp::handle($method, $id, $op, $headers, $raw, $this->context);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($extra as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value));
        }
        @ini_set('serialize_precision', '-1');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
