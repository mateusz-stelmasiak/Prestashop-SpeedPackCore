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

class AsynccartRemoveModuleFrontController extends ModuleFrontController
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

        $count = AsyncCartAnswer::count($cart);

        return [
            'ok' => count($failed) < count($lines),
            'failed' => $failed,
            'count' => $count,
            'label' => AsyncCartAnswer::itemsLabel($this->context, $count),
            'totals' => $count ? AsyncCartAnswer::totals($this->context, $cart) : null,
            // vouchers came or went: the page shows different summary lines, so it re-renders them
            'rules' => count($cart->getCartRules()) !== $rulesBefore,
        ];
    }
}
