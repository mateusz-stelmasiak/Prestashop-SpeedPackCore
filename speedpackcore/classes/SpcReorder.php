<?php
/**
 * SpeedPack Core - Reorder: the last order again, in one tap.
 *
 * A signed-in shopper who has ordered before sees "Order the same as last time" on the home page,
 * in an empty cart and in their account. One tap (controllers/front/reorder.php):
 *   1. puts the products of the last order in the cart (what is no longer sold or in stock is
 *      left out, and the shopper is told),
 *   2. uses the same delivery and invoice addresses, and the same carrier when it still delivers
 *      there,
 *   3. opens the checkout at the payment step: the personal details, address and delivery steps
 *      are saved as done, the way PrestaShop's checkout saves them itself (with the cart's
 *      checksum; when anything does not match, the checkout simply starts at the address step).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcReorder extends SpcFeature
{
    public $id = 'reorder';

    public const K_ENABLED = 'SPC_RO_ENABLED';
    public const K_HOME = 'SPC_RO_HOME';
    public const K_CART = 'SPC_RO_CART';
    public const K_ACCOUNT = 'SPC_RO_ACCOUNT';
    public const K_PAYMENT = 'SPC_RO_PAYMENT';

    /** on every checkout: what each finished step holds, next to its title */
    public const K_SUMMARY = 'SPC_RO_SUMMARY';

    /** product lines shown on the card */
    public const SHOWN = 3;

    public function install()
    {
        // off until the shop owner switches it on: it adds a card to the shop's pages
        return Configuration::updateValue(self::K_ENABLED, 0)
            && Configuration::updateValue(self::K_HOME, 1)
            && Configuration::updateValue(self::K_CART, 1)
            && Configuration::updateValue(self::K_ACCOUNT, 1)
            && Configuration::updateValue(self::K_PAYMENT, 1)
            && Configuration::updateValue(self::K_SUMMARY, 1)
            && $this->registerHooks();
    }

    public function registerHooks()
    {
        return $this->registerHook('displayHome')
            && $this->registerHook('displayShoppingCartFooter')
            && $this->registerHook('displayCustomerAccount');
    }

    public function uninstall()
    {
        foreach ([self::K_ENABLED, self::K_HOME, self::K_CART, self::K_ACCOUNT, self::K_PAYMENT, self::K_SUMMARY] as $k) {
            Configuration::deleteByName($k);
        }

        return true;
    }

    /** Checkout summaries: on unless switched off (a setting never saved counts as on). */
    public static function summaryOn()
    {
        $v = Configuration::get(self::K_SUMMARY);

        return $v === false || (int) $v === 1;
    }

    public static function enabled()
    {
        return (int) Configuration::get(self::K_ENABLED) === 1;
    }

    public function summary()
    {
        $on = self::enabled();

        return ['on' => $on, 'status' => $on ? $this->l('On') : $this->l('Off'),
            'fact' => (int) Configuration::get(self::K_PAYMENT) ? $this->l('The last order in the cart and the checkout open at payment, in one tap.') : $this->l('The last order in the cart in one tap.'), ];
    }

    /* ------------------------------------------------------------------ *
     *  The last order
     * ------------------------------------------------------------------ */

    /**
     * The customer's last valid order in this shop, with its lines.
     *
     * @return array|null id_order, date_add, total, id_currency, id_carrier, id_address_delivery,
     *                    id_address_invoice, lines[] (id_product, id_product_attribute, quantity, name)
     */
    public static function lastOrder($idCustomer, $idShop)
    {
        if ((int) $idCustomer <= 0) {
            return null;
        }
        $db = Db::getInstance();
        $order = $db->getRow('SELECT id_order, date_add, total_paid_tax_incl total, id_currency, id_carrier, id_address_delivery, id_address_invoice
            FROM `' . _DB_PREFIX_ . 'orders` WHERE id_customer = ' . (int) $idCustomer . ' AND id_shop = ' . (int) $idShop . ' AND valid = 1
            ORDER BY date_add DESC, id_order DESC');
        if (!$order) {
            return null;
        }
        $lines = [];
        foreach ($db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'order_detail` WHERE id_order = ' . (int) $order['id_order'] . ' ORDER BY id_order_detail') ?: [] as $d) {
            // a customised line (an engraving, a composition) cannot be rebuilt from here
            if (!empty($d['id_customization'])) {
                continue;
            }
            $lines[] = [
                'id_product' => (int) $d['product_id'], 'id_product_attribute' => (int) $d['product_attribute_id'],
                'quantity' => (int) $d['product_quantity'] - (int) (isset($d['product_quantity_refunded']) ? $d['product_quantity_refunded'] : 0),
                'name' => (string) $d['product_name'],
            ];
        }
        $lines = array_values(array_filter($lines, function ($l) { return $l['quantity'] > 0; }));
        if (!$lines) {
            return null;
        }
        $order['lines'] = $lines;

        return $order;
    }

    /**
     * The last order into the shopper's cart, with its addresses and carrier, and the checkout
     * set to open at payment.
     *
     * @return array added[], skipped[] (names), address, carrier, payment (bool each), id_order
     */
    public static function fill($context, $order, $toPayment = true)
    {
        $customer = $context->customer;
        $cart = $context->cart;
        $out = ['added' => [], 'skipped' => [], 'address' => false, 'carrier' => false, 'payment' => false, 'id_order' => (int) $order['id_order']];

        // the same addresses, when they are still the customer's
        $delivery = self::ownAddress($order['id_address_delivery'], $customer->id);
        $invoice = self::ownAddress($order['id_address_invoice'], $customer->id) ?: $delivery;

        if (!Validate::isLoadedObject($cart) || !$cart->id) {
            $cart = new Cart();
            $cart->id_customer = (int) $customer->id;
            $cart->id_lang = (int) $context->language->id;
            $cart->id_currency = (int) $context->currency->id;
            $cart->id_shop = (int) $context->shop->id;
            $cart->id_shop_group = (int) $context->shop->id_shop_group;
            $cart->id_guest = (int) $context->cookie->__get('id_guest');
            $cart->secure_key = $customer->secure_key;
            $cart->id_address_delivery = $delivery ?: (int) Address::getFirstCustomerAddressId($customer->id);
            $cart->id_address_invoice = $invoice ?: $cart->id_address_delivery;
            $cart->add();
            $context->cart = $cart;
            $context->cookie->__set('id_cart', (int) $cart->id);
        } elseif ($delivery) {
            if ((int) $cart->id_address_delivery !== $delivery && (int) $cart->id_address_delivery > 0) {
                $cart->updateAddressId((int) $cart->id_address_delivery, $delivery);
            }
            $cart->id_address_delivery = $delivery;
            $cart->id_address_invoice = $invoice;
            $cart->update();
        }
        $out['address'] = $delivery > 0 && (int) $cart->id_address_delivery === $delivery;

        foreach ($order['lines'] as $line) {
            $product = new Product($line['id_product'], false, $context->language->id);
            $ipa = $line['id_product_attribute'];
            $sold = Validate::isLoadedObject($product) && $product->active && $product->available_for_order
                && (!$ipa || Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . (int) $ipa . ' AND id_product = ' . (int) $line['id_product']));
            // updateQty checks stock and the minimal quantity itself: false or -1 when it cannot
            $done = $sold ? $cart->updateQty($line['quantity'], $line['id_product'], $ipa ?: null, false, 'up', (int) $cart->id_address_delivery) : false;
            if ($done === true || $done === 1) {
                $out['added'][] = $line['name'];
            } else {
                $out['skipped'][] = $line['name'];
            }
        }
        if (!$out['added']) {
            return $out;
        }

        // the same carrier, when it still delivers to this address
        $carrier = self::carrier((int) $order['id_carrier']);
        if ($carrier && $cart->id_address_delivery) {
            $options = $cart->getDeliveryOptionList();
            $key = $carrier . ',';
            if (isset($options[$cart->id_address_delivery][$key])) {
                $cart->setDeliveryOption([(int) $cart->id_address_delivery => $key]);
                $cart->update();
                $out['carrier'] = true;
            }
        }

        if ($toPayment && $out['address'] && ($out['carrier'] || $cart->isVirtualCart())) {
            $out['payment'] = self::toPayment($cart, $delivery === $invoice);
        }

        return $out;
    }

    /** An address id when it exists, is not deleted and belongs to the customer; else 0. */
    protected static function ownAddress($idAddress, $idCustomer)
    {
        $address = new Address((int) $idAddress);

        return Validate::isLoadedObject($address) && !$address->deleted && (int) $address->id_customer === (int) $idCustomer ? (int) $address->id : 0;
    }

    /** The carrier of an old order as it is now (editing a carrier makes a new one). */
    protected static function carrier($idCarrier)
    {
        $carrier = new Carrier($idCarrier);
        if (!Validate::isLoadedObject($carrier)) {
            return 0;
        }
        if ($carrier->deleted) {
            $carrier = Carrier::getCarrierByReference($carrier->id_reference);
            if (!$carrier || !Validate::isLoadedObject($carrier)) {
                return 0;
            }
        }

        return $carrier->active ? (int) $carrier->id : 0;
    }

    /**
     * Save the personal details, address and delivery steps as done, the way PrestaShop's own
     * checkout saves them (OrderController::saveDataToPersist), so it opens at payment.
     */
    public static function toPayment(Cart $cart, $sameAddress)
    {
        if (!class_exists('CartChecksum') || !class_exists('AddressChecksum')) {
            return false;
        }
        $done = ['step_is_reachable' => true, 'step_is_complete' => true];
        $data = [
            'checkout-personal-information-step' => $done,
            'checkout-addresses-step' => $done + ['use_same_address' => (bool) $sameAddress],
            'checkout-delivery-step' => $done,
            'checkout-payment-step' => ['step_is_reachable' => true, 'step_is_complete' => false],
        ];
        $checksum = new CartChecksum(new AddressChecksum());
        $data['checksum'] = $checksum->generateChecksum($cart);

        return Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'cart` SET checkout_session_data = \''
            . Db::getInstance()->escape(json_encode($data)) . '\' WHERE id_cart = ' . (int) $cart->id);
    }

    /* ------------------------------------------------------------------ *
     *  Shop
     * ------------------------------------------------------------------ */

    /** The card's variables for a signed-in customer with a past order, or null. */
    public function card($where)
    {
        $context = $this->context;
        if (!self::enabled() || !Validate::isLoadedObject($context->customer) || !$context->customer->isLogged() || SpcAudit::parts() !== null) {
            return null;
        }
        $keys = ['home' => self::K_HOME, 'cart' => self::K_CART, 'account' => self::K_ACCOUNT];
        if (!(int) Configuration::get($keys[$where])) {
            return null;
        }
        $order = self::lastOrder((int) $context->customer->id, (int) $context->shop->id);
        if (!$order) {
            return null;
        }
        $currency = new Currency((int) $order['id_currency']);
        $names = array_column($order['lines'], 'name');

        return [
            'where' => $where,
            'url' => $context->link->getModuleLink($this->name, 'reorder', [], true),
            'token' => Tools::getToken(false),
            'id_order' => (int) $order['id_order'],
            'date' => Tools::displayDate($order['date_add']),
            'total' => self::money($context, (float) $order['total'], $currency->iso_code ?: $context->currency->iso_code),
            'lines' => array_slice($names, 0, self::SHOWN),
            'more' => max(0, count($names) - self::SHOWN),
            'count' => count($names),
            'items' => array_sum(array_column($order['lines'], 'quantity')),
            'thumbs' => self::thumbs($context, $order['lines']),
            'firstname' => (string) $context->customer->firstname,
            'payment' => (bool) Configuration::get(self::K_PAYMENT),
        ];
    }

    /**
     * What each checkout step holds, by step id: the shopper, the addresses, the carrier and its
     * price. Shown next to the title of a finished step, so it can be checked at a glance.
     */
    public static function summaries($context, array $t)
    {
        $out = [];
        $customer = $context->customer;
        if (Validate::isLoadedObject($customer)) {
            $out['checkout-personal-information-step'] = trim(trim($customer->firstname . ' ' . $customer->lastname) . ' · ' . $customer->email, ' ·');
        }
        $cart = $context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return $out;
        }
        $line = function ($id) {
            $a = new Address((int) $id);
            if (!Validate::isLoadedObject($a)) {
                return '';
            }
            $street = trim($a->address1 . ' ' . $a->address2);

            return trim(implode(', ', array_filter([$street, trim($a->postcode . ' ' . $a->city)])));
        };
        $delivery = $line($cart->id_address_delivery);
        if ($delivery !== '') {
            $invoice = (int) $cart->id_address_invoice !== (int) $cart->id_address_delivery ? $line($cart->id_address_invoice) : '';
            $out['checkout-addresses-step'] = $delivery . ($invoice !== '' ? ' · ' . sprintf($t['invoice'], $invoice) : '');
        }
        $option = $cart->getDeliveryOption(null, true);
        $key = is_array($option) && isset($option[$cart->id_address_delivery]) ? (string) $option[$cart->id_address_delivery] : '';
        $names = [];
        foreach (array_filter(explode(',', $key)) as $idCarrier) {
            $carrier = new Carrier((int) $idCarrier, (int) $context->language->id);
            if (Validate::isLoadedObject($carrier)) {
                $names[] = $carrier->name;
            }
        }
        if ($names) {
            $cost = (float) $cart->getTotalShippingCost(null, true);
            $out['checkout-delivery-step'] = implode(', ', $names) . ' · ' . ($cost > 0 ? self::money($context, $cost, $context->currency->iso_code) : $t['free']);
        }

        return $out;
    }

    /** Cover pictures of the first products of the order (small size), for the card. */
    protected static function thumbs($context, array $lines)
    {
        $idShop = (int) $context->shop->id;
        $idLang = (int) $context->language->id;
        $type = ImageType::getFormattedName('small');
        $out = [];
        foreach (array_slice($lines, 0, self::SHOWN) as $line) {
            $row = Db::getInstance()->getRow('SELECT i.id_image, pl.link_rewrite FROM `' . _DB_PREFIX_ . 'image_shop` i
                INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.id_product = i.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . ')
                WHERE i.id_product = ' . (int) $line['id_product'] . ' AND i.id_shop = ' . $idShop . ' AND i.cover = 1');
            if ($row) {
                $out[] = ['src' => $context->link->getImageLink($row['link_rewrite'], (int) $line['id_product'] . '-' . (int) $row['id_image'], $type), 'alt' => $line['name']];
            }
        }

        return $out;
    }

    /** An amount in the order's currency, written the shop's way. */
    protected static function money($context, $amount, $iso)
    {
        return $context->getCurrentLocale()->formatPrice($amount, $iso);
    }

    public function show($where)
    {
        $card = $this->card($where);
        if (!$card) {
            return '';
        }
        // in the cart only while it is empty: a full cart has its own way to the checkout
        if ($where === 'cart' && Validate::isLoadedObject($this->context->cart) && SpcCartAnswer::count($this->context->cart) > 0) {
            return '';
        }

        return $this->render('hook/reorder.tpl', ['spc_reorder' => $card]);
    }

    public function hookActionFrontControllerSetMedia()
    {
        $controller = $this->context->controller;
        $page = isset($controller->php_self) ? $controller->php_self : '';
        if ($page === 'order' && self::summaryOn()) {
            Media::addJsDef(['spcCheckout' => self::summaries($this->context, [
                'invoice' => $this->l('invoice: %s'),
                'free' => $this->l('free'),
            ])]);
            $controller->registerJavascript('spc-checkout', 'modules/' . $this->name . '/views/js/checkout-summary.js', ['position' => 'bottom', 'priority' => 200, 'attributes' => 'defer']);
            $controller->registerStylesheet('spc-checkout', 'modules/' . $this->name . '/views/css/checkout-summary.css', ['media' => 'all', 'priority' => 150]);
        }
        if (!self::enabled() || !in_array($page, ['index', 'cart', 'my-account'], true) || !$this->context->customer->isLogged()) {
            return;
        }
        $controller->registerStylesheet('spc-reorder', 'modules/' . $this->name . '/views/css/reorder.css', ['media' => 'all', 'priority' => 150]);
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $out = '';
        if (!$this->isRegisteredInHook('displayHome') || !$this->isRegisteredInHook('displayCustomerAccount') || !$this->isRegisteredInHook('displayShoppingCartFooter')) {
            $this->registerHooks();
        }
        $keys = [self::K_ENABLED, self::K_HOME, self::K_CART, self::K_ACCOUNT, self::K_PAYMENT, self::K_SUMMARY];
        if (Tools::isSubmit('submitSpcReorder')) {
            foreach ($keys as $k) {
                Configuration::updateValue($k, Tools::getValue($k) ? 1 : 0);
            }
            $out .= $this->displayConfirmation($this->l('Settings updated'));
        }
        $switch = function ($name, $label, $desc) {
            return ['type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]], ];
        };
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitSpcReorder';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach ($keys as $k) {
            $helper->fields_value[$k] = (int) Configuration::get($k);
        }
        $helper->fields_value[self::K_SUMMARY] = self::summaryOn() ? 1 : 0;

        return $out . $helper->generateForm([['form' => [
            'id_form' => 'spc-reorder',
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-refresh'],
            'description' => $this->l('Shoppers who have ordered before get "Order the same as last time": one tap puts the products of their last order in the cart, with the same addresses and carrier, and opens the checkout at payment. Products no longer sold or out of stock are left out, and the shopper is told. With cash on delivery or a bank transfer, a repeat order takes two taps.'),
            'input' => [
                $switch(self::K_ENABLED, $this->l('Repeat last order'), $this->l('For signed-in shoppers with a past order.')),
                $switch(self::K_HOME, $this->l('On the home page'), $this->l('A card with the last order above the home page content (displayHome).')),
                $switch(self::K_CART, $this->l('In an empty cart'), $this->l('The same card while the cart is empty (displayShoppingCartFooter).')),
                $switch(self::K_ACCOUNT, $this->l('In the customer account'), $this->l('A tile next to "Order history" (displayCustomerAccount).')),
                $switch(self::K_SUMMARY, $this->l('Summaries of finished checkout steps'), $this->l('On every checkout, next to the title of a finished step: the name and e-mail, the address, the carrier and its price, so the shopper can check them at a glance (and press Edit only when something is wrong).')),
                $switch(self::K_PAYMENT, $this->l('Straight to payment'), $this->l('Addresses and carrier taken from the last order, so the checkout opens at the payment step. Off: the checkout starts as usual.')),
            ],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }
}
