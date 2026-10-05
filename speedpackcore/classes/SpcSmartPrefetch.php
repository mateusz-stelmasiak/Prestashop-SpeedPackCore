<?php
/**
 * Smart Prefetch - speculative navigation prefetching.
 *
 * Registers a deferred script that prefetches the page behind a link shortly
 * before the visitor clicks it. See views/js/smartprefetch.js for the three
 * strategies and why intent-based prefetching is the one that earns its
 * bandwidth.
 *
 * This class's real job is to hand the front end a list of URLs it must never
 * speculatively request. That list is built from PrestaShop's own link
 * builder rather than from a hard-coded list of English path fragments,
 * because friendly URLs are localised - on a Polish shop the cart is
 * /pl/koszyk, and an English deny list would sail straight past it.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcSmartPrefetch extends SpcFeature
{
    public $id = 'smartprefetch';

    public const K_ENABLED = 'SPC_SP_ENABLED';
    public const K_HOVER_DELAY = 'SPC_SP_HOVER_DELAY';
    public const K_MAX_TOTAL = 'SPC_SP_MAX_TOTAL';
    public const K_MAX_WARMUP = 'SPC_SP_MAX_WARMUP';
    public const K_WARMUP_SELECTOR = 'SPC_SP_WARMUP_SEL';
    public const K_VIEWPORT = 'SPC_SP_VIEWPORT';
    public const K_VIEWPORT_SELECTOR = 'SPC_SP_VIEWPORT_SEL';
    public const K_DEBUG = 'SPC_SP_DEBUG';

    /**
     * Pages that must never be prefetched: they mutate state, are personal to
     * the visitor, or are the conversion funnel itself, where a speculative
     * request buys nothing and risks confusing session handling.
     */
    protected static $unsafe_pages = [
        'cart',
        'order',
        'order-confirmation',
        'order-detail',
        'order-follow',
        'order-return',
        'order-slip',
        'authentication',
        'registration',
        'my-account',
        'identity',
        'address',
        'addresses',
        'history',
        'discount',
        'password',
        'guest-tracking',
    ];

    /** Front controllers on which prefetching is switched off entirely. */
    protected static $silent_controllers = [
        'cart', 'order', 'orderconfirmation', 'orderdetail', 'orderfollow',
        'orderslip', 'authentication', 'registration', 'myaccount', 'identity',
        'address', 'addresses', 'history', 'discount', 'guesttracking', 'password',
    ];

    public function install()
    {
        /* Settings are written first and unconditionally. They used to hang
         * off the end of an && chain, so a hook that failed to register left
         * the module with no configuration at all - which reads at runtime as
         * "disabled", and in the back office as empty fields that fail
         * validation the moment Save is pressed. */
        $this->setDefaults();

        $this->registerHook('actionFrontControllerSetMedia');
        $this->registerHook('displayHeader');

        return true;
    }

    public function uninstall()
    {
        foreach ($this->defaults() as $key => $value) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    protected function defaults()
    {
        return [
            self::K_ENABLED => 1,
            self::K_HOVER_DELAY => 65,
            self::K_MAX_TOTAL => 12,
            self::K_MAX_WARMUP => 3,
            // The primary navigation, plus whatever the home slider is pushing.
            self::K_WARMUP_SELECTOR => '#header .top-menu a[data-depth="0"], .carousel .carousel-item .caption p a',
            self::K_VIEWPORT => 0,
            self::K_VIEWPORT_SELECTOR => '.product-miniature .product-title a',
            // Off by default; turn it on to see in the browser console what the module does.
            self::K_DEBUG => 0,
        ];
    }

    /**
     * Configuration value, falling back to the shipped default.
     *
     * Never let an absent or blank row decide behaviour: a module whose
     * settings failed to write should still run on its defaults rather than
     * silently switch itself off.
     */
    protected function conf($key)
    {
        $value = Configuration::get($key);

        if ($value === false || $value === '') {
            $defaults = $this->defaults();

            return isset($defaults[$key]) ? $defaults[$key] : false;
        }

        return $value;
    }

    protected function setDefaults()
    {
        foreach ($this->defaults() as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     *  Front office
     * ------------------------------------------------------------------ */

    public function hookActionFrontControllerSetMedia()
    {
        if (!$this->conf(self::K_ENABLED)) {
            return;
        }
        if ($this->onSilentController()) {
            return;
        }

        $asset = $this->assetPath();
        if (!$asset) {
            return;
        }

        Media::addJsDef([
            'smartPrefetchConfig' => [
                'enabled' => true,
                'hoverDelay' => (int) $this->conf(self::K_HOVER_DELAY),
                'maxTotal' => (int) $this->conf(self::K_MAX_TOTAL),
                'maxWarmup' => (int) $this->conf(self::K_MAX_WARMUP),
                'warmupSelector' => (string) $this->conf(self::K_WARMUP_SELECTOR),
                'viewport' => (bool) $this->conf(self::K_VIEWPORT),
                'viewportSelector' => (string) $this->conf(self::K_VIEWPORT_SELECTOR),
                'fetchFallback' => true,
                'workerUrl' => $this->workerUrl(),
                'scope' => $this->workerScope(),
                'debug' => (bool) $this->conf(self::K_DEBUG),
                'denyPrefixes' => $this->unsafePathPrefixes(),
            ],
        ]);

        $this->context->controller->registerJavascript(
            'modules-smartprefetch',
            'modules/' . $this->name . '/' . $asset,
            ['position' => 'bottom', 'priority' => 200, 'attributes' => 'defer']
        );
    }

    /**
     * Second route to the same registration. Themes and PrestaShop builds
     * differ in which of these fires, and registering the script twice under
     * one id is harmless - the later call replaces the earlier.
     */
    public function hookDisplayHeader()
    {
        $this->hookActionFrontControllerSetMedia();

        /* displayHeader output is echoed into <head>. This hook exists only to
         * register assets, so it returns an explicit empty string and can
         * never put a stray character on the page. */
        return '';
    }

    /**
     * The worker is the module's own static file. A worker may only claim the
     * folder it is served from, unless the server sends Service-Worker-Allowed;
     * the module's .htaccess sends it on Apache and LiteSpeed. Where it is not
     * sent (nginx without that header), registration is refused and
     * prefetching carries on with plain prefetch hints.
     */
    protected function workerUrl()
    {
        $file = is_file($this->dir() . '/views/js/sw.min.js') ? 'sw.min.js' : 'sw.js';

        return $this->module->getPathUri() . 'views/js/' . $file;
    }

    /** The shop root, so the worker covers every page. */
    protected function workerScope()
    {
        return __PS_BASE_URI__;
    }

    /** Prefer the minified build, fall back to the readable source. */
    protected function assetPath()
    {
        foreach (['views/js/smartprefetch.min.js', 'views/js/smartprefetch.js'] as $candidate) {
            if (file_exists($this->dir() . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function onSilentController()
    {
        $current = '';
        if (isset($this->context->controller->php_self)) {
            $current = (string) $this->context->controller->php_self;
        }
        $current = strtolower(str_replace(['-', '_'], '', $current));

        return in_array($current, self::$silent_controllers, true);
    }

    /**
     * The path component of every page the front end must refuse to prefetch,
     * resolved through Link so it matches whatever the shop's friendly URLs
     * and language prefixes actually look like.
     *
     * @return string[] lowercase path prefixes
     */
    /**
     * A URL path as the browser reports it: decoded, lowercased, with no
     * trailing slash, so it can be compared against location.pathname.
     */
    protected function normalisePath($path)
    {
        if (!$path) {
            return '';
        }

        return rtrim(Tools::strtolower(rawurldecode($path)), '/');
    }

    protected function unsafePathPrefixes()
    {
        $prefixes = [];

        foreach (self::$unsafe_pages as $page) {
            try {
                $url = $this->context->link->getPageLink($page, true);
            } catch (Exception $e) {
                continue;
            }

            $path = parse_url($url, PHP_URL_PATH);
            if (!$path) {
                continue;
            }

            /* Drop a trailing slash so /pl/koszyk also covers /pl/koszyk?x=1 */
            $path = $this->normalisePath($path);
            if ($path !== '' && !in_array($path, $prefixes, true)) {
                $prefixes[] = $path;
            }
        }

        return $prefixes;
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        /* Repair anything a half-finished install left behind, rather than
         * presenting a broken form and waiting to be told about it. */
        $this->setDefaults();
        $repaired = $this->ensureHooks();

        $output = '';

        if ($repaired) {
            $output .= $this->displayConfirmation(
                $this->l('Re-registered hook(s): ') . implode(', ', $repaired)
            );
        }

        if (Tools::isSubmit('submit' . $this->id)) {
            $defaults = $this->defaults();
            $number = function ($key) use ($defaults) {
                $raw = trim((string) Tools::getValue($key));

                return $raw === '' ? (int) $defaults[$key] : (int) $raw;
            };

            $delay = $number(self::K_HOVER_DELAY);
            $total = $number(self::K_MAX_TOTAL);
            $warm = $number(self::K_MAX_WARMUP);
            $warmSel = trim((string) Tools::getValue(self::K_WARMUP_SELECTOR));
            $viewSel = trim((string) Tools::getValue(self::K_VIEWPORT_SELECTOR));

            if ($delay < 0 || $delay > 1000) {
                $output .= $this->displayError($this->l('Hover delay must be between 0 and 1000 ms.'));
            } elseif ($total < 1 || $total > 50) {
                $output .= $this->displayError($this->l('Maximum prefetches must be between 1 and 50.'));
            } elseif ($warm < 0 || $warm > 10) {
                $output .= $this->displayError($this->l('Warm-up count must be between 0 and 10.'));
            } else {
                Configuration::updateValue(self::K_ENABLED, (int) Tools::getValue(self::K_ENABLED));
                Configuration::updateValue(self::K_HOVER_DELAY, $delay);
                Configuration::updateValue(self::K_MAX_TOTAL, $total);
                Configuration::updateValue(self::K_MAX_WARMUP, $warm);
                Configuration::updateValue(self::K_WARMUP_SELECTOR, $warmSel);
                Configuration::updateValue(self::K_VIEWPORT, (int) Tools::getValue(self::K_VIEWPORT));
                Configuration::updateValue(self::K_VIEWPORT_SELECTOR, $viewSel);
                Configuration::updateValue(self::K_DEBUG, (int) Tools::getValue(self::K_DEBUG));

                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        return $output . $this->renderStatus() . $this->renderForm();
    }

    /** Registers any hook that has gone missing. @return string[] repaired */
    protected function ensureHooks()
    {
        $repaired = [];

        foreach (['actionFrontControllerSetMedia', 'displayHeader'] as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $this->registerHook($hook);
                $repaired[] = $hook;
            }
        }

        return $repaired;
    }

    /**
     * A short status block, so whether the module is actually wired up can be
     * read off the configuration page instead of guessed at from the shop.
     */
    protected function renderStatus()
    {
        $asset = $this->assetPath();

        $rows = [
            $this->l('Enabled') => $this->conf(self::K_ENABLED) ? $this->l('yes') : $this->l('no'),
            $this->l('Console logging') => $this->conf(self::K_DEBUG) ? $this->l('yes') : $this->l('no'),
            $this->l('Script file') => $asset ? $asset : $this->l('MISSING'),
            $this->l('Hook actionFrontControllerSetMedia') => $this->isRegisteredInHook('actionFrontControllerSetMedia') ? $this->l('registered') : $this->l('NOT registered'),
            $this->l('Hook displayHeader') => $this->isRegisteredInHook('displayHeader') ? $this->l('registered') : $this->l('NOT registered'),
            $this->l('Service worker URL') => $this->workerUrl(),
            $this->l('Worker scope') => $this->workerScope(),
            $this->l('Worker file') => is_file($this->dir() . '/views/js/sw.js')
                ? $this->l('present') : $this->l('MISSING'),
            $this->l('Whole-shop worker') => $this->l('needs the Service-Worker-Allowed header, sent by the module .htaccess on Apache and LiteSpeed; without it, prefetching uses plain hints'),
            $this->l('Pages excluded') => implode(', ', $this->unsafePathPrefixes()),
        ];

        return $this->render('admin/status.tpl', [
            'spc_status' => ['title' => $this->l('Status'), 'rows' => $rows, 'help' => ''],
        ]);
    }

    protected function renderForm()
    {
        $switch = function ($label, $name, $hint) {
            return [
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'desc' => $hint,
                'is_bool' => true,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ];
        };

        $fields[0]['form'] = [
            'id_form' => 'spc-smartprefetch',
            'legend' => ['title' => $this->l('Smart Prefetch'), 'icon' => 'icon-bolt'],
            'input' => [
                $switch($this->l('Enabled'), self::K_ENABLED, $this->l('Prefetching is skipped automatically on cart, checkout and account pages, on slow connections, and when the visitor has Data Saver on.')),
                [
                    'type' => 'text',
                    'label' => $this->l('Hover delay (ms)'),
                    'name' => self::K_HOVER_DELAY,
                    'desc' => $this->l('How long a pointer must rest on a link before it counts as intent. 65 ms is long enough to ignore a pointer crossing the menu, short enough to still win most of the latency.'),
                    'class' => 'fixed-width-sm',
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Max prefetches per page'),
                    'name' => self::K_MAX_TOTAL,
                    'desc' => $this->l('A hard ceiling on speculative requests, so a visitor sweeping the mouse across a category page cannot pull down the whole catalogue.'),
                    'class' => 'fixed-width-sm',
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Warm-up links'),
                    'name' => self::K_MAX_WARMUP,
                    'desc' => $this->l('How many top-level links to fetch on the first page of a session, before the visitor has moved. Keep this small: every one is a guess. 0 disables the warm-up.'),
                    'class' => 'fixed-width-sm',
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Warm-up selector'),
                    'name' => self::K_WARMUP_SELECTOR,
                    'desc' => $this->l('CSS selector for the links worth guessing at. Order matters: the first matches win.'),
                ],
                $switch($this->l('Prefetch links in view'), self::K_VIEWPORT, $this->l('Off by default. On a category page this is a lot of pages fetched for a small gain.')),
                [
                    'type' => 'text',
                    'label' => $this->l('In-view selector'),
                    'name' => self::K_VIEWPORT_SELECTOR,
                ],
                $switch($this->l('Log to the browser console'), self::K_DEBUG, $this->l('Prints what is prefetched and why, under the [smart-prefetch] prefix. Useful while confirming the module works; turn it off afterwards.')),
            ],
            'submit' => ['title' => $this->l('Save'), 'class' => 'btn btn-default pull-right'],
        ];

        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submit' . $this->id;

        foreach (array_keys($this->defaults()) as $key) {
            $helper->fields_value[$key] = $this->conf($key);
        }

        return $helper->generateForm($fields);
    }
}
