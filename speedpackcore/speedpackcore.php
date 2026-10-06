<?php
/**
 * SpeedPack Core - four speed-ups for PrestaShop in one module.
 *
 *   SmartPrefetch  fetches the next page while the pointer rests on a link
 *   InstantNav     menu clicks swap the page content instead of reloading the page
 *   InstantCart    add to cart answers at once; quick clicks become one request
 *   CartSpeed      remembers address lookups for the page (an Address override)
 *
 * Each part lives in classes/ and can be switched off on its own on the configuration page. The
 * speed audit (classes/SpcAudit.php) measures the shop with each part off and on.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/SpcFeature.php';
require_once dirname(__FILE__) . '/classes/SpcAudit.php';
require_once dirname(__FILE__) . '/classes/SpcCartAnswer.php';
require_once dirname(__FILE__) . '/classes/SpcCacheBackend.php';
require_once dirname(__FILE__) . '/classes/SpcOpcache.php';
require_once dirname(__FILE__) . '/classes/SpcWarmup.php';
require_once dirname(__FILE__) . '/classes/SpcCache.php';
require_once dirname(__FILE__) . '/classes/SpcInstantCart.php';
require_once dirname(__FILE__) . '/classes/SpcInstantNav.php';
require_once dirname(__FILE__) . '/classes/SpcSmartPrefetch.php';
require_once dirname(__FILE__) . '/classes/SpcHealth.php';
require_once dirname(__FILE__) . '/classes/SpcCare.php';
require_once dirname(__FILE__) . '/classes/SpcWeight.php';
require_once dirname(__FILE__) . '/classes/SpcDiagnostics.php';

class SpeedPackCore extends Module
{
    public const K_CARTSPEED = 'SPC_CS_ENABLED';

    /** the separate modules this pack replaces; with both on, every part would run twice */
    public const REPLACES = ['smartprefetch', 'instantnav', 'instantcart', 'cartspeed'];

    /** @var SpcCache */
    private $cache;

    /** @var SpcSmartPrefetch */
    private $smartPrefetch;

    /** @var SpcInstantNav */
    private $instantNav;

    /** @var SpcInstantCart */
    private $instantCart;

    /** @var SpcDiagnostics the health check: on the settings page only, never on the shop */
    private $diagnostics;

    public function __construct()
    {
        $this->name = 'speedpackcore';
        $this->tab = 'front_office_features';
        $this->version = '1.3.0';
        $this->author = 'Alhambra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '3eb6b4d19aa0d3c653ffeb7d54022e6c';
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('SpeedPack Core');
        $this->description = $this->l('Speed-ups in one module: Redis, APCu or Memcached data cache, pages fetched before the click, menu clicks without a reload, instant cart changes and a lighter cart page.');
        $this->confirmUninstall = $this->l('The shop goes back to normal page loads and the standard add to cart. Remove SpeedPack Core?');

        $this->cache = new SpcCache($this, $this->context, 'Cache');
        $this->smartPrefetch = new SpcSmartPrefetch($this, $this->context, 'SmartPrefetch');
        $this->instantNav = new SpcInstantNav($this, $this->context, 'InstantNav');
        $this->instantCart = new SpcInstantCart($this, $this->context, 'InstantCart');
        $this->diagnostics = new SpcDiagnostics($this, $this->context, $this->l('Health check'));
    }

    /** @return SpcFeature[] by id */
    public function parts()
    {
        return [
            'cache' => $this->cache,
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
        // the settings page offers the speed audit until it has run once
        Configuration::updateValue(SpcAudit::K_DONE, 0);
        SpcAudit::key();
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
            if (!$part->uninstall()) {
                // the data cache could not be switched off: removing the module now would leave
                // PrestaShop pointed at a cache class that is about to go
                $this->_errors[] = $this->l('The data cache could not be switched off (is app/config/parameters.php writable?). Switch it off in the Cache section first.');

                return false;
            }
        }
        Configuration::deleteByName(self::K_CARTSPEED);
        foreach ([SpcAudit::K_KEY, SpcAudit::K_HISTORY, SpcAudit::K_DONE] as $key) {
            Configuration::deleteByName($key);
        }

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

    /* A request from the speed audit may ask for some parts to stay out (SpcAudit::off); for
     * every other visitor SpcAudit::off() is false and each part runs as set up. */

    public function hookActionFrontControllerSetMedia($params)
    {
        foreach ($this->parts() as $id => $part) {
            if (!SpcAudit::off($id)) {
                $part->hookActionFrontControllerSetMedia();
            }
        }
    }

    public function hookDisplayHeader($params)
    {
        if (!SpcAudit::off('smartprefetch')) {
            $this->smartPrefetch->hookDisplayHeader();
        }

        return '';
    }

    public function hookDisplayProductListReviews($params)
    {
        return SpcAudit::off('instantcart') ? '' : $this->instantCart->hookDisplayProductListReviews($params);
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        if (Tools::getValue('spc_ajax') === 'warmup') {
            $this->cache->ajaxWarmup();
        }
        if (in_array(Tools::getValue('spc_ajax'), ['care_scan', 'care', 'analyze', 'weight'], true)) {
            $this->diagnostics->ajax((string) Tools::getValue('spc_ajax'));
        }
        if (Tools::getValue('spc_ajax') === 'audit') {
            $this->ajaxAudit((string) Tools::getValue('step'));
        }
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
        $sections[] = ['id' => 'diagnostics', 'title' => $this->diagnostics->displayName];
        $body .= $this->diagnostics->getContent();
        $this->context->smarty->assign(['spc' => [
            'version' => $this->version,
            'twice' => implode(', ', $twice),
            'sections' => $sections,
        ]]);

        return $out . $this->display(__FILE__, 'views/templates/admin/configure.tpl') . $this->renderAudit() . $body;
    }

    /* ------------------------------------------------------------------ *
     *  Speed audit
     * ------------------------------------------------------------------ */

    /** One step of the audit, posted by views/js/audit.js; answers JSON. */
    private function ajaxAudit($step)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        @set_time_limit(60);
        $plan = SpcAudit::plan($this->context, $this);
        switch ($step) {
            case 'plan':
                $answer = $plan;
                break;
            case 'page':
                $i = (int) Tools::getValue('i');
                $answer = isset($plan['pages'][$i])
                    ? SpcAudit::page($plan['pages'][$i]['url'], $plan['tokens'])
                    : ['error' => 'no such page'];
                break;
            case 'cart':
                $answer = SpcAudit::cart($this->context, $plan['product'], $plan['tokens']['all']);
                break;
            case 'cartspeed':
                $answer = SpcAudit::cartSpeed($this->context);
                break;
            case 'save':
                $results = json_decode((string) Tools::getValue('results'), true);
                $answer = is_array($results) ? ['run' => SpcAudit::save($results), 'history' => SpcAudit::history()] : ['error' => 'nothing to save'];
                break;
            default:
                $answer = ['error' => 'unknown step'];
        }
        echo json_encode($answer);
        exit;
    }

    private function renderAudit()
    {
        $this->context->controller->addCSS($this->getPathUri() . 'views/css/audit.css');
        $this->context->controller->addJS($this->getPathUri() . 'views/js/audit.js');
        $texts = [
            'start' => $this->l('Measure my shop'),
            'again' => $this->l('Measure again'),
            'running' => $this->l('Measuring...'),
            'plan' => $this->l('Choosing pages to measure'),
            'nav' => $this->l('Clicking through the shop: %s'),
            'page' => $this->l('Page %1$d of %2$d: %3$s'),
            'cart' => $this->l('Adding to the cart'),
            'cartspeed' => $this->l('Counting address lookups in the cart'),
            'save' => $this->l('Saving the results'),
            'done' => $this->l('Done in %s s.'),
            'popupBlocked' => $this->l('The browser blocked the shop window, so the click test was skipped. Allow pop-ups for this page and measure again.'),
            'popupWait' => $this->l('SpeedPack Core is measuring this shop. This window closes by itself in about a minute.'),
            'otherOrigin' => $this->l('The shop is on another address than this back office, so the click test cannot run from here.'),
            'noLinks' => $this->l('No menu links were found for the click test (see "Links swapped" in InstantNav).'),
            'switchedOff' => $this->l('Switched off in the settings'),
            'without' => $this->l('Without SpeedPack'),
            'with' => $this->l('With SpeedPack'),
            'faster' => $this->l('%sx faster'),
            'fewer' => $this->l('%s fewer queries'),
            'ms' => $this->l('%s ms'),
            'queries' => $this->l('%s queries'),
            'query' => $this->l('%s query'),
            'same' => $this->l('About the same'),
            'failed' => $this->l('Could not measure: %s'),
            'noCache' => $this->l('No data cache is chosen yet: pick Redis, APCu or Memcached in the Cache section.'),
            'noBypass' => $this->l('Only Redis can be switched off for the audit alone, so with this cache both sides are measured with it on.'),
            'clickWithout' => $this->l('No speed-ups'),
            'clickSp' => $this->l('SmartPrefetch'),
            'clickNav' => $this->l('InstantNav'),
            'clickAll' => $this->l('All of SpeedPack'),
            'heroTitle' => $this->l('From click to page shown'),
            'historyTitle' => $this->l('Earlier audits'),
            'historyClick' => $this->l('Click to page, with SpeedPack'),
            'historyPage' => $this->l('Server answer, with SpeedPack'),
            'stopped' => $this->l('The audit stopped: %s'),
            'prerenderNote' => $this->l('A quick 0.3 s hover. On a longer hover, Chrome and Edge also build the whole page in advance, so it shows almost at once; a test window cannot show that part.'),
        ];

        return $this->auditTemplate($texts);
    }

    private function auditTemplate(array $texts)
    {
        $this->context->smarty->assign(['spc_audit' => [
            'url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'home' => $this->context->link->getPageLink('index', true),
            'texts' => json_encode($texts),
            'history' => json_encode(SpcAudit::history()),
            'first' => !Configuration::get(SpcAudit::K_DONE),
        ]]);

        return $this->display(__FILE__, 'views/templates/admin/audit.tpl');
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
