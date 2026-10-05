<?php
/**
 * Lean quantity endpoint for the cart page: sets one line to the quantity the shopper ended on and
 * answers with what the summary shows. Nothing else.
 *
 * The shop's own +/- sends one request per click and rebuilds the whole cart for each answer. Here
 * the page has already shown the new number; quick clicks arrive as one request with the final
 * quantity, and the answer is the line total, the count and the totals.
 *
 * POST p = id_product, a = id_product_attribute, c = id_customization, qty = the wanted quantity,
 * restore = 1 to put back a line that was just removed (Undo)
 * Answers {ok, quantity, line, count, label, totals, rules}
 * | {ok: false, error, quantity} | {fallback: true}
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AsynccartQtyModuleFrontController extends ModuleFrontController
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
            $answer = $this->setQuantity();
        } catch (Throwable $e) {
            $answer = ['fallback' => true];
        }
        echo json_encode($answer);
        exit;
    }

    private function setQuantity()
    {
        if (!$this->isTokenValid()) {
            return ['fallback' => true];
        }
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return ['ok' => false, 'error' => $this->module->l('Your cart has expired – please refresh the page.', 'qty')];
        }
        $idProduct = (int) Tools::getValue('p');
        $ipa = (int) Tools::getValue('a');
        $idCustomization = (int) Tools::getValue('c');
        $wanted = (int) Tools::getValue('qty');
        if ($idProduct <= 0 || $wanted < 1 || $wanted > 100000) {
            return ['fallback' => true];
        }

        $current = AsyncCartAnswer::lineQuantity($cart, $idProduct, $ipa, $idCustomization);
        // Undo after a removal: the line is gone, so it is added back from zero
        // (a customised line cannot be rebuilt from here, so Undo is offered only for plain lines)
        if (!$current && !(Tools::getValue('restore') && !$idCustomization)) {
            return ['fallback' => true];
        }
        $rulesBefore = count($cart->getCartRules());

        $error = '';
        if ($wanted !== $current) {
            $done = $cart->updateQty(
                abs($wanted - $current),
                $idProduct,
                $ipa ?: null,
                $idCustomization ?: false,
                $wanted > $current ? 'up' : 'down',
                0,
                null,
                true,
                false
            );
            if ($done < 0) {
                $attr = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';   // renamed in PrestaShop 8
                $product = new Product($idProduct);
                $min = $ipa ? (int) $attr::getAttributeMinimalQty($ipa) : (int) $product->minimal_quantity;
                $error = sprintf($this->module->l('The minimum quantity for this product is %d.', 'qty'), max(1, $min));
            } elseif (!$done) {
                $error = $this->module->l('There are not that many of this product left.', 'qty');
            }
            // the same clean-up the shop does after a change (vouchers that no longer apply, gifts)
            CartRule::autoRemoveFromCart($this->context);
            CartRule::autoAddToCart($this->context);
        }

        $quantity = AsyncCartAnswer::lineQuantity($cart, $idProduct, $ipa, $idCustomization);
        $count = AsyncCartAnswer::count($cart);

        return [
            'ok' => $error === '',
            'error' => $error,
            'quantity' => $quantity,
            'line' => AsyncCartAnswer::lineTotal($this->context, $cart, $idProduct, $ipa, $idCustomization),
            'count' => $count,
            'label' => AsyncCartAnswer::itemsLabel($this->context, $count),
            'totals' => $count ? AsyncCartAnswer::totals($this->context, $cart) : null,
            'rules' => count($cart->getCartRules()) !== $rulesBefore,
        ];
    }
}
