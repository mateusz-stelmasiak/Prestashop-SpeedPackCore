<?php
/**
 * Behaviour: what a shop window reports (views/js/behaviour.js, sent with navigator.sendBeacon).
 *
 * The visit is kept in the shop's own session cookie (spc_bs, spc_bl): no cookie of its own.
 * Answers 204 with no body, whatever happened; a shop window never waits for it.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpeedpackcoreCollectModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $content_only = true;
    public $display_header = false;
    public $display_footer = false;

    public function setMedia()
    {
        return true;
    }

    public function initContent()
    {
    }

    public function postProcess()
    {
        header('Cache-Control: no-store');
        try {
            $this->collect();
        } catch (Throwable $e) {
            // a lost page view is not worth an error page
        }
        http_response_code(204);
        exit;
    }

    private function collect()
    {
        if (!SpcBehaviour::enabled() || SpcAudit::parts() !== null || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 32768);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['m']) || !is_array($data['m'])) {
            return;
        }
        $cookie = $this->context->cookie;
        $part = new SpcBehaviour($this->module, $this->context, 'Behaviour');
        $id = (int) $cookie->__get('spc_bs');
        $last = (int) $cookie->__get('spc_bl');
        $state = SpcBehaviourStore::collect(['id' => $id, 'last' => $last], $data['m'], $part->env(), time());
        if ($state['id'] && ($id !== $state['id'] || $last !== $state['last'])) {
            $cookie->__set('spc_bs', $state['id']);
            $cookie->__set('spc_bl', $state['last']);
            $cookie->write();
        }
        // old visits go now and then, a batch at a time
        if (mt_rand(1, 200) === 1) {
            SpcBehaviourStore::purge(SpcBehaviour::keep(), time(), 500);
        }
    }
}
