<?php
/**
 * AsyncCart - the cart page without waiting.
 *
 * On a stock shop every +/- on the cart page is its own request that rebuilds the whole cart,
 * and nothing moves until the shop answers. Here the page changes at once: the quantity, the
 * header count, a removed line. Quick changes reach the shop as one request with the final
 * quantity, through lean endpoints that answer with the few numbers the summary shows.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/AsyncCartAnswer.php';

class AsyncCart extends Module
{
    public const K_QTY = 'ASYNCCART_QTY';
    public const K_REMOVE = 'ASYNCCART_REMOVE';
    public const K_DELAY = 'ASYNCCART_DELAY';
    public const K_NOTIFY = 'ASYNCCART_NOTIFY';

    public function __construct()
    {
        $this->name = 'asynccart';
        $this->tab = 'front_office_features';
        $this->version = '1.0.1';
        $this->author = 'Alhambra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('AsyncCart');
        $this->description = $this->l('Quantity changes and removals on the cart page happen at once; quick changes reach the shop as one request.');
    }

    protected function defaults()
    {
        return [
            self::K_QTY => 1,
            self::K_REMOVE => 1,
            // how long the page waits after the last click before telling the shop
            self::K_DELAY => 400,
            self::K_NOTIFY => 0,
        ];
    }

    protected function conf($key)
    {
        $value = Configuration::get($key);
        if ($value === false || $value === '') {
            $defaults = $this->defaults();

            return $defaults[$key];
        }

        return $value;
    }

    public function install()
    {
        foreach ($this->defaults() as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        return parent::install() && $this->registerHook('actionFrontControllerSetMedia');
    }

    public function uninstall()
    {
        foreach (array_keys($this->defaults()) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * SpeedPack Core's InstantCart does the same on the cart page; with both on, two handlers
     * would answer one click. When it is on, this module stands aside.
     */
    protected function speedPackHandlesCart()
    {
        if (!Module::isInstalled('speedpackcore') || !Module::isEnabled('speedpackcore')) {
            return false;
        }
        $on = function ($key) {
            $v = Configuration::get($key);

            return $v === false || (int) $v === 1;
        };

        return $on('SPC_IC_ENABLED') && ($on('SPC_IC_QTY') || $on('SPC_IC_REMOVE'));
    }

    public function hookActionFrontControllerSetMedia()
    {
        $controller = $this->context->controller;
        $page = isset($controller->php_self) ? $controller->php_self : '';
        $qty = (bool) $this->conf(self::K_QTY);
        $remove = (bool) $this->conf(self::K_REMOVE);
        if ($page !== 'cart' || (!$qty && !$remove) || $this->speedPackHandlesCart()) {
            return;
        }
        Media::addJsDef(['asyncCart' => [
            'qtyUrl' => $qty ? $this->context->link->getModuleLink($this->name, 'qty', [], true) : '',
            'removeUrl' => $remove ? $this->context->link->getModuleLink($this->name, 'remove', [], true) : '',
            'delay' => (int) $this->conf(self::K_DELAY),
            'notify' => (int) $this->conf(self::K_NOTIFY),
            't' => [
                'removed' => $this->l('Removed from cart'),
                'undo' => $this->l('Undo'),
                'qtyError' => $this->l('Quantity not changed'),
                'removeError' => $this->l('Not removed'),
                'error' => $this->l('Something went wrong. Please try again.'),
            ],
        ]]);
        $controller->registerJavascript('asynccart', 'modules/' . $this->name . '/views/js/asynccart.js', ['position' => 'bottom', 'priority' => 45, 'attributes' => 'defer']);
        $controller->registerStylesheet('asynccart', 'modules/' . $this->name . '/views/css/asynccart.css', ['media' => 'all', 'priority' => 150]);
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $out = '';
        if (!$this->isRegisteredInHook('actionFrontControllerSetMedia')) {
            $this->registerHook('actionFrontControllerSetMedia');
        }
        if (Tools::isSubmit('submitAsyncCart')) {
            $delay = (int) Tools::getValue(self::K_DELAY);
            if ($delay < 100 || $delay > 2000) {
                $out .= $this->displayError($this->l('The wait must be between 100 and 2000 ms.'));
            } else {
                Configuration::updateValue(self::K_QTY, Tools::getValue(self::K_QTY) ? 1 : 0);
                Configuration::updateValue(self::K_REMOVE, Tools::getValue(self::K_REMOVE) ? 1 : 0);
                Configuration::updateValue(self::K_NOTIFY, Tools::getValue(self::K_NOTIFY) ? 1 : 0);
                Configuration::updateValue(self::K_DELAY, $delay);
                $out .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }
        if ($this->speedPackHandlesCart()) {
            $out .= $this->displayWarning($this->l('SpeedPack Core already makes the cart page instant (InstantCart), so AsyncCart stays off to avoid two handlers on one click. Switch off its cart options there to use AsyncCart instead.'));
        }

        $this->context->smarty->assign(['asynccart' => [
            'rows' => [
                $this->l('Instant quantity change') => $this->conf(self::K_QTY) ? $this->l('on') : $this->l('off'),
                $this->l('Instant remove with Undo') => $this->conf(self::K_REMOVE) ? $this->l('on') : $this->l('off'),
                $this->l('Wait after the last click') => (int) $this->conf(self::K_DELAY) . ' ms',
                $this->l('Hook actionFrontControllerSetMedia') => $this->isRegisteredInHook('actionFrontControllerSetMedia') ? $this->l('registered') : $this->l('missing'),
                $this->l('Active on the cart page') => $this->speedPackHandlesCart() ? $this->l('no, SpeedPack Core handles it') : $this->l('yes'),
            ],
        ]]);

        return $out . $this->display(__FILE__, 'views/templates/admin/status.tpl') . $this->renderForm();
    }

    protected function renderForm()
    {
        $switch = function ($name, $label, $desc) {
            return [
                'type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]],
            ];
        };
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitAsyncCart';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            self::K_QTY => (int) $this->conf(self::K_QTY),
            self::K_REMOVE => (int) $this->conf(self::K_REMOVE),
            self::K_DELAY => (int) $this->conf(self::K_DELAY),
            self::K_NOTIFY => (int) $this->conf(self::K_NOTIFY),
        ];

        return $helper->generateForm([['form' => [
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-shopping-cart'],
            'input' => [
                $switch(self::K_QTY, $this->l('Instant quantity change'), $this->l('The + and - buttons and a typed quantity change the line at once. The shop hears the final quantity once; if it refuses (stock, minimum), the number goes back with its message.')),
                $switch(self::K_REMOVE, $this->l('Instant remove with Undo'), $this->l('The bin takes the line away at once, with Undo. The shop deletes it in the background.')),
                ['type' => 'text', 'name' => self::K_DELAY, 'label' => $this->l('Wait after the last click (ms)'), 'class' => 'fixed-width-sm', 'desc' => $this->l('Clicks within this time become one request. 300-500 ms suits most shoppers.')],
                $switch(self::K_NOTIFY, $this->l('Tell other modules (updateCart event)'), $this->l('Off: only the totals are updated, nothing re-renders. Turn on if a module (a mini-cart, an analytics tag, a delivery widget) must hear every change.')),
            ],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }
}
