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

    /** the shopper's path on each order and cart in the back office */
    public const K_PATHS = 'SPC_BH_PATHS';

    public const JS = 'views/js/behaviour.js';
    public const JS_MIN = 'views/js/behaviour.min.js';

    public function install()
    {
        // off until the shop owner switches it on: recording visitors is their decision
        return Configuration::updateValue(self::K_ENABLED, 0)
            && Configuration::updateValue(self::K_PATHS, 1)
            && Configuration::updateValue(self::K_CONSENT, 0)
            && Configuration::updateValue(self::K_CUSTOMER, 0)
            && Configuration::updateValue(self::K_KEEP, 90)
            && SpcBehaviourStore::install()
            && $this->registerHooks();
    }

    public function registerHooks()
    {
        return $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayAdminOrderMain')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        foreach ([self::K_ENABLED, self::K_CONSENT, self::K_CUSTOMER, self::K_KEEP, self::K_PATHS] as $k) {
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
        if (!self::enabled() || SpcAudit::parts() !== null || SpcWarm::isWarmRequest()) {
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

    /** The path on orders and carts: on unless switched off (a setting never saved counts as on). */
    public static function pathsOn()
    {
        $v = Configuration::get(self::K_PATHS);

        return $v === false || (int) $v === 1;
    }

    /* ------------------------------------------------------------------ *
     *  The shopper's path on an order or a cart
     * ------------------------------------------------------------------ */

    /** The panel for an order or a cart page: every visit behind it, page by page. */
    public function journeyPanel($idCart, $idOrder, $where)
    {
        $data = $this->journeyData($idCart, $idOrder, $where);

        return $data ? $this->render('admin/journey.tpl', ['spc_journey' => $data]) : '';
    }

    /** What the panel shows, or null when there is nothing to show. */
    public function journeyData($idCart, $idOrder, $where)
    {
        if (!self::pathsOn()) {
            return null;
        }
        try {
            $j = SpcBehaviourStore::journey((int) $this->context->shop->id, (int) $this->context->language->id, (int) $idCart, (int) $idOrder);
        } catch (Exception $e) {
            return null;
        }
        if (!$j && !self::enabled()) {
            return null;
        }

        return $j ? $this->present($j, $where) : [
            'empty' => true, 'where' => $where, 'card' => $this->cardLayout(), 'css' => $this->module->getPathUri() . 'views/css/journey.css',
            't' => ['title' => $where === 'order' ? $this->l('Path to this order') : $this->l('Path to this cart'), 'none' => $this->l('No visit was recorded for it: it was made before Behaviour recorded visits, or without the browser reporting (an app, a phone order).')],
        ];
    }

    /** Bootstrap 4 cards on the new order page (1.7.7+) and on PrestaShop 9, panels before. */
    protected function cardLayout()
    {
        return version_compare(_PS_VERSION_, '1.7.7.0', '>=');
    }

    /** Everything the template shows, written out: dates, durations, names, event lines. */
    protected function present(array $j, $where)
    {
        $t = $this->texts();
        // one visit, one device and one source: the singular names
        $t['device'] = ['mobile' => $this->l('Phone'), 'tablet' => $this->l('Tablet'), 'desktop' => $this->l('Computer')];
        $t['source'] = ['direct' => $this->l('Direct'), 'search' => $this->l('Search engine'), 'social' => $this->l('Social media'), 'email' => $this->l('E-mail'), 'ads' => $this->l('Ad'), 'other' => $this->l('Another site')];
        $labels = $j['labels'];
        $name = function ($key) use ($labels, $t) {
            $type = explode(':', $key)[0];
            $typeName = isset($t['types'][$type]) ? $t['types'][$type] : $type;
            if (isset($labels[$key])) {
                return ['name' => $labels[$key], 'type' => $typeName, 'kind' => $type];
            }
            $id = strpos($key, ':') !== false ? ' #' . explode(':', $key)[1] : '';

            return ['name' => $typeName . $id, 'type' => '', 'kind' => $type];
        };
        $visits = [];
        foreach ($j['visits'] as $i => $v) {
            $pages = [];
            foreach (array_slice($v['views'], 0, 40) as $view) {
                $events = [];
                $steps = [];
                $stepAt = 0;
                foreach ($view['events'] as $e) {
                    // the checkout steps read as one line: Personal details › Address › Delivery
                    if ($e['type'] === 'step') {
                        $steps[] = isset($t['steps'][$e['detail']]) ? $t['steps'][$e['detail']] : $e['detail'];
                        if (count($steps) === 1) {
                            $events[] = ['kind' => 'step', 'text' => ''];
                            $stepAt = count($events) - 1;
                        }
                        continue;
                    }
                    $events[] = $this->eventLine($e, $t);
                }
                if ($steps) {
                    $events[$stepAt]['text'] = implode(' › ', $steps);
                }
                $pages[] = $name($view['page']) + [
                    'time' => $view['activeMs'] > 0 ? $this->duration($view['activeMs']) : '',
                    'url' => $view['url'], 'at' => date('H:i', $view['at']), 'events' => $events,
                ];
            }
            $visits[] = [
                'n' => $i + 1,
                'label' => sprintf($this->l('Visit %d'), $i + 1),
                'date' => $this->day($v['started']) . ' ' . date('H:i', $v['started']),
                'device' => isset($t['device'][$v['device']]) ? $t['device'][$v['device']] : $v['device'],
                'deviceKind' => $v['device'],
                'source' => (isset($t['source'][$v['source']]) ? $t['source'][$v['source']] : $v['source']) . ($v['ref'] !== '' ? ' (' . $v['ref'] . ')' : '') . ($v['campaign'] !== '' ? ' · ' . $v['campaign'] : ''),
                'length' => $this->duration(max(0, $v['last'] - $v['started']) * 1000),
                'engaged' => sprintf($this->l('%s engaged'), $this->duration($v['activeMs'])),
                'pages' => sprintf($this->l('%d pages'), $v['pages']),
                'outcome' => $v['outcome'], 'outcomeText' => $t['outcomes'][$v['outcome']],
                'total' => $v['orderedAt'] && $v['total'] > 0 ? SpcCartAnswer::price($this->context, $v['total']) : '',
                'steps' => $pages,
                'more' => count($v['views']) > 40 ? sprintf($this->l('and %d more pages'), count($v['views']) - 40) : '',
                'gap' => $i > 0 ? sprintf($this->l('%s later'), $this->duration(max(0, $v['started'] - $j['visits'][$i - 1]['last']) * 1000)) : '',
            ];
        }
        $sum = $j['summary'];
        $tiles = [
            ['text' => false, 'value' => (string) $sum['visits'], 'label' => $sum['visits'] === 1 ? $this->l('visit') : $this->l('visits')],
            ['text' => false, 'value' => $sum['days'] > 0 ? (string) $sum['days'] : $this->duration($sum['span'] * 1000), 'label' => $sum['days'] > 0 ? ($sum['days'] === 1 ? $this->l('day to decide') : $this->l('days to decide')) : ($sum['orderedAt'] ? $this->l('from first page to order') : $this->l('from first page to last'))],
            ['text' => false, 'value' => (string) $sum['pages'], 'label' => $this->l('pages seen')],
            ['text' => false, 'value' => $this->duration($sum['activeMs']), 'label' => $this->l('engaged')],
            ['text' => true, 'value' => isset($t['source'][$sum['source']]) ? $t['source'][$sum['source']] : $sum['source'], 'label' => $sum['ref'] !== '' ? sprintf($this->l('came from %s'), $sum['ref']) : $this->l('first came from')],
            ['text' => true, 'value' => implode(', ', array_map(function ($d) use ($t) { return isset($t['device'][$d]) ? $t['device'][$d] : $d; }, $sum['devices'])), 'label' => $this->l('device')],
        ];

        return [
            'empty' => false, 'where' => $where, 'card' => $this->cardLayout(), 'css' => $this->module->getPathUri() . 'views/css/journey.css',
            'tiles' => $tiles, 'visits' => $visits,
            'outcome' => $sum['outcome'], 'outcomeText' => $t['outcomes'][$sum['outcome']],
            'link' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '#spc-behaviour',
            't' => [
                'title' => $where === 'order' ? $this->l('Path to this order') : $this->l('Path to this cart'),
                'all' => $this->l('All visits in Behaviour'),
            ],
        ];
    }

    protected function eventLine(array $e, array $t)
    {
        switch ($e['type']) {
            case 'cart':
                return ['kind' => 'cart', 'text' => $t['events']['cart']];
            case 'step':
                return ['kind' => 'step', 'text' => isset($t['steps'][$e['detail']]) ? $t['steps'][$e['detail']] : $e['detail']];
            case 'pay':
                return ['kind' => 'pay', 'text' => $t['events']['pay']];
            case 'reorder':
                return ['kind' => 'reorder', 'text' => $t['events']['reorder']];
            case 'search':
                return ['kind' => $e['value'] === 0 ? 'error' : 'search', 'text' => sprintf($t['events']['search'], $e['detail'], $e['value'] < 0 ? '?' : $e['value'])];
            default:
                // "delivery: no delivery to this postcode": the step by its name, the message as shown
                if (preg_match('/^(personal|addresses|delivery|payment): (.*)$/s', $e['detail'], $m)) {
                    return ['kind' => 'error', 'text' => $t['steps'][$m[1]] . ': ' . $m[2]];
                }

                return ['kind' => 'error', 'text' => $e['detail']];
        }
    }

    /** 40 s, 2.5 min, 3 h, 2 d. */
    protected function duration($ms)
    {
        $s = (int) round($ms / 1000);
        if ($s < 60) {
            return sprintf($this->l('%s s'), $s);
        }
        if ($s < 3600) {
            return sprintf($this->l('%s min'), $s < 600 ? round($s / 60, 1) : round($s / 60));
        }
        if ($s < 172800) {
            return sprintf($this->l('%s h'), round($s / 3600, 1));
        }

        return sprintf($this->l('%s days'), (int) round($s / 86400));
    }

    protected function day($ts)
    {
        return date('d.m.Y', $ts);
    }

    public function hookDisplayAdminOrderMain($params)
    {
        $order = new Order((int) $params['id_order']);

        return Validate::isLoadedObject($order) ? $this->journeyPanel((int) $order->id_cart, (int) $order->id, 'order') : '';
    }

    /** The order page before 1.7.7 (from 1.7.7 on, displayAdminOrderMain shows it). */
    public function hookDisplayAdminOrder($params)
    {
        return $this->cardLayout() ? '' : $this->hookDisplayAdminOrderMain($params);
    }

    /**
     * Carts have no hook on their page: the panel is made here, with the page's head, and
     * views/js/journey.js puts it at the top of the page.
     */
    public function hookDisplayBackOfficeHeader($params)
    {
        $idCart = 0;
        if (Tools::getValue('controller') === 'AdminCarts' && Tools::getIsset('viewcart')) {
            $idCart = (int) Tools::getValue('id_cart');
        } elseif (isset($_SERVER['REQUEST_URI']) && preg_match('#/sell/orders/carts/(\d+)/view#', (string) $_SERVER['REQUEST_URI'], $m)) {
            $idCart = (int) $m[1];
        }
        if ($idCart <= 0) {
            return '';
        }
        $data = $this->journeyData($idCart, (int) Order::getIdByCartId($idCart), 'cart');
        if (!$data) {
            return '';
        }

        return $this->render('admin/journey-cart.tpl', ['spc_journey' => $data, 'spc_journey_js' => $this->module->getPathUri() . 'views/js/journey.js']);
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
            'id_cart' => Validate::isLoadedObject($context->cart) ? (int) $context->cart->id : 0,
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
        if (!$this->isRegisteredInHook('actionValidateOrder') || !$this->isRegisteredInHook('displayAdminOrderMain') || !$this->isRegisteredInHook('displayBackOfficeHeader')) {
            $this->registerHooks();
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
                Configuration::updateValue(self::K_PATHS, Tools::getValue(self::K_PATHS) ? 1 : 0);
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
            self::K_PATHS => self::pathsOn() ? 1 : 0,
        ];

        return $out . $helper->generateForm([['form' => [
            'id_form' => 'spc-behaviour',
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-bar-chart'],
            'description' => $this->l('Records what shoppers do: each page shown with its engaged time (visible and in use) and scroll depth, add to cart, checkout steps, the pay button, errors and empty searches. No cookie of its own, no IP address, no browser string; visits are tied together through the shop session. Search engines and the speed audit are not recorded.'),
            'input' => [
                $switch(self::K_ENABLED, $this->l('Record visits'), $this->l('Adds a small script (about 3 KB gzipped) to every shop page.')),
                $switch(self::K_CONSENT, $this->l('Only after analytics consent'), $this->l('Records a visitor only once a consent banner allows analytics: Google Consent Mode (analytics_storage granted), or window.spcBehaviourConsent = true, or the event spc:consent on document.')),
                $switch(self::K_PATHS, $this->l('The path on orders and carts'), $this->l('Every order and cart in the back office shows the visits behind it: when, from where, on what device, page by page, with what happened on each.')),
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
