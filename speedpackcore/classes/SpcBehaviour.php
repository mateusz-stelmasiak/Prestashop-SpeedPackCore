<?php
/**
 * SpeedPack Core - Behaviour: what shoppers do on the shop, page by page.
 *
 * A small script (views/js/behaviour.js) reports each page shown – InstantNav swaps included,
 * prerendered pages only once they are really shown – with its engaged time and scroll depth,
 * and what happened there: add to cart, checkout steps, the pay button, errors, empty searches.
 * The collect controller stores it (classes/SpcBehaviourStore.php); the Behaviour tab asks
 * questions of it: time on each page, routes, the paths that end in an order, where carts are
 * left, all cut into time buckets and searchable.
 *
 * No cookie of its own: a visit is tied together through the shop's own session cookie on the
 * server. No IP address or browser string is kept. Customer accounts are linked only when the
 * shop owner switches that on.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SpcBehaviourStore.php';

class SpcBehaviour extends SpcFeature
{
    public $id = 'behaviour';

    public const K_ENABLED = 'SPC_BH_ENABLED';
    public const K_CONSENT = 'SPC_BH_CONSENT';
    public const K_CUSTOMER = 'SPC_BH_CUSTOMER';
    public const K_KEEP = 'SPC_BH_KEEP';

    public const JS = 'views/js/behaviour.js';
    public const JS_MIN = 'views/js/behaviour.min.js';

    public function install()
    {
        // off until the shop owner switches it on: recording visitors is their decision
        return Configuration::updateValue(self::K_ENABLED, 0)
            && Configuration::updateValue(self::K_CONSENT, 0)
            && Configuration::updateValue(self::K_CUSTOMER, 0)
            && Configuration::updateValue(self::K_KEEP, 90)
            && SpcBehaviourStore::install()
            && $this->registerHook('actionValidateOrder');
    }

    public function uninstall()
    {
        foreach ([self::K_ENABLED, self::K_CONSENT, self::K_CUSTOMER, self::K_KEEP] as $k) {
            Configuration::deleteByName($k);
        }

        return SpcBehaviourStore::uninstall();
    }

    public static function enabled()
    {
        return (int) Configuration::get(self::K_ENABLED) === 1;
    }

    /** Days visits are kept. */
    public static function keep()
    {
        $days = (int) Configuration::get(self::K_KEEP);

        return $days >= 1 && $days <= 730 ? $days : 90;
    }

    public function summary()
    {
        $on = self::enabled();
        $fact = $this->l('Time on each page, routes, paths to an order and where carts are left.');
        if ($on) {
            try {
                $r = Db::getInstance()->getRow('SELECT COUNT(*) n, COALESCE(SUM(ordered_at > 0), 0) o FROM ' . SpcBehaviourStore::table('session')
                    . ' WHERE id_shop = ' . (int) $this->context->shop->id . ' AND started >= ' . (time() - 7 * 86400));
                if ($r && (int) $r['n'] > 0) {
                    $fact = sprintf($this->l('%1$d visits in 7 days, %2$s%% ended in an order.'), (int) $r['n'], round(100 * $r['o'] / $r['n'], 1));
                }
            } catch (Exception $e) {
                // tables not there yet: the settings page creates them
            }
        }

        return ['on' => $on, 'status' => $on ? $this->l('Recording') : $this->l('Off'), 'fact' => $fact];
    }

    /* ------------------------------------------------------------------ *
     *  Shop
     * ------------------------------------------------------------------ */

    public function hookActionFrontControllerSetMedia()
    {
        // the speed audit's own requests are never visitors (SpcAudit::off covers this part too)
        if (!self::enabled() || SpcAudit::parts() !== null) {
            return;
        }
        $controller = $this->context->controller;
        Media::addJsDef(['spcBehaviour' => [
            'url' => $this->context->link->getModuleLink($this->name, 'collect', [], true),
            'consent' => (int) Configuration::get(self::K_CONSENT),
        ]]);
        $min = $this->dir() . '/' . self::JS_MIN;
        $asset = is_file($min) && filesize($min) > 0 ? self::JS_MIN : self::JS;
        $controller->registerJavascript('spc-behaviour', 'modules/' . $this->name . '/' . $asset, ['position' => 'bottom', 'priority' => 200, 'attributes' => 'defer']);
    }

    /** The visit that placed an order, with its total (when the shopper's own request validates it). */
    public function hookActionValidateOrder($params)
    {
        if (!self::enabled() || empty($params['order'])) {
            return;
        }
        try {
            // an order validated without the shopper's session (a payment notification) has no visit
            $id = (int) $this->context->cookie->__get('spc_bs');
            if ($id > 0) {
                $order = $params['order'];
                SpcBehaviourStore::ordered($id, (int) $order->id, (float) $order->total_paid_tax_incl, time());
            }
        } catch (Throwable $e) {
            // never in the way of an order
        }
    }

    /** What the collect controller hands the store about the visitor. */
    public function env()
    {
        $context = $this->context;
        $idCustomer = Validate::isLoadedObject($context->customer) && $context->customer->isLogged() ? (int) $context->customer->id : 0;

        return [
            'id_shop' => (int) $context->shop->id,
            'host' => Tools::getHttpHost(false, false, true),
            'id_customer' => (int) Configuration::get(self::K_CUSTOMER) ? $idCustomer : 0,
            // a shopper who has ordered before: the case for a faster repeat order
            'returning' => function () use ($idCustomer) {
                return $idCustomer && (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE valid = 1 AND id_customer = ' . $idCustomer) > 0;
            },
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function ajax()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;
        try {
            if (Tools::getValue('op') === 'session') {
                $answer = SpcBehaviourStore::session((int) Tools::getValue('id'), $idShop, $idLang);
            } else {
                $f = [];
                foreach (['from', 'to', 'bucket', 'device', 'source', 'outcome', 'returning', 'q'] as $k) {
                    $f[$k] = Tools::getValue($k, '');
                }
                $answer = SpcBehaviourStore::report($f, $idShop, $idLang, time());
            }
        } catch (Exception $e) {
            $answer = ['error' => $e->getMessage()];
        }
        echo json_encode($answer);
        exit;
    }

    public function getContent()
    {
        $out = '';
        // a zip uploaded over an older version may not have run the upgrade
        SpcBehaviourStore::install();
        if (!$this->isRegisteredInHook('actionValidateOrder')) {
            $this->registerHook('actionValidateOrder');
        }
        if (Tools::isSubmit('submitSpcBehaviour')) {
            $keep = (int) Tools::getValue(self::K_KEEP);
            if ($keep < 1 || $keep > 730) {
                $out .= $this->displayError($this->l('Keep visits for 1 to 730 days.'));
            } else {
                Configuration::updateValue(self::K_ENABLED, Tools::getValue(self::K_ENABLED) ? 1 : 0);
                Configuration::updateValue(self::K_CONSENT, Tools::getValue(self::K_CONSENT) ? 1 : 0);
                Configuration::updateValue(self::K_CUSTOMER, Tools::getValue(self::K_CUSTOMER) ? 1 : 0);
                Configuration::updateValue(self::K_KEEP, $keep);
                $out .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        SpcBehaviourStore::purge(self::keep(), time());

        $this->context->controller->addCSS($this->module->getPathUri() . 'views/css/behaviour.css');
        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/behaviour-admin.js');
        $out .= $this->render('admin/behaviour.tpl', ['spc_bh' => [
            'enabled' => self::enabled(),
            'url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&spc_ajax=behaviour',
            'texts' => json_encode($this->texts()),
            'customers' => (bool) Configuration::get(self::K_CUSTOMER),
        ]]);

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
        $helper->submit_action = 'submitSpcBehaviour';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            self::K_ENABLED => self::enabled() ? 1 : 0,
            self::K_CONSENT => (int) Configuration::get(self::K_CONSENT),
            self::K_CUSTOMER => (int) Configuration::get(self::K_CUSTOMER),
            self::K_KEEP => self::keep(),
        ];

        return $out . $helper->generateForm([['form' => [
            'id_form' => 'spc-behaviour',
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-bar-chart'],
            'description' => $this->l('Records what shoppers do: each page shown with its engaged time (visible and in use) and scroll depth, add to cart, checkout steps, the pay button, errors and empty searches. No cookie of its own, no IP address, no browser string; visits are tied together through the shop session. Search engines and the speed audit are not recorded.'),
            'input' => [
                $switch(self::K_ENABLED, $this->l('Record visits'), $this->l('Adds a small script (about 3 KB gzipped) to every shop page.')),
                $switch(self::K_CONSENT, $this->l('Only after analytics consent'), $this->l('Records a visitor only once a consent banner allows analytics: Google Consent Mode (analytics_storage granted), or window.spcBehaviourConsent = true, or the event spc:consent on document.')),
                $switch(self::K_CUSTOMER, $this->l('Link visits to customer accounts'), $this->l('Keeps the account of a signed-in shopper with the visit, so visits can be searched by customer (customer:ID or an e-mail address). Off: only whether the shopper has ordered before is kept.')),
                ['type' => 'text', 'name' => self::K_KEEP, 'label' => $this->l('Keep visits for'), 'suffix' => $this->l('days'), 'class' => 'fixed-width-sm', 'desc' => $this->l('Older visits are deleted.')],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }

    protected function texts()
    {
        return [
            'off' => $this->l('Recording is off. Switch it on below; the first visits show up here within a minute.'),
            'empty' => $this->l('No visits match.'),
            'noMatch' => $this->l('Nothing on the shop matches "%s".'),
            'loading' => $this->l('Loading...'),
            'error' => $this->l('Could not load the report: %s'),
            'range' => ['1' => $this->l('Last 24 hours'), '7' => $this->l('Last 7 days'), '30' => $this->l('Last 30 days'), '90' => $this->l('Last 90 days')],
            'bucket' => ['0' => $this->l('Auto'), '900' => $this->l('15 minutes'), '3600' => $this->l('Hour'), '86400' => $this->l('Day'), '604800' => $this->l('Week')],
            'device' => ['' => $this->l('All devices'), 'mobile' => $this->l('Phones'), 'tablet' => $this->l('Tablets'), 'desktop' => $this->l('Computers')],
            'source' => ['' => $this->l('All sources'), 'direct' => $this->l('Direct'), 'search' => $this->l('Search engines'), 'social' => $this->l('Social'), 'email' => $this->l('E-mail'), 'ads' => $this->l('Ads'), 'other' => $this->l('Other sites')],
            'outcome' => ['' => $this->l('All visits'), 'ordered' => $this->l('Ordered'), 'abandoned' => $this->l('Cart left'), 'browsing' => $this->l('Browsed only'), 'bounced' => $this->l('One page')],
            'returning' => ['' => $this->l('New and returning'), '0' => $this->l('New shoppers'), '1' => $this->l('Ordered before')],
            'search' => $this->l('Search visits: a product, category, page or address; "Kimchi > koszyk" for one after the other'),
            'kpi' => [
                'sessions' => $this->l('Visits'), 'views' => $this->l('Pages'), 'perSession' => $this->l('Pages per visit'),
                'engaged' => $this->l('Engaged time (median)'), 'bounce' => $this->l('One page only'), 'cart' => $this->l('Added to cart'),
                'conversion' => $this->l('Ordered'), 'returning' => $this->l('Ordered before'), 'live' => $this->l('On the shop now'),
            ],
            'timeline' => $this->l('Visits over time'),
            'series' => ['sessions' => $this->l('Visits'), 'carts' => $this->l('With cart'), 'orders' => $this->l('Orders')],
            'pages' => $this->l('Pages'),
            'pageCols' => [$this->l('Page'), $this->l('Views'), $this->l('Avg. engaged'), $this->l('Scroll'), $this->l('Entries'), $this->l('Exit rate'), $this->l('Visits that ordered')],
            'dwell' => $this->l('Time on a page'),
            'routes' => $this->l('Most taken routes'),
            'routeShare' => $this->l('%s%% of its views'),
            'paths' => $this->l('Most common paths (first 6 pages)'),
            'success' => $this->l('Paths of success: the pages before an order'),
            'toOrder' => $this->l('Median %1$s and %2$s pages to an order'),
            'cartToOrder' => $this->l('Cart to order: new shoppers %1$s, shoppers who ordered before %2$s'),
            'funnel' => $this->l('Funnel'),
            'steps' => [
                'sessions' => $this->l('Visits'), 'product' => $this->l('Saw a product'), 'cart' => $this->l('Added to cart'), 'checkout' => $this->l('Started checkout'),
                'personal' => $this->l('Personal details'), 'addresses' => $this->l('Address'), 'delivery' => $this->l('Delivery'), 'payment' => $this->l('Payment'),
                'pay' => $this->l('Pressed pay'), 'ordered' => $this->l('Ordered'),
            ],
            'failures' => $this->l('Failure points'),
            'abandoned' => $this->l('Where carts were left (last page)'),
            'emptySearch' => $this->l('Searches with no results'),
            'notFound' => $this->l('Pages not found (404)'),
            'errors' => $this->l('Errors shown to shoppers'),
            'none' => $this->l('None'),
            'sessions' => $this->l('Visits'),
            'sessionsOf' => $this->l('%1$d most recent of %2$d'),
            'outcomes' => ['ordered' => $this->l('Ordered'), 'checkout' => $this->l('Left in checkout'), 'cart' => $this->l('Left the cart'), 'browsing' => $this->l('Browsed'), 'bounced' => $this->l('One page')],
            'returningShopper' => $this->l('ordered before'),
            'customer' => $this->l('customer #%s'),
            'nav' => [$this->l('loaded'), $this->l('InstantNav'), $this->l('back')],
            'events' => ['reorder' => $this->l('repeated the last order'), 'cart' => $this->l('added to cart'), 'step' => $this->l('checkout step: %s'), 'pay' => $this->l('pressed pay'), 'error' => $this->l('error: %s'), 'search' => $this->l('searched "%1$s": %2$s results')],
            'sample' => $this->l('Paths and funnel from the %1$d most recent of %2$d visits.'),
            'types' => [
                'index' => $this->l('Home'), 'category' => $this->l('Category'), 'product' => $this->l('Product'), 'cms' => $this->l('Page'),
                'cart' => $this->l('Cart'), 'checkout' => $this->l('Checkout'), 'order' => $this->l('Checkout'), 'order-confirmation' => $this->l('Order confirmed'),
                'search' => $this->l('Search'), 'pagenotfound' => $this->l('Not found'), 'my-account' => $this->l('Account'), 'authentication' => $this->l('Sign in'),
                'registration' => $this->l('Sign up'), 'contact' => $this->l('Contact'), 'manufacturer' => $this->l('Brand'), 'supplier' => $this->l('Supplier'),
                'new-products' => $this->l('New products'), 'prices-drop' => $this->l('Price drops'), 'best-sales' => $this->l('Best sellers'), 'history' => $this->l('Order history'),
                'order-detail' => $this->l('Order details'), 'module' => $this->l('Module page'), '…' => '…',
            ],
            'vitals' => $this->l('Core Web Vitals, as shoppers got them'),
            'vitalNames' => ['lcp' => $this->l('Largest paint (LCP)'), 'inp' => $this->l('Response to a tap (INP)'), 'cls' => $this->l('Layout shift (CLS)'), 'ttfb' => $this->l('Server answer (TTFB)'), 'fcp' => $this->l('First paint (FCP)')],
            'ratings' => ['good' => $this->l('Good'), 'ni' => $this->l('Needs improvement'), 'poor' => $this->l('Poor')],
            'vitalsNote' => $this->l('75th percentile of %d page views, the figure Google judges a page by. Chrome and Edge report them; InstantNav swaps have no LCP of their own.'),
            'vitalsNone' => $this->l('No measurements yet: Chrome and Edge report them as shoppers browse.'),
            'minutes' => $this->l('%s min'), 'seconds' => $this->l('%s s'), 'hours' => $this->l('%s h'),
            'close' => $this->l('Close'),
        ];
    }
}
