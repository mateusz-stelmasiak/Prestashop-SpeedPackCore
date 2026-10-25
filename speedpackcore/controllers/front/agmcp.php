<?php
/**
 * /mcp: the shop's MCP server (classes/SpcAgentMcp.php), Streamable HTTP with JSON answers.
 * POST a JSON-RPC message (or a batch); GET is not offered (no server-sent stream).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'speedpackcore/classes/agents/SpcAgentMcp.php';

class SpeedPackCoreAgMcpModuleFrontController extends ModuleFrontController
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
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Cache-Control: no-store');
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if (!SpcAgentSettings::on(SpcAgentSettings::K_MCP)) {
            http_response_code(404);
            exit;
        }
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST, OPTIONS');
            exit;
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
        $msg = json_decode($raw, true);
        header('Content-Type: application/json');
        if (!is_array($msg)) {
            http_response_code(400);
            echo json_encode(SpcAgentMcp::error(null, -32700, 'Parse error'));
            exit;
        }
        $batch = isset($msg[0]);
        $answers = [];
        foreach ($batch ? array_slice($msg, 0, 20) : [$msg] as $one) {
            $a = is_array($one) ? SpcAgentMcp::handle($one, $this->context) : SpcAgentMcp::error(null, -32600, 'Invalid Request');
            if ($a !== null) {
                $answers[] = $a;
            }
        }
        if (!$answers) {
            http_response_code(202); // only notifications
            exit;
        }
        echo json_encode($batch ? $answers : $answers[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
