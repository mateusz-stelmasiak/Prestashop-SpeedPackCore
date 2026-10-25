<?php
/**
 * Agent API: its documentation as a page (service-doc), written from the OpenAPI description so the
 * two never disagree.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/agents/SpcAgentApi.php';

class SpeedPackCoreAgApidocsModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        SpcAgentApi::guard(SpcAgentSettings::K_OPENAPI);
        $spec = SpcAgentApi::openapi($this->context);
        $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $server = $spec['servers'][0]['url'];
        $rows = '';
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                $params = '';
                foreach (isset($op['parameters']) ? $op['parameters'] : [] as $p) {
                    $params .= '<li><code>' . $e($p['name']) . '</code>' . (isset($p['description']) ? ' – ' . $e($p['description']) : '')
                        . (isset($p['schema']['default']) ? ' (default ' . $e($p['schema']['default']) . ')' : '') . '</li>';
                }
                $rows .= '<section><h2><span>' . strtoupper($e($method)) . '</span> ' . $e($path) . '</h2><p>' . $e($op['summary']) . '</p>'
                    . ($params ? '<ul>' . $params . '</ul>' : '')
                    . '<p>Example: <a href="' . $e($server . $path) . '"><code>' . $e($server . $path) . '</code></a></p></section>';
            }
        }
        $fields = '';
        foreach ($spec['components']['schemas']['Product']['properties'] as $name => $p) {
            $fields .= '<tr><td><code>' . $e($name) . '</code></td><td>' . $e(is_array($p['type']) ? implode(' | ', $p['type']) : $p['type']) . '</td><td>' . $e(isset($p['description']) ? $p['description'] : '') . '</td></tr>';
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($spec['info']['title']) . '</title><meta name="robots" content="noindex">'
            . '<link rel="service-desc" type="application/openapi+json" href="' . $e(SpcAgentApi::url($this->context, 'openapi')) . '">'
            . '<style>body{font:16px/1.55 system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;color:#1d2a30}h1{color:#143848}h2{font-size:1.1rem;margin-top:2rem}h2 span{background:#143848;color:#fff;border-radius:4px;padding:.1rem .4rem;font-size:.8rem}code{background:#f2f4f5;padding:.1rem .3rem;border-radius:3px}table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #e5e5e5;padding:.3rem .5rem;vertical-align:top}</style></head><body>'
            . '<h1>' . $e($spec['info']['title']) . '</h1><p>' . $e($spec['info']['description']) . '</p>'
            . '<p>Version ' . $e($spec['info']['version']) . ' · <a href="' . $e(SpcAgentApi::url($this->context, 'openapi')) . '">OpenAPI description</a> · '
            . '<a href="' . $e(SpcAgentApi::url($this->context, 'status')) . '">Status</a> · <a href="/.well-known/api-catalog">API catalog</a></p>'
            . $rows . '<h2>Product fields</h2><table>' . $fields . '</table></body></html>';
        exit;
    }
}
