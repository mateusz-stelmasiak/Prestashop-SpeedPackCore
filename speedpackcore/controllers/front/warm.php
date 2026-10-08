<?php
/**
 * The page cache's warm-up for cron (SpcWarm::cron): the queue of cleared pages, then the
 * catalogue from where the last run stopped, about 25 seconds a call. Only with the key shown on
 * the settings page. Answers JSON.
 *
 *   every 5 minutes: curl -s "https://shop.example/module/speedpackcore/warm?key=…" > /dev/null
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpeedpackcoreWarmModuleFrontController extends ModuleFrontController
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
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (!hash_equals(SpcWarm::token(), (string) Tools::getValue('key'))) {
            http_response_code(403);
            echo json_encode(['error' => 'key']);
            exit;
        }
        if (!SpcWarm::enabled()) {
            echo json_encode(['error' => 'off']);
            exit;
        }
        @set_time_limit(60);
        ignore_user_abort(true);
        echo json_encode(SpcWarm::cron($this->context, 25));
        exit;
    }
}
