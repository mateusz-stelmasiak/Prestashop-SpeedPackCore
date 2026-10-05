<?php
/**
 * The numbers the cart page shows after a change: product count, the "3 items" label and the
 * summary totals, formatted the way the theme prints them. Shared by the remove and quantity
 * endpoints, which answer with these instead of re-rendering the cart.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AsyncCartAnswer
{
    /** Products in the cart, counted the way the header shows them. */
    public static function count(Cart $cart)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT SUM(quantity) FROM `' . _DB_PREFIX_ . 'cart_product` WHERE id_cart = ' . (int) $cart->id
        );
    }

    /** Whether the cart page shows prices with tax for this customer. */
    public static function withTax(Cart $cart)
    {
        return !Product::getTaxCalculationMethod((int) $cart->id_customer);
    }

    /** the cart page's summary values */
    public static function totals($context, Cart $cart)
    {
        $tax = self::withTax($cart);
        $products = $cart->getOrderTotal($tax, Cart::ONLY_PRODUCTS);
        $discount = $cart->getOrderTotal($tax, Cart::ONLY_DISCOUNTS);
        $shipping = $cart->getOrderTotal($tax, Cart::ONLY_SHIPPING);
        $total = $cart->getOrderTotal($tax, Cart::BOTH);

        return [
            'products' => self::price($context, $products),
            'discount' => $discount > 0 ? '-' . self::price($context, $discount) : '',
            'shipping' => $shipping > 0 ? self::price($context, $shipping) : $context->getTranslator()->trans('Free', [], 'Shop.Theme.Checkout'),
            'total' => self::price($context, $total),
        ];
    }

    /** One line's total, or '' when the line is not in the cart. */
    public static function lineTotal($context, Cart $cart, $idProduct, $ipa, $idCustomization)
    {
        $tax = self::withTax($cart);
        foreach ($cart->getProducts(true) as $row) {
            if ((int) $row['id_product'] === (int) $idProduct
                && (int) $row['id_product_attribute'] === (int) $ipa
                && (int) (isset($row['id_customization']) ? $row['id_customization'] : 0) === (int) $idCustomization) {
                return self::price($context, $tax ? $row['total_wt'] : $row['total']);
            }
        }

        return '';
    }

    /** PrestaShop 1.7.6+ formats prices through the locale */
    public static function price($context, $amount)
    {
        return $context->getCurrentLocale()->formatPrice($amount, $context->currency->iso_code);
    }

    /** "1 item" / "3 items", in the shop's own wording (the theme's js-subtotal label) */
    public static function itemsLabel($context, $count)
    {
        return $count === 1
            ? $context->getTranslator()->trans('1 item', [], 'Shop.Theme.Checkout')
            : $context->getTranslator()->trans('%count% items', ['%count%' => $count], 'Shop.Theme.Checkout');
    }
}
