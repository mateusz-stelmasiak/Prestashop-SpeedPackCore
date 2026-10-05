<?php
/**
 * Serves the service worker.
 *
 * A worker's scope is capped by the directory it is served from, so a file
 * sitting in modules/speedpackcore/views/js could only ever control that
 * folder. Serving it through a controller lets us send
 * Service-Worker-Allowed: / alongside it, which is what permits the
 * registration to claim the whole shop.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpeedpackcoreSwModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = true;

    public function initContent()
    {
        $file = null;

        foreach (['sw.min.js', 'sw.js'] as $candidate) {
            $path = _PS_MODULE_DIR_ . 'speedpackcore/views/js/' . $candidate;
            if (is_file($path) && is_readable($path)) {
                $file = $path;
                break;
            }
        }

        if (!$file) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');

        echo file_get_contents($file);
        exit;
    }
}
