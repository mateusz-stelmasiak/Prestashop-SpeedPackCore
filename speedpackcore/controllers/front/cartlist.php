<?php
/**
 * The cart's products for the top of the checkout (views/js/checkout-summary.js asks again after
 * the cart changed on the page). Answers JSON.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpeedpackcoreCartlistModuleFrontController extends ModuleFrontController
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
        try {
            $answer = SpcReorder::cartList($this->context);
        } catch (Throwable $e) {
            $answer = ['items' => [], 'count' => 0, 'countText' => ''];
        }
        echo json_encode($answer);
        exit;
    }
}
