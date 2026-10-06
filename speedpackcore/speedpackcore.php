<?php
/**
 * SpeedPack Core - four speed-ups for PrestaShop in one module.
 *
 *   SmartPrefetch  fetches the next page while the pointer rests on a link
 *   InstantNav     menu clicks swap the page content instead of reloading the page
 *   InstantCart    add to cart answers at once; quick clicks become one request
 *   CartSpeed      remembers address lookups for the page (an Address override)
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

class SpeedPackCore extends Module
{
    public const K_CARTSPEED = 'SPC_CS_ENABLED';

    /** the version whose settings page was last opened: a new one runs the speed audit by itself */
    public const K_SEEN = 'SPC_SEEN_VERSION';

    /** opt-in: a small visible credit in the shop footer, and a section in the shop's llms.txt */
    public const K_CREDIT = 'SPC_CREDIT';
    public const K_LLMS = 'SPC_LLMS';

    public const SITE = 'https://github.com/mateusz-stelmasiak/Prestashop-SpeedPackCore';
    public const AUDIT_EMAIL = 'mateusz.stelmasiak@gmail.com';

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

    /** @var SpcBehaviour */
    private $behaviour;

    /** @var bool the settings page of a version opened for the first time */
    private $autoAudit = false;

    public function __construct()
    {
        $this->name = 'speedpackcore';
        $this->tab = 'front_office_features';
        $this->version = '1.5.0';
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
        $this->behaviour = new SpcBehaviour($this, $this->context, $this->l('Behaviour'));
    }

    /** @return SpcFeature[] by id */
    public function parts()
    {
        return [
            'cache' => $this->cache,
            'smartprefetch' => $this->smartPrefetch,
            'instantnav' => $this->instantNav,
            'instantcart' => $this->instantCart,
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
        foreach ([SpcAudit::K_KEY, SpcAudit::K_HISTORY, SpcAudit::K_DONE, self::K_SEEN, self::K_CREDIT, self::K_LLMS] as $key) {
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
            && $this->registerHook('displayLlmsTxt');
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
    }

    public function hookActionFrontControllerSetMedia($params)
    {
        // a second chance for a shop upgraded without visiting the settings page (the dispatcher
        // hook not registered yet): still before the page's content is built
        SpcAudit::apply();
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
        if (Tools::getValue('spc_ajax') === 'audit') {
            $this->ajaxAudit((string) Tools::getValue('step'));
        }
        $out = '';
        // a new version (installed or updated): the speed audit runs by itself, to show what it does
        $this->autoAudit = Configuration::get(self::K_SEEN) !== $this->version;
        if ($this->autoAudit) {
            Configuration::updateValue(self::K_SEEN, $this->version);
        }
        foreach (['actionDispatcherBefore', 'actionFrontControllerSetMedia', 'displayHeader', 'displayProductListReviews', 'actionValidateOrder', 'displayFooter', 'displayLlmsTxt'] as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $this->registerHook($hook);
            }
        }
        if (Tools::isSubmit('submitSpcCartSpeed')) {
            Configuration::updateValue(self::K_CARTSPEED, Tools::getValue(self::K_CARTSPEED) ? 1 : 0);
            $out .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        if (Tools::isSubmit('submitSpcShare')) {
            Configuration::updateValue(self::K_CREDIT, Tools::getValue(self::K_CREDIT) ? 1 : 0);
            Configuration::updateValue(self::K_LLMS, Tools::getValue(self::K_LLMS) ? 1 : 0);
            $out .= $this->displayConfirmation($this->l('Settings updated.'));
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
            'smartprefetch' => ['submitsmartprefetch'],
            'instantnav' => ['submitinstantnav'],
            'instantcart' => ['submitInstantCart'],
            'cartspeed' => ['submitSpcCartSpeed'],
            'diagnostics' => ['submitSpcMultiFront'],
            'behaviour' => ['submitSpcBehaviour'],
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
        $keys = ['smartprefetch' => SpcSmartPrefetch::K_ENABLED, 'instantnav' => SpcInstantNav::K_ENABLED, 'instantcart' => SpcInstantCart::K_ENABLED, 'cartspeed' => self::K_CARTSPEED, 'behaviour' => SpcBehaviour::K_ENABLED];
        if (!isset($keys[$part])) {
            return '';
        }
        $now = $this->summaries()[$part]['on'];
        Configuration::updateValue($keys[$part], $now ? 0 : 1);

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
        $names = ['cache' => 'Cache', 'smartprefetch' => 'SmartPrefetch', 'instantnav' => 'InstantNav', 'instantcart' => 'InstantCart', 'cartspeed' => 'CartSpeed', 'diagnostics' => $this->diagnostics->displayName, 'behaviour' => $this->behaviour->displayName];
        $what = [
            'cache' => $this->l('Database results kept in Redis, APCu or Memcached.'),
            'smartprefetch' => $this->l('The next page before the click.'),
            'instantnav' => $this->l('Menu clicks without a reload.'),
            'instantcart' => $this->l('The cart without waiting.'),
            'cartspeed' => $this->l('A lighter cart page.'),
            'diagnostics' => $this->l('The PrestaShop tuning guide, checked on this server.'),
            'behaviour' => $this->l('What shoppers do, page by page.'),
        ];
        $cards = [];
        foreach ($this->summaries() as $id => $sum) {
            $cards[] = $sum + [
                'id' => $id,
                'name' => $names[$id],
                'what' => $what[$id],
                'switch' => in_array($id, ['smartprefetch', 'instantnav', 'instantcart', 'cartspeed', 'behaviour'], true),
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
                $switch(self::K_CREDIT, $this->l('Credit in the shop footer'), $this->l('One small line in the footer: "Fast pages: SpeedPack Core", with your measured speed-up when there is one. A normal visible link, marked nofollow so it never affects your search ranking.')),
                $switch(self::K_LLMS, $this->l('Mention in llms.txt'), $this->l('Adds a short "Site performance" section with your measured speed-up to the llms.txt that a llms.txt module generates (one that offers the displayLlmsTxt hook).')),
            ],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }

    public function hookDisplayFooter($params)
    {
        if (!(int) Configuration::get(self::K_CREDIT) || SpcAudit::parts() !== null) {
            return '';
        }
        $g = $this->gains();
        $this->context->smarty->assign('spc_credit', [
            'url' => $this->siteLink('footer'),
            'clicks' => $g ? $g['clicks'] : null,
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
            . '- This shop runs [SpeedPack Core](' . $this->siteLink('llms') . ') for PrestaShop: database results from memory, pages fetched before the click, menu clicks without a reload and an instant cart.' . "\n";
        if ($g) {
            $facts = [];
            foreach (['clicks' => 'from click to page shown %sx faster', 'pages' => 'server answers %sx faster', 'cart' => 'add to cart %sx faster'] as $k => $text) {
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
