<?php
/**
 * Reorder: "Order the same as last time" (classes/SpcReorder.php). A signed-in customer's last
 * order into the cart, with its addresses and carrier, then on to the checkout (at payment when
 * everything carried over), with a note of anything left out.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpeedpackcoreReorderModuleFrontController extends ModuleFrontController
{
    public $auth = true;
    public $guestAllowed = false;

    public function postProcess()
    {
        $context = $this->context;
        $back = $context->link->getPageLink('index', true);
        if (!SpcReorder::enabled() || $_SERVER['REQUEST_METHOD'] !== 'POST' || Tools::getValue('token') !== Tools::getToken(false)) {
            Tools::redirect($back);

            return;
        }
        $order = SpcReorder::lastOrder((int) $context->customer->id, (int) $context->shop->id);
        if (!$order) {
            $this->warning[] = $this->module->l('There is no earlier order to repeat.', 'reorder');
            $this->redirectWithNotifications($back);

            return;
        }
        $done = SpcReorder::fill($context, $order, (bool) Configuration::get(SpcReorder::K_PAYMENT));
        if (!$done['added']) {
            $this->warning[] = $this->module->l('None of the products of your last order can be ordered now.', 'reorder');
            $this->redirectWithNotifications($context->link->getPageLink('cart', true, null, ['action' => 'show']));

            return;
        }
        $this->success[] = sprintf($this->module->l('Your last order is in the cart: %d products.', 'reorder'), count($done['added']));
        if ($done['skipped']) {
            $this->warning[] = sprintf($this->module->l('Not available now, so left out: %s.', 'reorder'), implode(', ', $done['skipped']));
        }
        $this->redirectWithNotifications($context->link->getPageLink('order', true));
    }
}
