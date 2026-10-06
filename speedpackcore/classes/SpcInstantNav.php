<?php
/**
 * Instant Navigation.
 *
 * Hands the front end a deferred script that swaps the page's content region
 * instead of reloading the document when a menu link is taken. See
 * views/js/instantnav.js for how the swap keeps itself safe.
 *
 * This class's real job is to answer two questions the page cannot:
 *
 *   Which URLs must never be fetched ahead of the visitor -- the cart, the
 *   checkout, the account, either form of logout. That list is built from
 *   PrestaShop's own link builder rather than from hard-coded English path
 *   fragments, because friendly URLs are localised: on a Polish shop the cart
 *   is /pl/koszyk, and an English deny list would sail straight past it.
 *
 *   What each URL is shaped like, so the loading placeholder can stand in for
 *   the right thing: a grid of products, a page of prose, or a form.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcInstantNav extends SpcFeature
{
    public $id = 'instantnav';

    public const K_ENABLED = 'SPC_NAV_ENABLED';
    public const K_LINKS = 'SPC_NAV_LINKS';
    public const K_REGION = 'SPC_NAV_REGION';
    public const K_PREFETCH = 'SPC_NAV_PREFETCH';
    public const K_HOVER = 'SPC_NAV_HOVER';
    public const K_SKELETON = 'SPC_NAV_SKELETON';
    public const K_DELAY = 'SPC_NAV_DELAY';
    public const K_BAR = 'SPC_NAV_BAR';
    public const K_TRANSITION = 'SPC_NAV_TRANSITION';
    public const K_TRANSITION_MS = 'SPC_NAV_TRANSITION_MS';
    public const K_TTL = 'SPC_NAV_TTL';
    public const K_DEBUG = 'SPC_NAV_DEBUG';

    public const JS = 'views/js/instantnav.js';
    public const JS_MIN = 'views/js/instantnav.min.js';

    /**
     * Pages that must never be fetched ahead of the visitor: they change
     * state, are personal, or are the checkout itself, where a speculative
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

    /**
     * Pages whose placeholder is a form beside a column of details rather
     * than a grid of cards. The CMS pages are handled separately: they are
     * prose, and their URL prefix has to be read off a real page.
     */
    protected static $form_pages = [
        'contact',
        'stores',
    ];

    /* ------------------------------------------------------------------ *
     *  Settings
     * ------------------------------------------------------------------ */

    protected function defaults()
    {
        return [
            self::K_ENABLED => 1,
            // The main navigation, on desktop and on a phone.
            self::K_LINKS => '#header .top-menu a[data-depth="0"], #_mobile_top_menu a',
            // The region replaced, which is everything below the header.
            self::K_REGION => '#wrapper',
            self::K_PREFETCH => 1,
            self::K_HOVER => 60,
            self::K_SKELETON => 1,
            // Long enough that a page already in hand never flashes one.
            self::K_DELAY => 140,
            self::K_BAR => 1,
            // How the new page arrives: off, fade, slide or scale.
            self::K_TRANSITION => 'fade',
            self::K_TRANSITION_MS => 260,
            // Seconds a fetched page stays usable.
            self::K_TTL => 60,
            // Off by default; turn it on to see in the browser console what the module does.
            self::K_DEBUG => 0,
        ];
    }

    /**
     * A setting, falling back to the shipped default.
     *
     * Configuration::get returns false for a row that was never written, and
     * a module whose settings all read false is a module that does nothing.
     * A missing row means "not configured yet", not "off".
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

    public function install()
    {
        /* Written first and unconditionally: a half-installed module with no
         * settings is worse than one that failed outright. */
        $this->setDefaults();

        return $this->registerHooks();
    }

    public function uninstall()
    {
        foreach (array_keys($this->defaults()) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    protected function setDefaults()
    {
        foreach ($this->defaults() as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
    }

    protected function registerHooks()
    {
        return $this->registerHook('actionFrontControllerSetMedia');
    }

    /* ------------------------------------------------------------------ *
     *  Front office
     * ------------------------------------------------------------------ */

    public function summary()
    {
        $on = (bool) $this->conf(self::K_ENABLED);

        return ['on' => $on, 'status' => $on ? $this->l('On') : $this->l('Off'), 'fact' => $this->l('Menu clicks swap the content: no reload, no white flash.')];
    }

    public function hookActionFrontControllerSetMedia()
    {
        if (!$this->conf(self::K_ENABLED)) {
            return;
        }

        $this->context->controller->registerJavascript(
            'instantnav',
            $this->asset(),
            ['position' => 'bottom', 'priority' => 200, 'attributes' => 'defer']
        );

        Media::addJsDef(['instantNavConfig' => [
            'enabled' => true,
            'links' => (string) $this->conf(self::K_LINKS),
            'region' => (string) $this->conf(self::K_REGION),
            'prefetch' => (bool) $this->conf(self::K_PREFETCH),
            'hoverDelay' => (int) $this->conf(self::K_HOVER),
            'skeleton' => (bool) $this->conf(self::K_SKELETON),
            'skeletonDelay' => (int) $this->conf(self::K_DELAY),
            'bar' => (bool) $this->conf(self::K_BAR),
            'transition' => $this->transitionStyle(),
            'transitionMs' => (int) $this->conf(self::K_TRANSITION_MS),
            'ttl' => (int) $this->conf(self::K_TTL),
            'debug' => (bool) $this->conf(self::K_DEBUG),
            'loadingLabel' => $this->l('Loading'),
            'shapes' => $this->pageShapes(),
            'denyPrefixes' => $this->unsafePathPrefixes(),
        ]]);
    }

    /**
     * The transitions on offer, and the one in force.
     *
     * Kept to a list rather than free text: the value is written into a CSS
     * attribute selector on the page, so it has to be one of a known few.
     */
    public static function transitions()
    {
        return ['off', 'fade', 'slide', 'scale'];
    }

    protected function transitionStyle()
    {
        $chosen = (string) $this->conf(self::K_TRANSITION);

        return in_array($chosen, self::transitions(), true) ? $chosen : 'fade';
    }

    /** The minified build where it exists, the readable one otherwise. */
    protected function asset()
    {
        $min = $this->dir() . '/' . self::JS_MIN;

        return is_file($min) && filesize($min) > 0
            ? 'modules/' . $this->name . '/' . self::JS_MIN
            : 'modules/' . $this->name . '/' . self::JS;
    }

    /* ------------------------------------------------------------------ *
     *  What the shop's URLs mean
     * ------------------------------------------------------------------ */

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

    protected function pathFor($page)
    {
        try {
            $url = $this->context->link->getPageLink($page, true);
        } catch (Exception $e) {
            return '';
        }

        $path = $this->normalisePath(parse_url($url, PHP_URL_PATH));

        /* getPageLink falls back to index.php?controller=... where a page has
         * no friendly URL. That prefix would match the whole shop. */
        if ($path === '' || Tools::strpos($path, '/index.php') !== false) {
            return '';
        }

        return $path;
    }

    protected function unsafePathPrefixes()
    {
        $prefixes = [];

        foreach (self::$unsafe_pages as $page) {
            $path = $this->pathFor($page);
            if ($path !== '' && !in_array($path, $prefixes, true)) {
                $prefixes[] = $path;
            }
        }

        return $prefixes;
    }

    /**
     * What each URL prefix looks like, longest first so the more specific
     * prefix wins. Only a first guess: the page side measures each page it
     * lands on and prefers what it measured.
     */
    protected function pageShapes()
    {
        $shapes = [];

        foreach (self::$form_pages as $page) {
            $path = $this->pathFor($page);
            if ($path !== '') {
                $shapes[] = ['prefix' => $path, 'shape' => 'form'];
            }
        }

        $cms = $this->cmsPathPrefix();
        if ($cms !== '') {
            $shapes[] = ['prefix' => $cms, 'shape' => 'article'];
        }

        usort($shapes, function ($a, $b) {
            return Tools::strlen($b['prefix']) - Tools::strlen($a['prefix']);
        });

        return $shapes;
    }

    /**
     * The folder the CMS pages sit under, read off a real one.
     *
     * getPageLink('cms') has no page to point at and answers with
     * index.php?controller=cms, so the friendly prefix -- /pl/content on this
     * shop, whatever the cms_rule route says on another -- has to come from
     * an actual page's URL with its last segment removed.
     */
    protected function cmsPathPrefix()
    {
        try {
            // any active page will do: only its address is read, in the visitor's language below
            $pages = CMS::getCMSPages(null, null, true);
        } catch (Exception $e) {
            return '';
        }

        if (empty($pages)) {
            return '';
        }

        $first = reset($pages);
        if (empty($first['id_cms'])) {
            return '';
        }

        try {
            $url = $this->context->link->getCMSLink(
                (int) $first['id_cms'],
                null,
                null,
                (int) $this->context->language->id
            );
        } catch (Exception $e) {
            return '';
        }

        $path = $this->normalisePath(parse_url($url, PHP_URL_PATH));
        if ($path === '' || Tools::strpos($path, '/index.php') !== false) {
            return '';
        }

        $cut = Tools::strrpos($path, '/');
        if ($cut === false || $cut === 0) {
            return '';
        }

        return Tools::substr($path, 0, $cut);
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->id)) {
            $hover = (int) Tools::getValue(self::K_HOVER);
            $delay = (int) Tools::getValue(self::K_DELAY);
            $ttl = (int) Tools::getValue(self::K_TTL);
            $links = trim((string) Tools::getValue(self::K_LINKS));
            $region = trim((string) Tools::getValue(self::K_REGION));
            $motion = (string) Tools::getValue(self::K_TRANSITION);
            $motionMs = (int) Tools::getValue(self::K_TRANSITION_MS);

            if ($hover < 0 || $hover > 1000) {
                $output .= $this->displayError($this->l('Hover delay must be between 0 and 1000 ms.'));
            } elseif ($delay < 0 || $delay > 3000) {
                $output .= $this->displayError($this->l('Placeholder delay must be between 0 and 3000 ms.'));
            } elseif ($ttl < 1 || $ttl > 600) {
                $output .= $this->displayError($this->l('A fetched page must stay usable for between 1 and 600 seconds.'));
            } elseif ($region === '') {
                $output .= $this->displayError($this->l('The swapped region cannot be empty.'));
            } elseif (!in_array($motion, self::transitions(), true)) {
                $output .= $this->displayError($this->l('That is not one of the transitions on offer.'));
            } elseif ($motionMs < 0 || $motionMs > 1200) {
                $output .= $this->displayError($this->l('The transition must be between 0 and 1200 ms.'));
            } else {
                Configuration::updateValue(self::K_ENABLED, (int) Tools::getValue(self::K_ENABLED));
                Configuration::updateValue(self::K_LINKS, $links);
                Configuration::updateValue(self::K_REGION, $region);
                Configuration::updateValue(self::K_PREFETCH, (int) Tools::getValue(self::K_PREFETCH));
                Configuration::updateValue(self::K_HOVER, $hover);
                Configuration::updateValue(self::K_SKELETON, (int) Tools::getValue(self::K_SKELETON));
                Configuration::updateValue(self::K_DELAY, $delay);
                Configuration::updateValue(self::K_BAR, (int) Tools::getValue(self::K_BAR));
                Configuration::updateValue(self::K_TRANSITION, $motion);
                Configuration::updateValue(self::K_TRANSITION_MS, $motionMs);
                Configuration::updateValue(self::K_TTL, $ttl);
                Configuration::updateValue(self::K_DEBUG, (int) Tools::getValue(self::K_DEBUG));

                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        /* Re-registering costs nothing and fixes the one failure that leaves
         * the module installed but silent. */
        $this->registerHooks();

        return $output . $this->renderStatus() . $this->renderForm();
    }

    protected function renderStatus()
    {
        $shapes = [];
        foreach ($this->pageShapes() as $shape) {
            $shapes[] = $shape['prefix'] . ' → ' . $shape['shape'];
        }

        $rows = [
            $this->l('Active') => $this->conf(self::K_ENABLED) ? $this->l('yes') : $this->l('no'),
            $this->l('Script served') => $this->asset(),
            $this->l('Links swapped') => (string) $this->conf(self::K_LINKS),
            $this->l('Region replaced') => (string) $this->conf(self::K_REGION),
            $this->l('Transition') => $this->transitionStyle() === 'off'
                ? $this->l('none')
                : sprintf($this->l('%1$s over %2$d ms'), $this->transitionStyle(), (int) $this->conf(self::K_TRANSITION_MS)),
            $this->l('Placeholder') => $this->conf(self::K_SKELETON)
                ? sprintf($this->l('after %d ms'), (int) $this->conf(self::K_DELAY))
                : $this->l('off'),
            $this->l('Pages shaped as') => $shapes ? implode(', ', $shapes) : $this->l('all as listings'),
            $this->l('Never fetched ahead') => implode(', ', $this->unsafePathPrefixes()),
        ];

        return $this->render('admin/status.tpl', [
            'spc_status' => ['title' => $this->l('Status'), 'rows' => $rows, 'help' => $this->l('Open the shop with the browser console showing. The module announces itself, and says which shape each placeholder took and whether it was measured or guessed.')],
        ]);
    }

    protected function renderForm()
    {
        $switch = function ($label, $name, $desc = '') {
            return [
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'is_bool' => true,
                'desc' => $desc,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ];
        };

        $fields = ['form' => [
            'id_form' => 'spc-instantnav',
            'legend' => ['title' => $this->l('Instant Navigation'), 'icon' => 'icon-cogs'],
            'input' => [
                $switch($this->l('Active'), self::K_ENABLED),
                [
                    'type' => 'text',
                    'label' => $this->l('Links swapped'),
                    'name' => self::K_LINKS,
                    'desc' => $this->l('Which links replace the page instead of reloading it. Anything not matching this is left completely alone.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Region replaced'),
                    'name' => self::K_REGION,
                    'desc' => $this->l('The part of the page that is replaced. Everything outside it - the header above all - is never touched, which is what stops the flash.'),
                ],
                $switch($this->l('Fetch on hover'), self::K_PREFETCH, $this->l('Starts fetching the page when the pointer rests on a link, so the click has nothing left to wait for.')),
                [
                    'type' => 'text',
                    'label' => $this->l('Hover delay (ms)'),
                    'name' => self::K_HOVER,
                    'class' => 'fixed-width-sm',
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Keep a fetched page (s)'),
                    'name' => self::K_TTL,
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('How long a page fetched ahead stays usable before it is fetched again.'),
                ],
                $switch($this->l('Loading placeholder'), self::K_SKELETON, $this->l('Shows the shape of the page that is coming while it loads. It measures each page you visit and reuses those proportions next time, so it lines up with the real thing rather than approximating it.')),
                [
                    'type' => 'text',
                    'label' => $this->l('Placeholder delay (ms)'),
                    'name' => self::K_DELAY,
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('A page fetched on hover arrives inside this window, so no placeholder is ever seen. Only a genuinely slow page shows one.'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Transition'),
                    'name' => self::K_TRANSITION,
                    'desc' => $this->l('How the new page arrives. Where the browser supports view transitions the old and new content are animated as snapshots on the compositor, which stays smooth however heavy the listing is; elsewhere it falls back to a plain fade. Anyone whose system asks for reduced motion gets none of it.'),
                    'options' => [
                        'query' => [
                            ['id' => 'off', 'name' => $this->l('None - swap instantly')],
                            ['id' => 'fade', 'name' => $this->l('Fade')],
                            ['id' => 'slide', 'name' => $this->l('Fade and rise')],
                            ['id' => 'scale', 'name' => $this->l('Fade and settle')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Transition length (ms)'),
                    'name' => self::K_TRANSITION_MS,
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Around 250 ms reads as smooth. Much longer and the shop feels slow rather than polished.'),
                ],
                $switch($this->l('Progress line'), self::K_BAR, $this->l('A thin line across the top of the window while the next page is on its way.')),
                $switch($this->l('Log to the browser console'), self::K_DEBUG, $this->l('Prints what was swapped and which placeholder was used, under the [instant-nav] prefix. Useful while confirming the module works; turn it off afterwards.')),
            ],
            'submit' => ['title' => $this->l('Save'), 'class' => 'btn btn-default pull-right'],
        ]];

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
