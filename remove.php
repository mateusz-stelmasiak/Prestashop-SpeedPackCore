<?php
/**
 * Lean remove-from-cart endpoint: deletes one or more cart lines, cleans up cart rules and answers
 * with the new product count and the cart page's totals, formatted. Nothing else.
 *
 * The shop's own delete presents the whole cart for its answer and then rebuilds the cart page in a
 * second request. Here the page has already taken the line away; this only makes it true and sends
 * back the few numbers the summary shows.
 *
 * POST lines = [{p: id_product, a: id_product_attribute, c: id_customization}, …]
 * Answers {ok, count, label, totals: {products, shipping, discount, total}, rules, failed: [index…]}
 * | {ok: false, error} | {fallback: true}
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpeedpackcoreRemoveModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $content_only = true;
    public $display_header = false;
    public $display_footer = false;

    // nothing is rendered: skip the page's assets and template variables (they present the whole cart)
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
            $answer = $this->remove();
        } catch (Throwable $e) {
            $answer = ['fallback' => true];
        }
        echo json_encode($answer);
        exit;
    }

    private function remove()
    {
        if (!$this->isTokenValid()) {
            return ['fallback' => true];
        }
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return ['ok' => false, 'error' => $this->module->l('Your cart has expired – please refresh the page.', 'remove')];
        }
        $lines = json_decode((string) Tools::getValue('lines'), true);
        if (!is_array($lines) || !$lines || count($lines) > 50) {
            return ['fallback' => true];
        }

        $rulesBefore = count($cart->getCartRules());
        $failed = [];
        foreach (array_values($lines) as $i => $l) {
            $idProduct = isset($l['p']) ? (int) $l['p'] : 0;
            if ($idProduct <= 0) {
                $failed[] = $i;
                continue;
            }
            $ipa = isset($l['a']) ? (int) $l['a'] : 0;
            $idCustomization = isset($l['c']) ? (int) $l['c'] : 0;
            if (!$cart->deleteProduct($idProduct, $ipa, $idCustomization)) {
                $failed[] = $i;
            }
        }
        // the same clean-up the shop does after a delete (vouchers that no longer apply, gifts)
        CartRule::autoRemoveFromCart($this->context);
        CartRule::autoAddToCart($this->context);

        $count = (int) Db::getInstance()->getValue(
            'SELECT SUM(quantity) FROM `' . _DB_PREFIX_ . 'cart_product` WHERE id_cart = ' . (int) $cart->id
        );

        return [
            'ok' => count($failed) < count($lines),
            'failed' => $failed,
            'count' => $count,
            'label' => $this->itemsLabel($count),
            'totals' => $count ? $this->totals($cart) : null,
            // vouchers came or went: the page shows different summary lines, so it re-renders them
            'rules' => count($cart->getCartRules()) !== $rulesBefore,
        ];
    }

    /** the cart page's summary values, formatted like the theme shows them */
    private function totals(Cart $cart)
    {
        $tax = !Product::getTaxCalculationMethod((int) $cart->id_customer);
        $products = $cart->getOrderTotal($tax, Cart::ONLY_PRODUCTS);
        $discount = $cart->getOrderTotal($tax, Cart::ONLY_DISCOUNTS);
        $shipping = $cart->getOrderTotal($tax, Cart::ONLY_SHIPPING);
        $total = $cart->getOrderTotal($tax, Cart::BOTH);

        return [
            'products' => $this->price($products),
            'discount' => $discount > 0 ? '-' . $this->price($discount) : '',
            'shipping' => $shipping > 0 ? $this->price($shipping) : $this->trans('Free', [], 'Shop.Theme.Checkout'),
            'total' => $this->price($total),
        ];
    }

    private function price($amount)
    {
        // PrestaShop 1.7.6+ formats prices through the locale
        return $this->context->getCurrentLocale()->formatPrice($amount, $this->context->currency->iso_code);
    }

    /** "1 item" / "3 items", in the shop's own wording (the theme's js-subtotal label) */
    private function itemsLabel($count)
    {
        return $count === 1
            ? $this->trans('1 item', [], 'Shop.Theme.Checkout')
            : $this->trans('%count% items', ['%count%' => $count], 'Shop.Theme.Checkout');
    }
}
