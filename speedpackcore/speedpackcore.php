<?php
/**
 * SpeedPack Core - four speed-ups for PrestaShop in one module.
 *
 *   SmartPrefetch  fetches the next page while the pointer rests on a link
 *   InstantNav     menu clicks swap the page content instead of reloading the page
 *   InstantCart    add to cart answers at once; quick clicks become one request
 *   CartSpeed      remembers address lookups for the page (an Address override)
 *   Reorder        the last order again in one tap, the checkout opening at payment
 *   Behaviour      what shoppers do: time on each page, routes, paths to an order, failure points
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
require_once dirname(__FILE__) . '/classes/SpcBehaviour.php';
require_once dirname(__FILE__) . '/classes/SpcReorder.php';
require_once dirname(__FILE__) . '/classes/SpcHtml.php';
require_once dirname(__FILE__) . '/classes/SpcImages.php';
require_once dirname(__FILE__) . '/classes/SpcPageCache.php';
require_once dirname(__FILE__) . '/classes/SpcOptimize.php';

class SpeedPackCore extends Module
{
    public const K_CARTSPEED = 'SPC_CS_ENABLED';

    /** the version whose settings page was last opened: a new one runs the speed audit by itself */
    public const K_SEEN = 'SPC_SEEN_VERSION';

    /** the version whose stylesheets and scripts the shop's combined files were made from */
    public const K_ASSETS = 'SPC_ASSETS_VERSION';

    /** opt-in: a small visible credit in the shop footer, and a section in the shop's llms.txt */
    public const K_CREDIT = 'SPC_CREDIT';
    public const K_LLMS = 'SPC_LLMS';

    public const SITE = 'https://github.com/mateusz-stelmasiak/Prestashop-SpeedPackCore';
    public const AUDIT_EMAIL = 'mateusz.stelmasiak@gmail.com';
    public const AUTHOR = 'Mateusz Stelmasiak';
    public const AUTHOR_URL = 'https://github.com/mateusz-stelmasiak';

    /** the separate modules this pack replaces; with both on, every part would run twice */
    public const REPLACES = ['smartprefetch', 'instantnav', 'instantcart', 'cartspeed'];

    /** @var SpcCache */
    private $cache;

    /** @var SpcPageCache */
    private $pageCache;

    /** @var SpcOptimize */
    private $optimize;

    /** @var SpcSmartPrefetch */
    private $smartPrefetch;

    /** @var SpcInstantNav */
    private $instantNav;

    /** @var SpcInstantCart */
    private $instantCart;

    /** @var SpcDiagnostics the health check: on the settings page only, never on the shop */
    private $diagnostics;

    /** @var SpcBehaviour */
    private $behaviour;

    /** @var SpcReorder */
    private $reorder;

    /** @var bool the settings page of a version opened for the first time */
    private $autoAudit = false;

    public function __construct()
    {
        $this->name = 'speedpackcore';
        $this->tab = 'front_office_features';
        $this->version = '1.7.3';
        $this->author = 'Alhambra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '3eb6b4d19aa0d3c653ffeb7d54022e6c';
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('SpeedPack Core');
        $this->description = $this->l('Speed-ups in one module: a page cache, WebP and AVIF pictures, lazy loading, critical CSS, Redis, APCu or Memcached data cache, pages fetched before the click, menu clicks without a reload, instant cart changes and a lighter cart page.');
        $this->confirmUninstall = $this->l('The shop goes back to normal page loads and the standard add to cart. Remove SpeedPack Core?');

        $this->cache = new SpcCache($this, $this->context, 'Cache');
        $this->pageCache = new SpcPageCache($this, $this->context, $this->l('Page cache'));
        $this->optimize = new SpcOptimize($this, $this->context, $this->l('Optimize'));
        $this->smartPrefetch = new SpcSmartPrefetch($this, $this->context, 'SmartPrefetch');
        $this->instantNav = new SpcInstantNav($this, $this->context, 'InstantNav');
        $this->instantCart = new SpcInstantCart($this, $this->context, 'InstantCart');
        $this->diagnostics = new SpcDiagnostics($this, $this->context, $this->l('Health check'));
        $this->behaviour = new SpcBehaviour($this, $this->context, $this->l('Behaviour'));
        $this->reorder = new SpcReorder($this, $this->context, $this->l('Reorder'));
    }

    /** @return SpcFeature[] by id */
    public function parts()
    {
        return [
            'cache' => $this->cache,
            'pagecache' => $this->pageCache,
            'optimize' => $this->optimize,
            'smartprefetch' => $this->smartPrefetch,
            'instantnav' => $this->instantNav,
            'instantcart' => $this->instantCart,
            'reorder' => $this->reorder,
            'behaviour' => $this->behaviour,
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
        foreach ([SpcAudit::K_KEY, SpcAudit::K_HISTORY, SpcAudit::K_DONE, self::K_SEEN, self::K_ASSETS, self::K_CREDIT, self::K_LLMS] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    public function registerHooks()
    {
        return $this->registerHook('actionDispatcherBefore')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayProductListReviews')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayFooter')
            && $this->registerHook('displayLlmsTxt')
            && $this->reorder->registerHooks()
            && $this->behaviour->registerHooks()
            && $this->pageCache->registerHooks()
            && $this->optimize->registerHooks();
    }

    /* ------------------------------------------------------------------ *
     *  Front office: each hook goes to the parts that use it
     * ------------------------------------------------------------------ */

    /* A request from the speed audit may ask for some parts to stay out (SpcAudit::off); for
     * every other visitor SpcAudit::off() is false and each part runs as set up. */

    /** First thing on a shop request: the configuration an audit request asks for (SpcAudit::apply). */
    public function hookActionDispatcherBefore($params)
    {
        // the back office is never measured, even from a browser that carries the audit cookie
        if (isset($params['controller_type']) && (int) $params['controller_type'] === Dispatcher::FC_ADMIN) {
            return;
        }
        SpcAudit::apply();
        // a page kept for visitors who are not signed in: sent now, before PrestaShop builds it
        if (!isset($params['controller_type']) || (int) $params['controller_type'] === Dispatcher::FC_FRONT) {
            SpcPageCache::serve((string) Dispatcher::getInstance()->getController(), $this->context);
        }
    }

    /**
     * The page PrestaShop has just built, on its way out: Optimize's changes, then kept by the
     * page cache (so a page served from it has them already).
     */
    public function hookActionOutputHTMLBefore($params)
    {
        if (!isset($params['html']) || !is_string($params['html'])) {
            return;
        }
        $html = &$params['html'];
        if (!SpcAudit::off('optimize')) {
            $html = $this->optimize->transform($html, $this->pageName());
        }
        SpcPageCache::store((string) Dispatcher::getInstance()->getController(), $html, $this->context);
    }

    /** The page's name as Optimize knows it: index, product... or module-<name> for a module's page. */
    private function pageName()
    {
        $c = $this->context->controller;
        if ($c instanceof ModuleFrontController && $c->module) {
            return 'module-' . $c->module->name;
        }

        return isset($c->php_self) && $c->php_self ? (string) $c->php_self : (string) Dispatcher::getInstance()->getController();
    }

    /* What makes kept pages stale (only while the page cache is on: switching it on empties it) */

    /** A product, its stock or its price changed: its page, its categories, the home page and the listings. */
    private function productChanged($idProduct)
    {
        if (!SpcPageCache::enabled()) {
            return;
        }
        if ((int) $idProduct > 0) {
            SpcPageCache::productChanged((int) $idProduct);
        } else {
            // a price for every product
            SpcPageCache::flush();
        }
    }

    /** Anything that shows on many pages (a category, a CMS page, a brand, the theme, a module). */
    private function siteChanged()
    {
        if (SpcPageCache::enabled()) {
            SpcPageCache::flush();
        }
    }

    public function hookActionObjectProductAddAfter($params)
    {
        $this->productChanged(isset($params['object']->id) ? $params['object']->id : 0);
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->productChanged(isset($params['object']->id) ? $params['object']->id : 0);
    }

    public function hookActionObjectProductDeleteAfter($params)
    {
        $this->productChanged(isset($params['object']->id) ? $params['object']->id : 0);
    }

    public function hookActionUpdateQuantity($params)
    {
        if (!empty($params['id_product'])) {
            $this->productChanged($params['id_product']);
        }
    }

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $this->productChanged(isset($params['object']->id_product) ? $params['object']->id_product : 0);
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $this->productChanged(isset($params['object']->id_product) ? $params['object']->id_product : 0);
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $this->productChanged(isset($params['object']->id_product) ? $params['object']->id_product : 0);
    }

    public function hookActionObjectCategoryAddAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectCategoryUpdateAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectCmsAddAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectCmsUpdateAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectCmsDeleteAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectSupplierUpdateAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionObjectSpecificPriceRuleUpdateAfter($params)
    {
        $this->siteChanged();
    }

    public function hookActionClearCache($params)
    {
        $this->siteChanged();
    }

    public function hookActionClearCompileCache($params)
    {
        $this->siteChanged();
    }

    public function hookActionModuleInstallAfter($params)
    {
        $this->siteChanged();
    }

    /** New product pictures (after PrestaShop made their sizes): their WebP and AVIF copies. */
    public function hookActionWatermark($params)
    {
        $this->optimize->hookActionWatermark($params);
    }

    public function hookActionObjectImageDeleteAfter($params)
    {
        $this->optimize->hookActionObjectImageDeleteAfter($params);
    }

    public function hookActionFrontControllerSetMedia($params)
    {
        // a second chance for a shop upgraded without visiting the settings page (the dispatcher
        // hook not registered yet): still before the page's content is built
        SpcAudit::apply();
        $this->assetsUpdated();
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

    public function hookDisplayHome($params)
    {
        return $this->reorder->show('home');
    }

    public function hookDisplayShoppingCartFooter($params)
    {
        return $this->reorder->show('cart');
    }

    public function hookDisplayCustomerAccount($params)
    {
        return $this->reorder->show('account');
    }

    public function hookDisplayAdminOrderMain($params)
    {
        return $this->behaviour->hookDisplayAdminOrderMain($params);
    }

    public function hookDisplayAdminOrder($params)
    {
        return $this->behaviour->hookDisplayAdminOrder($params);
    }

    public function hookDisplayBackOfficeHeader($params)
    {
        return $this->behaviour->hookDisplayBackOfficeHeader($params);
    }

    public function hookActionValidateOrder($params)
    {
        $this->behaviour->hookActionValidateOrder($params);
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
        if (Tools::getValue('spc_ajax') === 'behaviour') {
            $this->behaviour->ajax();
        }
        if (Tools::getValue('spc_ajax') === 'optimize') {
            $this->optimize->ajax((string) Tools::getValue('op'));
        }
        if (Tools::getValue('spc_ajax') === 'audit') {
            $this->ajaxAudit((string) Tools::getValue('step'));
        }
        $out = '';
        // a new version (installed or updated): the speed audit runs by itself, to show what it does
        $this->autoAudit = Configuration::get(self::K_SEEN) !== $this->version;
        if ($this->autoAudit) {
            Configuration::updateValue(self::K_SEEN, $this->version);
        }
        $this->assetsUpdated();
        foreach (['actionDispatcherBefore', 'actionFrontControllerSetMedia', 'displayHeader', 'displayProductListReviews', 'actionValidateOrder', 'displayFooter', 'displayLlmsTxt'] as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $this->registerHook($hook);
            }
        }
        if (Tools::isSubmit('submitSpcCartSpeed')) {
            Configuration::updateValue(self::K_CARTSPEED, Tools::getValue(self::K_CARTSPEED) ? 1 : 0);
            $out .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        if (Tools::isSubmit('submitSpcShare') || Tools::isSubmit('submitSpcLlmsNow')) {
            Configuration::updateValue(self::K_CREDIT, Tools::getValue(self::K_CREDIT) ? 1 : 0);
            Configuration::updateValue(self::K_LLMS, Tools::getValue(self::K_LLMS) ? 1 : 0);
            $out .= Tools::isSubmit('submitSpcLlmsNow') ? $this->llmsNow() : $this->displayConfirmation($this->l('Settings updated.'));
        }
        if (Tools::isSubmit('submitSpcToggle')) {
            $out .= $this->toggle((string) Tools::getValue('spc_part'));
        }

        $twice = [];
        foreach (self::REPLACES as $old) {
            if (Module::isInstalled($old) && Module::isEnabled($old)) {
                $twice[] = $old;
            }
        }

        // every section of the page, in tab order; each starts with a marker (pane.tpl) that
        // views/js/config.js turns into a tab
        // the speed-ups first, then the health check, then Behaviour (not a speed-up: a report)
        $panes = ['audit' => $this->renderAudit()];
        foreach ($this->speedUps() as $id => $part) {
            $panes[$id] = $part->getContent();
        }
        $panes['cartspeed'] = $this->cartSpeedForm();
        $panes['diagnostics'] = $this->diagnostics->getContent();
        $panes['behaviour'] = $this->behaviour->getContent();

        $tabs = [['id' => 'overview', 'title' => $this->l('Overview')], ['id' => 'audit', 'title' => $this->l('Speed audit')]];
        foreach ($this->speedUps() as $id => $part) {
            $tabs[] = ['id' => $id, 'title' => $part->displayName];
        }
        $tabs[] = ['id' => 'cartspeed', 'title' => 'CartSpeed'];
        $tabs[] = ['id' => 'diagnostics', 'title' => $this->diagnostics->displayName];
        $tabs[] = ['id' => 'behaviour', 'title' => $this->behaviour->displayName];

        $this->context->smarty->assign(['spc' => [
            'version' => $this->version,
            'twice' => implode(', ', $twice),
            'tabs' => $tabs,
            'active' => $this->activeTab(),
            'askAudit' => $this->askAuditLink(),
        ]]);
        $html = $out . $this->display(__FILE__, 'views/templates/admin/configure.tpl') . $this->pane('overview') . $this->renderOverview() . $this->shareForm();
        foreach ($panes as $id => $content) {
            $html .= $this->pane($id) . $content;
        }

        return $html . $this->pane('');
    }

    /** @return SpcFeature[] the parts that make the shop faster, by id */
    private function speedUps()
    {
        $parts = $this->parts();
        unset($parts['behaviour']);

        return $parts;
    }

    /**
     * After an update: PrestaShop's combined CSS and JS are made again (they are named after
     * the list of files, not their content, so a changed stylesheet would not reach the shop),
     * and the page cache goes (its pages name the old combined files). Once per version.
     */
    public function assetsUpdated()
    {
        if (Configuration::get(self::K_ASSETS) === $this->version) {
            return false;
        }
        Configuration::updateValue(self::K_ASSETS, $this->version);
        Media::clearCache();
        if (class_exists('SpcPageCache')) {
            SpcPageCache::flush();
        }

        return true;
    }

    /** The marker that starts a tab's section ('' ends the last one). */
    private function pane($id)
    {
        $this->context->smarty->assign('spc_pane', $id);

        return $this->display(__FILE__, 'views/templates/admin/pane.tpl');
    }

    /** The tab to open: the one whose form was just sent, else the overview (or the address). */
    private function activeTab()
    {
        $forms = [
            'cache' => ['submitSpcCache', 'submitSpcBuiltin', 'submitSpcFlush', 'submitSpcOpcache'],
            'pagecache' => ['submitSpcPageCache', 'submitSpcPageCacheFlush'],
            'optimize' => ['submitSpcOptimize', 'submitSpcCriticalClear'],
            'smartprefetch' => ['submitsmartprefetch'],
            'instantnav' => ['submitinstantnav'],
            'instantcart' => ['submitInstantCart'],
            'cartspeed' => ['submitSpcCartSpeed'],
            'diagnostics' => ['submitSpcMultiFront'],
            'behaviour' => ['submitSpcBehaviour'],
            'reorder' => ['submitSpcReorder'],
            'overview' => ['submitSpcShare'],
        ];
        foreach ($forms as $tab => $submits) {
            foreach ($submits as $submit) {
                if (Tools::isSubmit($submit)) {
                    return $tab;
                }
            }
        }

        return $this->autoAudit ? 'audit' : '';
    }

    /** The one-click switches of the overview. */
    private function toggle($part)
    {
        $keys = ['pagecache' => SpcPageCache::K_ENABLED, 'optimize' => SpcOptimize::K_ENABLED, 'smartprefetch' => SpcSmartPrefetch::K_ENABLED, 'instantnav' => SpcInstantNav::K_ENABLED, 'instantcart' => SpcInstantCart::K_ENABLED, 'cartspeed' => self::K_CARTSPEED, 'behaviour' => SpcBehaviour::K_ENABLED, 'reorder' => SpcReorder::K_ENABLED];
        if (!isset($keys[$part])) {
            return '';
        }
        $now = $this->summaries()[$part]['on'];
        // saved for every shop, so a value kept for one shop cannot hold the switch where it was
        if (!SpcPageCache::set($keys[$part], $now ? 0 : 1)) {
            return $this->displayError($this->l('PrestaShop did not keep the switch: check that the module may change settings for this shop (multistore: all shops).'));
        }
        if (in_array($part, ['pagecache', 'optimize'], true)) {
            SpcPageCache::flush();
        }

        return $this->displayConfirmation($now ? $this->l('Switched off.') : $this->l('Switched on.'));
    }

    /** @return array id => summary of every part */
    private function summaries()
    {
        $out = [];
        foreach ($this->speedUps() as $id => $part) {
            $out[$id] = $part->summary();
        }
        $cs = (bool) Configuration::get(self::K_CARTSPEED);
        $out['cartspeed'] = ['on' => $cs, 'status' => $cs ? $this->l('On') : $this->l('Off'), 'fact' => $this->l('Address lookups of the cart page asked once, not 73 times.')];
        $out['diagnostics'] = $this->diagnostics->summary();
        $out['behaviour'] = $this->behaviour->summary();

        return $out;
    }

    private function renderOverview()
    {
        $this->context->controller->addCSS($this->getPathUri() . 'views/css/config.css');
        $this->context->controller->addJS($this->getPathUri() . 'views/js/config.js');
        $names = ['cache' => 'Cache', 'pagecache' => $this->pageCache->displayName, 'optimize' => $this->optimize->displayName, 'smartprefetch' => 'SmartPrefetch', 'instantnav' => 'InstantNav', 'instantcart' => 'InstantCart', 'cartspeed' => 'CartSpeed', 'diagnostics' => $this->diagnostics->displayName, 'behaviour' => $this->behaviour->displayName, 'reorder' => $this->reorder->displayName];
        $what = [
            'cache' => $this->l('Database results kept in Redis, APCu or Memcached.'),
            'pagecache' => $this->l('Whole pages ready for visitors.'),
            'optimize' => $this->l('Smaller pictures, lazy loading, critical CSS.'),
            'smartprefetch' => $this->l('The next page before the click.'),
            'instantnav' => $this->l('Menu clicks without a reload.'),
            'instantcart' => $this->l('The cart without waiting.'),
            'cartspeed' => $this->l('A lighter cart page.'),
            'diagnostics' => $this->l('The PrestaShop tuning guide, checked on this server.'),
            'behaviour' => $this->l('What shoppers do, page by page.'),
            'reorder' => $this->l('The last order again, in one tap.'),
        ];
        $cards = [];
        foreach ($this->summaries() as $id => $sum) {
            $cards[] = $sum + [
                'id' => $id,
                'name' => $names[$id],
                'what' => $what[$id],
                'switch' => in_array($id, ['pagecache', 'optimize', 'smartprefetch', 'instantnav', 'instantcart', 'cartspeed', 'reorder', 'behaviour'], true),
                'level' => isset($sum['level']) ? $sum['level'] : ($sum['on'] ? 'ok' : 'off'),
            ];
        }
        $this->context->smarty->assign('spc_overview', [
            'cards' => $cards,
            'url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'audit' => $this->gains(),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/overview.tpl');
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
                    ? SpcAudit::page($plan['pages'][$i]['url'], $plan['tokens'], $plan['enabled']['pagecache'])
                    : ['error' => 'no such page'];
                break;
            case 'optimize':
                // the home page and a product page (the plan lists the products last)
                $urls = [$plan['pages'][0]['url']];
                if (count($plan['pages']) > 1) {
                    $urls[] = $plan['pages'][count($plan['pages']) - 1]['url'];
                }
                $answer = SpcAudit::optimize($urls, $plan['tokens']);
                break;
            case 'cart':
                $answer = SpcAudit::cart($this->context, $plan['product'], $plan['tokens']['all']);
                break;
            case 'cartspeed':
                $answer = SpcAudit::cartSpeed($this->context);
                break;
            case 'save':
                $results = json_decode((string) Tools::getValue('results'), true);
                $answer = is_array($results) ? ['run' => SpcAudit::save($results, (bool) Tools::getValue('replace')), 'history' => SpcAudit::history()] : ['error' => 'nothing to save'];
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
            'slower' => $this->l('%sx slower'),
            'failed' => $this->l('Could not measure: %s'),
            'noCache' => $this->l('No data cache is chosen yet: pick Redis, APCu or Memcached in the Cache section.'),
            'pageCache' => $this->l('A page cache in front of the shop (a cache module, LiteSpeed, a CDN) answered instead of PrestaShop, so those pages could not be measured with and without SpeedPack. Let requests with the spc_audit cookie through it, or switch it off for the audit.'),
            'navCached' => $this->l('The shop window got the same page whatever the audit asked for – a page cache in front of the shop answered. The clicks were not counted. Let requests with the spc_audit cookie through it, or switch it off for the audit.'),
            'clickWithout' => $this->l('No speed-ups'),
            'clickSp' => $this->l('SmartPrefetch'),
            'clickNav' => $this->l('InstantNav'),
            'clickAll' => $this->l('All of SpeedPack'),
            'heroTitle' => $this->l('From click to page shown'),
            'historyTitle' => $this->l('Earlier audits'),
            'historyClick' => $this->l('Click to page, with SpeedPack'),
            'historyPage' => $this->l('Server answer, with SpeedPack'),
            'stopped' => $this->l('The audit stopped: %s'),
            'autoNote' => $this->l('SpeedPack Core was just installed or updated, so it is measuring this shop now.'),
            'clicksLater' => $this->l('The click test needs a shop window, which the browser opens only on a click: press "Measure the clicks too".'),
            'clicksNow' => $this->l('Measure the clicks too'),
            'optimizeStep' => $this->l('Optimize: the home page and a product page'),
            'pcMiss' => $this->l('The page cache did not answer these pages (a page type it does not keep, or a notice on the page), so there is nothing to compare yet.'),
            'files' => $this->l('%s files'),
            'file' => $this->l('%s file'),
            'fewerFiles' => $this->l('%s fewer files hold the page up'),
            'optNote' => $this->l('Scripts holding the page up: %1$s to %2$s. Pictures loaded at once: %3$s to %4$s. In WebP or AVIF: %5$s to %6$s. HTML: %7$s to %8$s KB.'),
            'optNoWebp' => $this->l('No picture is in WebP or AVIF yet: convert them in Optimize, Pictures.'),
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
            'auto' => $this->autoAudit,
        ]]);

        return $this->display(__FILE__, 'views/templates/admin/audit.tpl');
    }

    /* ------------------------------------------------------------------ *
     *  Sharing: a custom audit, the footer credit, llms.txt
     * ------------------------------------------------------------------ */

    /** A link to the SpeedPack Core site, tagged with where it was placed. */
    public function siteLink($medium)
    {
        $host = (string) Tools::getHttpHost(false, false, true);

        return self::SITE . '?' . http_build_query([
            'utm_source' => 'speedpackcore',
            'utm_medium' => $medium,
            'utm_campaign' => 'module-' . $this->version,
            'utm_content' => $host,
        ]);
    }

    /** The last audit as "× faster" figures (null where not measured). */
    public function gains()
    {
        $history = SpcAudit::history();
        $last = $history ? $history[count($history) - 1] : null;
        $gain = function ($pair, $before, $after) {
            if (!is_array($pair) || empty($pair[$after]) || empty($pair[$before]) || $pair[$before] <= $pair[$after]) {
                return null;
            }

            return round($pair[$before] / $pair[$after], 1);
        };

        return $last ? [
            'at' => $last['at'],
            'clicks' => $gain(isset($last['nav']) ? $last['nav'] : null, 'off', 'all'),
            'pages' => $gain(isset($last['pages']) ? $last['pages'] : null, 'off', 'on'),
            'pagecache' => $gain(isset($last['pagecache']) ? $last['pagecache'] : null, 'off', 'on'),
            'cart' => $gain(isset($last['cart']) ? $last['cart'] : null, 'core', 'lean'),
        ] : null;
    }

    /**
     * "Ask for a custom audit of my site": an e-mail to the author, written out with what an
     * audit needs to start (the shop, its versions, the last measurements).
     */
    private function askAuditLink()
    {
        $g = $this->gains();
        $lines = [
            'Hello,',
            '',
            'I would like a custom speed audit of my shop.',
            '',
            'Shop: ' . $this->context->shop->getBaseURL(true),
            'PrestaShop ' . _PS_VERSION_ . ', PHP ' . PHP_VERSION . ', SpeedPack Core ' . $this->version,
            'Theme: ' . $this->context->shop->theme_name,
            'Data cache: ' . (Configuration::get(SpcCache::K_BACKEND) ?: 'none'),
        ];
        if ($g) {
            $lines[] = 'Last speed audit (' . $g['at'] . '): clicks ' . ($g['clicks'] ?: '-') . 'x, server ' . ($g['pages'] ?: '-') . 'x, add to cart ' . ($g['cart'] ?: '-') . 'x faster';
        }
        $lines[] = 'Health check: ' . $this->diagnostics->summary()['fact'];
        $lines[] = '';
        $lines[] = 'What I would like checked:';
        $lines[] = '';

        return 'mailto:' . self::AUDIT_EMAIL . '?subject=' . rawurlencode('Custom speed audit: ' . Tools::getHttpHost(false, false, true))
            . '&body=' . rawurlencode(implode("\n", $lines));
    }

    /** Opt-in sharing: the footer credit and the llms.txt section, both off until switched on. */
    private function shareForm()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitSpcShare';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [self::K_CREDIT => (int) Configuration::get(self::K_CREDIT), self::K_LLMS => (int) Configuration::get(self::K_LLMS)];
        $switch = function ($name, $label, $desc) {
            return ['type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]], ];
        };

        return $helper->generateForm([['form' => [
            'id_form' => 'spc-share',
            'legend' => ['title' => $this->l('Share the speed'), 'icon' => 'icon-heart'],
            'description' => $this->l('Optional, and off unless you switch it on. Each helps other shop owners find SpeedPack Core.'),
            'input' => [
                $switch(self::K_CREDIT, $this->l('Credit in the shop footer'), $this->l('One small line in the footer: "Fast pages: SpeedPack Core by Mateusz Stelmasiak", with your measured speed-up when there is one. A normal visible link, marked nofollow so it never affects your search ranking.')),
                $switch(self::K_LLMS, $this->l('Mention in llms.txt'), $this->l('Adds a short "Site performance" section with your measured speed-up to the llms.txt that a llms.txt module generates (one that offers the displayLlmsTxt hook).')),
            ],
            'submit' => ['title' => $this->l('Save')],
            'buttons' => [['type' => 'submit', 'name' => 'submitSpcLlmsNow', 'title' => $this->l('Save and update llms.txt now'), 'icon' => 'process-icon-refresh', 'class' => 'pull-left']],
        ]]]);
    }

    /**
     * llms.txt brought up to date now. A llms.txt module that can generate on demand does it (and
     * takes this section through displayLlmsTxt); otherwise, or when its file came out without the
     * section, the file in the shop's root keeps everything it has and only this section is put in
     * at its end (an earlier copy of it replaced, never the rest of the file).
     */
    private function llmsNow()
    {
        $generator = null;
        foreach (Hook::getHookModuleExecList('displayLlmsTxt') ?: [] as $row) {
            $m = Module::getInstanceByName($row['module']);
            if ($m && $m->name !== $this->name && method_exists($m, 'generateNow') && Module::isEnabled($m->name)) {
                $generator = $m;
                break;
            }
        }
        if (!$generator) {
            foreach (['ps_llms_generator', 'llmstxt', 'llms_txt'] as $name) {
                $m = Module::isInstalled($name) && Module::isEnabled($name) ? Module::getInstanceByName($name) : null;
                if ($m && method_exists($m, 'generateNow')) {
                    $generator = $m;
                    break;
                }
            }
        }
        $by = '';
        if ($generator) {
            try {
                $report = $generator->generateNow();
                if (is_array($report) && isset($report['ok']) && !$report['ok']) {
                    return $this->displayError(sprintf($this->l('%1$s could not make llms.txt: %2$s'), $generator->displayName, isset($report['error']) ? (string) $report['error'] : '?'));
                }
                $by = $generator->displayName;
            } catch (Throwable $e) {
                return $this->displayError(sprintf($this->l('%1$s could not make llms.txt: %2$s'), $generator->displayName, $e->getMessage()));
            }
        }
        $done = self::llmsMerge(rtrim(_PS_ROOT_DIR_, '/') . '/llms.txt', (string) $this->hookDisplayLlmsTxt([]), Configuration::get('PS_SHOP_NAME'));
        if ($done === false) {
            return $this->displayError($this->l('llms.txt in the shop folder cannot be written by the web server.'));
        }
        $url = $this->context->shop->getBaseURL(true) . 'llms.txt';

        return $this->displayConfirmation(($by ? sprintf($this->l('llms.txt made again by %s.'), $by) . ' ' : '')
            . ((int) Configuration::get(self::K_LLMS) ? $this->l('The "Site performance" section is in it, at the end; the rest of the file is kept.') : $this->l('The "Site performance" section is not in it (the switch is off).'))
            . ' ' . $url);
    }

    /**
     * Puts $section ("## Site performance" and its lines) at the end of the llms.txt at $file,
     * keeping the rest; an earlier copy of the section is taken out first. With no section, only
     * takes it out. A file that is not there is started with the shop's name.
     *
     * @return bool|string false when the file cannot be written, else its new content
     */
    public static function llmsMerge($file, $section, $shopName = '')
    {
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        // the earlier copy: from its heading to the next heading of the same level, or the end
        $text = preg_replace('/(^|\n)## Site performance\n.*?(?=\n## |\z)/s', '$1', $text);
        $text = rtrim(preg_replace('/\n{3,}/', "\n\n", $text));
        $section = trim($section);
        if ($text === '' && $section === '') {
            return is_file($file) ? (@file_put_contents($file, '') !== false ? '' : false) : '';
        }
        if ($text === '') {
            $text = '# ' . ($shopName ?: 'Shop');
        }
        $text .= ($section !== '' ? "\n\n" . $section : '') . "\n";
        if ((is_file($file) && !is_writable($file)) || (!is_file($file) && !is_writable(dirname($file)))) {
            return false;
        }

        return @file_put_contents($file, $text) !== false ? $text : false;
    }

    public function hookDisplayFooter($params)
    {
        if (!(int) Configuration::get(self::K_CREDIT) || SpcAudit::parts() !== null) {
            return '';
        }
        $g = $this->gains();
        $this->context->smarty->assign('spc_credit', [
            'url' => $this->siteLink('footer'),
            'author' => self::AUTHOR,
            'author_url' => self::AUTHOR_URL,
            'clicks' => $g ? $g['clicks'] : null,
            'pages' => $g ? ($g['pagecache'] ?: $g['pages']) : null,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/credit.tpl');
    }

    /** A "Site performance" section for the shop's llms.txt (Markdown). */
    public function hookDisplayLlmsTxt($params)
    {
        if (!(int) Configuration::get(self::K_LLMS)) {
            return '';
        }
        $g = $this->gains();
        $out = "## Site performance\n\n"
            . '- This shop runs [SpeedPack Core](' . $this->siteLink('llms') . ') for PrestaShop, by [' . self::AUTHOR . '](' . self::AUTHOR_URL . '): pages kept ready, database results from memory, pages fetched before the click, menu clicks without a reload and an instant cart.' . "\n";
        if ($g) {
            $facts = [];
            foreach (['clicks' => 'from click to page shown %sx faster', 'pagecache' => 'pages served ready %sx faster', 'pages' => 'server answers %sx faster', 'cart' => 'add to cart %sx faster'] as $k => $text) {
                if ($g[$k]) {
                    $facts[] = sprintf($text, $g[$k]);
                }
            }
            if ($facts) {
                $out .= '- Measured on this shop with and without it (' . substr($g['at'], 0, 10) . '): ' . implode(', ', $facts) . ".\n";
            }
        }

        return $out;
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
