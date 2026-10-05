<?php
/**
 * Instant add to cart.
 *
 * The stock "Add to cart" waits for two full page builds before the visitor sees anything: the
 * cart controller presents the whole cart for its JSON answer, then ps_shoppingcart builds the
 * pop-up in a second request. This module makes the button answer at once in the browser (the
 * header count goes up and a small confirmation slides in) and sends the product to a lean
 * endpoint (controllers/front/add.php) that does only what adding needs: checks, updateQty, count.
 *
 * Anything it is unsure about (composer products, required customisation, a failed request)
 * falls back to the shop's own add-to-cart, so nothing can get lost.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcInstantCart extends SpcFeature
{
    public $id = 'instantcart';

    public const K_ENABLED = 'SPC_IC_ENABLED';
    public const K_NOTIFY = 'SPC_IC_NOTIFY';
    public const K_LISTING = 'SPC_IC_LISTING';
    public const K_REMOVE = 'SPC_IC_REMOVE';

    /** @var array|null composer products (they need a composition: no quick add from lists) */
    private static $composed;

    public function install()
    {
        return Configuration::updateValue(self::K_ENABLED, 1)
            && Configuration::updateValue(self::K_NOTIFY, 0)
            && Configuration::updateValue(self::K_LISTING, 1)
            && $this->registerHooks();
    }

    /** a setting that was never saved (upgrade not run yet) counts as on */
    private static function on($key)
    {
        $v = Configuration::get($key);

        return $v === false || (int) $v === 1;
    }

    /** save for the current context and, with multistore, for every shop (else a shop value wins) */
    private static function save($key, $value)
    {
        Configuration::updateValue($key, $value);
        if (Shop::isFeatureActive()) {
            Configuration::updateGlobalValue($key, $value);
            foreach (Shop::getShops(false, null, true) as $idShop) {
                Configuration::updateValue($key, $value, false, null, (int) $idShop);
            }
        }
    }

    public function registerHooks()
    {
        return $this->registerHook('actionFrontControllerSetMedia')
            // the add button on product miniatures (category lists, search, home…)
            && $this->registerHook('displayProductListReviews');
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::K_ENABLED);
        Configuration::deleteByName(self::K_NOTIFY);
        Configuration::deleteByName(self::K_LISTING);
        Configuration::deleteByName(self::K_REMOVE);
        Configuration::deleteByName('SPC_IC_SOUND');

        return true;
    }

    public function hookActionFrontControllerSetMedia()
    {
        $controller = $this->context->controller;
        $page = isset($controller->php_self) ? $controller->php_self : '';
        // the checkout has no add-to-cart buttons
        if (!self::on(self::K_ENABLED) || Configuration::isCatalogMode() || in_array($page, ['order', 'order-confirmation'], true)) {
            return;
        }
        Media::addJsDef(['instantcart' => [
            'url' => $this->context->link->getModuleLink($this->name, 'add', [], true),
            'cartUrl' => $this->context->link->getPageLink('cart', true, null, ['action' => 'show']),
            'notify' => (int) Configuration::get(self::K_NOTIFY),
            // the cart page's bin: the line goes at once, the lean endpoint deletes it
            'removeUrl' => self::on(self::K_REMOVE) && $page === 'cart' ? $this->context->link->getModuleLink($this->name, 'remove', [], true) : '',
            't' => [
                'added' => $this->l('Added to cart'),
                'addedShort' => $this->l('Added'),
                'go' => $this->l('Cart'),
                'close' => $this->l('Close'),
                'error' => $this->l('Could not add to cart'),
                'removed' => $this->l('Removed from cart'),
                'undo' => $this->l('Undo'),
            ],
        ]]);
        $controller->registerJavascript('instantcart', 'modules/' . $this->name . '/views/js/instantcart.js', ['position' => 'bottom', 'priority' => 40, 'attributes' => 'defer']);
        $controller->registerStylesheet('instantcart', 'modules/' . $this->name . '/views/css/instantcart.css', ['media' => 'all', 'priority' => 150]);
    }

    /**
     * A compact add-to-cart form in each product miniature; instantcart.js moves it next to the
     * theme's "see" button and makes it instant. Only where the shop lets a list add the product
     * directly (in stock, no required choice) and never for composer products.
     */
    public function hookDisplayProductListReviews($params)
    {
        if (!self::on(self::K_ENABLED) || !self::on(self::K_LISTING) || Configuration::isCatalogMode()) {
            return '';
        }
        $p = isset($params['product']) ? $params['product'] : null;
        if (!$p || empty($p['id_product'])) {
            return '';
        }
        // the core leaves add_to_cart_url empty for every product with combinations (unless
        // "add to cart from lists for products with attributes" is on); those still get the button
        // and add their default combination. Only products that really cannot be ordered from here
        // are skipped: not orderable, price hidden, customisation required, sold out.
        $v = function ($k, $d = null) use ($p) { return isset($p[$k]) ? $p[$k] : $d; };
        if (empty($p['add_to_cart_url'])) {
            if (!$v('available_for_order', true) || !$v('show_price', true) || $v('customization_required')
                || (int) $v('customizable', 0) === 2
                || ((int) $v('quantity', 1) <= 0 && !$v('allow_oosp', false))) {
                return '';
            }
        }
        $id = (int) $p['id_product'];
        if (isset(self::composedIds()[$id])) {
            return '';
        }
        $qty = max(1, (int) (isset($p['minimal_quantity']) ? $p['minimal_quantity'] : 1));

        return $this->render('hook/list-button.tpl', [
            'spc_btn' => [
                'action' => $this->context->link->getPageLink('cart', true),
                'token' => Tools::getToken(false),
                'id_product' => $id,
                'id_product_attribute' => (int) $v('id_product_attribute', 0),
                'qty' => $qty,
                'label' => $this->l('Add to cart'),
                'name' => (string) $p['name'],
            ],
        ]);
    }

    /** id => true for products the Infobia composer configures (read once per request) */
    private static function composedIds()
    {
        if (self::$composed === null) {
            self::$composed = [];
            if (Module::isEnabled('infobia_product_composer')) {
                try {
                    foreach (Db::getInstance()->executeS('SELECT DISTINCT id_product FROM `' . _DB_PREFIX_ . 'infobia_config_product`') ?: [] as $r) {
                        self::$composed[(int) $r['id_product']] = true;
                    }
                } catch (Throwable $e) {
                    self::$composed = [];
                }
            }
        }

        return self::$composed;
    }

    public function getContent()
    {
        $out = '';
        // a zip uploaded over the old version may not have run the upgrade: attach what is missing
        $missing = !$this->isRegisteredInHook('displayProductListReviews') || !$this->isRegisteredInHook('actionFrontControllerSetMedia');
        if ($missing && $this->registerHooks()) {
            $out .= $this->displayConfirmation($this->l('The product list hook was attached.'));
        }
        if (Tools::isSubmit('submitInstantCart')) {
            self::save(self::K_ENABLED, Tools::getValue(self::K_ENABLED) ? 1 : 0);
            self::save(self::K_NOTIFY, Tools::getValue(self::K_NOTIFY) ? 1 : 0);
            self::save(self::K_LISTING, Tools::getValue(self::K_LISTING) ? 1 : 0);
            self::save(self::K_REMOVE, Tools::getValue(self::K_REMOVE) ? 1 : 0);
            $out .= $this->displayConfirmation($this->l('Settings updated'));
        }
        // what the shop actually uses, so a setting that "does not stick" is visible at once
        $out .= $this->render('admin/instantcart-status.tpl', [
            'spc_ic' => [
                'version' => $this->version,
                'enabled' => self::on(self::K_ENABLED),
                'listing' => self::on(self::K_LISTING),
                'hook' => $this->isRegisteredInHook('displayProductListReviews'),
                'catalog' => (bool) Configuration::isCatalogMode(),
            ],
        ]);
        $switch = function ($name, $label, $desc) {
            return [
                'type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]],
            ];
        };
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitInstantCart';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            self::K_ENABLED => self::on(self::K_ENABLED) ? 1 : 0,
            self::K_NOTIFY => (int) Configuration::get(self::K_NOTIFY),
            self::K_LISTING => self::on(self::K_LISTING) ? 1 : 0,
            self::K_REMOVE => self::on(self::K_REMOVE) ? 1 : 0,
        ];

        return $out . $helper->generateForm([['form' => [
            'id_form' => 'spc-instantcart',
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-shopping-cart'],
            'input' => [
                $switch(self::K_ENABLED, $this->l('Instant add to cart'), $this->l('The button reacts at once; the product is sent to a lean endpoint in the background.')),
                $switch(self::K_LISTING, $this->l('Add button on product lists'), $this->l('An instant add-to-cart button next to the product link in category lists, search and the home page – for products that need no choice.')),
                $switch(self::K_REMOVE, $this->l('Instant remove in the cart'), $this->l('The bin takes the line away at once, with an undo; the shop deletes it in the background and only the totals are refreshed.')),
                $switch(self::K_NOTIFY, $this->l('Tell other modules (updateCart event)'), $this->l('Off: no extra request after adding. Turn on only if a module (e.g. an analytics add_to_cart tag or a mini-cart dropdown) needs the event.')),
            ],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }
}
