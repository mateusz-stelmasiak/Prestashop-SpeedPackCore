<?php
/**
 * SpeedPack Core - four speed-ups for PrestaShop in one module.
 *
 *   SmartPrefetch  fetches the next page while the pointer rests on a link
 *   InstantNav     menu clicks swap the page content instead of reloading the page
 *   InstantCart    add to cart answers at once; quick clicks become one request
 *   CartSpeed      remembers address lookups for the page (an Address override)
 *
 * Each part lives in classes/ and can be switched off on its own on the configuration page.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/SpcFeature.php';
require_once dirname(__FILE__) . '/classes/SpcInstantCart.php';
require_once dirname(__FILE__) . '/classes/SpcInstantNav.php';
require_once dirname(__FILE__) . '/classes/SpcSmartPrefetch.php';

class SpeedPackCore extends Module
{
    public const K_CARTSPEED = 'SPC_CS_ENABLED';

    /** the separate modules this pack replaces; with both on, every part would run twice */
    public const REPLACES = ['smartprefetch', 'instantnav', 'instantcart', 'cartspeed'];

    /** @var SpcSmartPrefetch */
    private $smartPrefetch;

    /** @var SpcInstantNav */
    private $instantNav;

    /** @var SpcInstantCart */
    private $instantCart;

    public function __construct()
    {
        $this->name = 'speedpackcore';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Alhambra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '3eb6b4d19aa0d3c653ffeb7d54022e6c';
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('SpeedPack Core');
        $this->description = $this->l('Four speed-ups in one module: pages fetched before the click, menu clicks without a reload, instant add to cart and a lighter cart page.');
        $this->confirmUninstall = $this->l('The shop goes back to normal page loads and the standard add to cart. Remove SpeedPack Core?');

        $this->smartPrefetch = new SpcSmartPrefetch($this, $this->context, 'SmartPrefetch');
        $this->instantNav = new SpcInstantNav($this, $this->context, 'InstantNav');
        $this->instantCart = new SpcInstantCart($this, $this->context, 'InstantCart');
    }

    /** @return SpcFeature[] by id */
    public function parts()
    {
        return [
            'smartprefetch' => $this->smartPrefetch,
            'instantnav' => $this->instantNav,
            'instantcart' => $this->instantCart,
        ];
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        Configuration::updateValue(self::K_CARTSPEED, 1);
        foreach ($this->parts() as $part) {
            if (!$part->install()) {
                return false;
            }
        }

        return $this->registerHooks();
    }

    public function uninstall()
    {
        foreach ($this->parts() as $part) {
            $part->uninstall();
        }
        Configuration::deleteByName(self::K_CARTSPEED);

        return parent::uninstall();
    }

    public function registerHooks()
    {
        return $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayProductListReviews');
    }

    /* ------------------------------------------------------------------ *
     *  Front office: each hook goes to the parts that use it
     * ------------------------------------------------------------------ */

    public function hookActionFrontControllerSetMedia($params)
    {
        foreach ($this->parts() as $part) {
            $part->hookActionFrontControllerSetMedia();
        }
    }

    public function hookDisplayHeader($params)
    {
        $this->smartPrefetch->hookDisplayHeader();

        return '';
    }

    public function hookDisplayProductListReviews($params)
    {
        return $this->instantCart->hookDisplayProductListReviews($params);
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $out = '';
        foreach (['actionFrontControllerSetMedia', 'displayHeader', 'displayProductListReviews'] as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $this->registerHook($hook);
            }
        }
        if (Tools::isSubmit('submitSpcCartSpeed')) {
            Configuration::updateValue(self::K_CARTSPEED, Tools::getValue(self::K_CARTSPEED) ? 1 : 0);
            $out .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        $twice = [];
        foreach (self::REPLACES as $old) {
            if (Module::isInstalled($old) && Module::isEnabled($old)) {
                $twice[] = $old;
            }
        }
        $sections = [];
        $body = '';
        foreach ($this->parts() as $id => $part) {
            $sections[] = ['id' => $id, 'title' => $part->displayName];
            $body .= $part->getContent();
        }
        $sections[] = ['id' => 'cartspeed', 'title' => 'CartSpeed'];
        $body .= $this->cartSpeedForm();
        $this->context->smarty->assign(['spc' => [
            'version' => $this->version,
            'twice' => implode(', ', $twice),
            'sections' => $sections,
        ]]);

        return $out . $this->display(__FILE__, 'views/templates/admin/configure.tpl') . $body;
    }

    private function cartSpeedForm()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitSpcCartSpeed';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [self::K_CARTSPEED => (int) Configuration::get(self::K_CARTSPEED)];

        return $helper->generateForm([['form' => [
            'id_form' => 'spc-cartspeed',
            'legend' => ['title' => 'CartSpeed', 'icon' => 'icon-dashboard'],
            'description' => $this->l('PrestaShop asks the database "does this address exist?" for every price and tax in the cart. CartSpeed remembers the answer for the rest of the page (73 identical queries become 4 on a typical cart page). It works through an override of Address::addressExists(), installed and removed with the module.'),
            'input' => [[
                'type' => 'switch', 'name' => self::K_CARTSPEED, 'label' => $this->l('Remember address lookups'), 'is_bool' => true,
                'values' => [['id' => 'cs_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => 'cs_off', 'value' => 0, 'label' => $this->l('No')]],
            ]],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }
}
