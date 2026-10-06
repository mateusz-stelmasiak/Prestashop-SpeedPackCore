<?php
/**
 * SpeedPack Core - Cache: the data cache (Redis, APCu or Memcached), PHP's OPcache, PrestaShop's
 * own speed switches and a warm-up after emptying.
 *
 * The data cache keeps database query results in memory. PrestaShop reads its choice from
 * app/config/parameters.php while it starts, so that file is the switch; it is only rewritten
 * after the chosen cache has answered a real test from this request (see SpcCacheBackend).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcCache extends SpcFeature
{
    public $id = 'cache';

    public const K_BACKEND = 'SPC_CACHE_BACKEND';
    public const K_REDIS_HOST = 'SPC_CACHE_REDIS_HOST';
    public const K_REDIS_PORT = 'SPC_CACHE_REDIS_PORT';
    public const K_REDIS_PASSWORD = 'SPC_CACHE_REDIS_PASSWORD';
    public const K_REDIS_DB = 'SPC_CACHE_REDIS_DB';
    public const K_REDIS_PREFIX = 'SPC_CACHE_REDIS_PREFIX';
    public const K_MEMCACHED_HOST = 'SPC_CACHE_MEMCACHED_HOST';
    public const K_MEMCACHED_PORT = 'SPC_CACHE_MEMCACHED_PORT';
    public const K_WARMUP = 'SPC_CACHE_WARMUP';

    /* PrestaShop's own speed settings, shown here so they sit next to the cache */
    public const PS_COMPILE = 'PS_SMARTY_FORCE_COMPILE';
    public const PS_SMARTY_CACHE = 'PS_SMARTY_CACHE';
    public const PS_CCC_CSS = 'PS_CSS_THEME_CACHE';
    public const PS_CCC_JS = 'PS_JS_THEME_CACHE';
    public const PS_HTACCESS = 'PS_HTACCESS_CACHE_CONTROL';

    /** set when the cache was just emptied, so the warm-up starts on its own */
    protected $warmNow = false;

    protected function defaults()
    {
        return [
            self::K_REDIS_HOST => '127.0.0.1',
            self::K_REDIS_PORT => 6379,
            self::K_REDIS_PASSWORD => '',
            self::K_REDIS_DB => 0,
            // one prefix per shop, so emptying never touches another site on the same Redis
            self::K_REDIS_PREFIX => 'ps' . Tools::substr(md5(_COOKIE_KEY_ . 'speedpackcore'), 0, 8) . ':',
            self::K_MEMCACHED_HOST => '127.0.0.1',
            self::K_MEMCACHED_PORT => 11211,
            self::K_WARMUP => 1,
        ];
    }

    protected function conf($key)
    {
        $value = Configuration::get($key);
        if ($value === false || ($value === '' && $key !== self::K_REDIS_PASSWORD)) {
            $defaults = $this->defaults();

            return isset($defaults[$key]) ? $defaults[$key] : false;
        }

        return $value;
    }

    public function install()
    {
        foreach ($this->defaults() as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        return true;
    }

    /** Never leave PrestaShop pointed at a class that is about to go. */
    public function uninstall()
    {
        if (SpcCacheBackend::current() === SpcCacheBackend::REDIS) {
            $errors = SpcCacheBackend::writeParameters(false, SpcCacheBackend::IDLE_CLASS, $this->module);
            if ($errors) {
                return false;
            }
        }
        SpcCacheBackend::removeRedisClass();
        foreach (array_keys($this->defaults()) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    protected function redisSettings()
    {
        return [
            'host' => (string) $this->conf(self::K_REDIS_HOST),
            'port' => (int) $this->conf(self::K_REDIS_PORT),
            'password' => (string) $this->conf(self::K_REDIS_PASSWORD),
            'database' => (int) $this->conf(self::K_REDIS_DB),
            'prefix' => (string) $this->conf(self::K_REDIS_PREFIX),
        ];
    }

    /**
     * Writes the Redis class again from the module's template, when Redis is the cache in use
     * (an upgrade brings a new template; the copy in override/ keeps the old one until then).
     *
     * @return string[] errors
     */
    public function refreshRedisClass()
    {
        if (SpcCacheBackend::current() !== SpcCacheBackend::REDIS) {
            return [];
        }

        return SpcCacheBackend::installRedisClass($this->redisSettings(), $this->module);
    }

    protected function memcachedSettings()
    {
        return [
            'host' => (string) $this->conf(self::K_MEMCACHED_HOST),
            'port' => (int) $this->conf(self::K_MEMCACHED_PORT),
        ];
    }

    public function summary()
    {
        $backend = SpcCacheBackend::current();
        if ($backend === SpcCacheBackend::OFF) {
            return ['on' => false, 'status' => $this->l('Off'), 'fact' => $this->l('Every database result is read from the database again. Choose Redis, APCu or Memcached.')];
        }
        $fact = $this->l('Database results come from memory.');
        $stats = SpcCacheBackend::stats($this->redisSettings());
        $asked = $stats ? (int) $stats['hits'] + (int) $stats['misses'] : 0;
        if ($asked > 0) {
            $fact = sprintf($this->l('%s of reads answered from memory.'), round(100 * (int) $stats['hits'] / $asked) . '%');
        }

        return ['on' => true, 'status' => $this->backendName($backend), 'fact' => $fact];
    }

    protected function backendName($backend)
    {
        $names = [
            SpcCacheBackend::OFF => $this->l('Off'),
            SpcCacheBackend::REDIS => 'Redis',
            SpcCacheBackend::APCU => 'APCu',
            SpcCacheBackend::MEMCACHED => 'Memcached',
        ];

        return isset($names[$backend]) ? $names[$backend] : (string) $backend;
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    /** The warm-up's steps, posted by views/js/admin.js; answers JSON. */
    public function ajaxWarmup()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $step = SpcWarmup::step($this->context, (int) Tools::getValue('offset'));
        $step['ok'] = true;
        $step['message'] = sprintf($this->l('Warming up: %1$d of %2$d pages'), $step['offset'], $step['total']);
        if ($step['finished']) {
            $step['message'] = sprintf($this->l('Done: %d pages are in the cache again.'), $step['total']);
        }
        echo json_encode($step);
        exit;
    }

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitSpcCache')) {
            $out .= $this->saveCache();
        } elseif (Tools::isSubmit('submitSpcBuiltin')) {
            $out .= $this->saveBuiltin();
        } elseif (Tools::isSubmit('submitSpcFlush')) {
            $out .= $this->flushAll();
        } elseif (Tools::isSubmit('submitSpcOpcache')) {
            $out .= SpcOpcache::reset()
                ? $this->displayConfirmation($this->l('OPcache reset. PHP compiles each file again on its first use.'))
                : $this->displayError($this->l('OPcache could not be reset from here (it is off, or the server does not allow it).'));
        }

        return $out . $this->renderStatus() . $this->renderCacheForm() . $this->renderBuiltinForm() . $this->renderActions();
    }

    protected function saveCache()
    {
        $backend = (string) Tools::getValue(self::K_BACKEND);
        $host = trim((string) Tools::getValue(self::K_REDIS_HOST));
        $port = (int) Tools::getValue(self::K_REDIS_PORT);
        $db = (int) Tools::getValue(self::K_REDIS_DB);
        $prefix = trim((string) Tools::getValue(self::K_REDIS_PREFIX));
        $password = (string) Tools::getValue(self::K_REDIS_PASSWORD);
        if ($password === '') {
            // an empty password field keeps the saved one
            $password = (string) $this->conf(self::K_REDIS_PASSWORD);
        }
        $mHost = trim((string) Tools::getValue(self::K_MEMCACHED_HOST));
        $mPort = (int) Tools::getValue(self::K_MEMCACHED_PORT);

        if ($backend === SpcCacheBackend::REDIS) {
            if ($host === '') {
                return $this->displayError($this->l('Enter the Redis host.'));
            }
            if ($port < 1 || $port > 65535) {
                return $this->displayError($this->l('The Redis port must be between 1 and 65535.'));
            }
            if ($db < 0 || $db > 255) {
                return $this->displayError($this->l('The Redis database must be between 0 and 255.'));
            }
            if (!preg_match('/^[A-Za-z0-9_:.\-]{1,40}$/', $prefix)) {
                return $this->displayError($this->l('The key prefix may use letters, digits and _ : . - only (up to 40).'));
            }
        }
        if ($backend === SpcCacheBackend::MEMCACHED && ($mHost === '' || $mPort < 1 || $mPort > 65535)) {
            return $this->displayError($this->l('Enter the Memcached host and a port between 1 and 65535.'));
        }

        $redis = ['host' => $host, 'port' => $port, 'password' => $password, 'database' => $db, 'prefix' => $prefix];
        $memcached = ['host' => $mHost, 'port' => $mPort];
        $errors = SpcCacheBackend::activate($backend, $redis, $memcached, $this->module);
        if ($errors) {
            return $this->displayError(Tools::safeOutput(implode(' ', $errors)));
        }

        // saved only once they are proven, so the status and the flush talk to the server in use
        if ($backend === SpcCacheBackend::REDIS) {
            Configuration::updateValue(self::K_REDIS_HOST, $host);
            Configuration::updateValue(self::K_REDIS_PORT, $port);
            Configuration::updateValue(self::K_REDIS_PASSWORD, $password);
            Configuration::updateValue(self::K_REDIS_DB, $db);
            Configuration::updateValue(self::K_REDIS_PREFIX, $prefix);
        }
        if ($backend === SpcCacheBackend::MEMCACHED) {
            Configuration::updateValue(self::K_MEMCACHED_HOST, $mHost);
            Configuration::updateValue(self::K_MEMCACHED_PORT, $mPort);
        }

        return $this->displayConfirmation($backend === SpcCacheBackend::OFF
            ? $this->l('Data cache switched off.')
            : sprintf($this->l('%s is now caching the shop.'), $this->backendName($backend)));
    }

    protected function saveBuiltin()
    {
        $compile = (int) Tools::getValue(self::PS_COMPILE);
        if (!in_array($compile, [0, 1, 2], true)) {
            return $this->displayError($this->l('Unknown template compiling mode.'));
        }
        Configuration::updateValue(self::K_WARMUP, Tools::getValue(self::K_WARMUP) ? 1 : 0);

        $new = [
            self::PS_COMPILE => $compile,
            self::PS_SMARTY_CACHE => Tools::getValue(self::PS_SMARTY_CACHE) ? 1 : 0,
            self::PS_CCC_CSS => Tools::getValue(self::PS_CCC_CSS) ? 1 : 0,
            self::PS_CCC_JS => Tools::getValue(self::PS_CCC_JS) ? 1 : 0,
            self::PS_HTACCESS => Tools::getValue(self::PS_HTACCESS) ? 1 : 0,
        ];
        $changed = [];
        foreach ($new as $key => $value) {
            if ((int) Configuration::get($key) !== $value) {
                $changed[$key] = (int) Configuration::get($key);
                Configuration::updateValue($key, $value);
            }
        }
        if (isset($changed[self::PS_HTACCESS]) && !Tools::generateHtaccess()) {
            Configuration::updateValue(self::PS_HTACCESS, $changed[self::PS_HTACCESS]);

            return $this->displayError($this->l('.htaccess could not be written, so browser caching was left as it was. The other settings were saved.'));
        }
        if (isset($changed[self::PS_CCC_CSS]) || isset($changed[self::PS_CCC_JS])) {
            Media::clearCache();
        }
        if (isset($changed[self::PS_COMPILE]) || isset($changed[self::PS_SMARTY_CACHE])) {
            Tools::clearSmartyCache();
        }

        return $this->displayConfirmation($changed ? $this->l('Settings updated.') : $this->l('Nothing changed.'));
    }

    protected function flushAll()
    {
        $data = SpcCacheBackend::flush($this->redisSettings());
        Tools::clearSmartyCache();
        Media::clearCache();
        $this->warmNow = (bool) $this->conf(self::K_WARMUP);

        return $data
            ? $this->displayConfirmation($this->l('Cache emptied: data cache, templates and combined CSS/JS.'))
            : $this->displayError($this->l('Templates and combined CSS/JS were emptied, but the data cache did not answer.'));
    }

    /* ---- what is in force ---- */

    protected function renderStatus()
    {
        $backend = SpcCacheBackend::current();
        $available = SpcCacheBackend::available();
        $offer = [];
        foreach ([SpcCacheBackend::REDIS, SpcCacheBackend::APCU, SpcCacheBackend::MEMCACHED] as $b) {
            $offer[] = $this->backendName($b) . ': ' . ($available[$b] ? $this->l('installed') : $this->l('not installed'));
        }
        $rows = [
            $this->l('Data cache in use') => $this->backendName($backend),
            $this->l('On this server') => implode(' · ', $offer),
        ];

        // the guide's rule of thumb: with the MySQL query cache on (MySQL 5.7, MariaDB), a data
        // cache on the same machine gains less – the speed audit measures how much
        $qc = [];
        foreach ((array) Db::getInstance()->executeS('SHOW VARIABLES LIKE \'query_cache_%\'') as $v) {
            if (isset($v['Variable_name'], $v['Value'])) {
                $qc[Tools::strtolower($v['Variable_name'])] = $v['Value'];
            }
        }
        $qcOn = isset($qc['query_cache_type']) && !in_array(Tools::strtoupper($qc['query_cache_type']), ['OFF', '0'], true) && !empty($qc['query_cache_size']);
        $rows[$this->l('Database query cache')] = !isset($qc['query_cache_type']) ? $this->l('not in this MySQL version') : ($qcOn ? $this->l('On') : $this->l('Off'));

        $stats = $backend !== SpcCacheBackend::OFF ? SpcCacheBackend::stats($this->redisSettings()) : null;
        if ($stats) {
            $asked = $stats['hits'] + $stats['misses'];
            $rows[$this->l('Data cache hit rate')] = $asked ? round(100 * $stats['hits'] / $asked, 1) . ' %' : '–';
            $rows[$this->l('Data cache memory')] = $this->megabytes($stats['used']) . ($stats['limit'] > 0 ? ' / ' . $this->megabytes($stats['limit']) : '');
        }

        $opcache = SpcOpcache::status();
        if (is_array($opcache)) {
            $asked = $opcache['hits'] + $opcache['misses'];
            $rows['OPcache'] = $this->l('On');
            $rows[$this->l('OPcache hit rate')] = $asked ? round(100 * $opcache['hits'] / $asked, 1) . ' %' : '–';
            $rows[$this->l('OPcache memory')] = $this->megabytes($opcache['used']) . ' / ' . $this->megabytes($opcache['limit']);
            $rows[$this->l('OPcache files')] = number_format($opcache['scripts'], 0, '.', ' ') . ' / ' . number_format($opcache['max_scripts'], 0, '.', ' ');
        } else {
            $rows['OPcache'] = $opcache === 'restricted' ? $this->l('On, numbers hidden') : $this->l('Off');
        }

        $notes = SpcOpcache::advice($opcache, $this->module);
        if ($qcOn) {
            $notes[] = ['level' => 'info', 'text' => $this->l('The database keeps its own query cache, so a data cache here gains less than usual. The speed audit at the top of this page measures how much it still saves on this shop.')];
        }
        if ($backend !== SpcCacheBackend::OFF && !isset(SpcCacheBackend::$classes[$backend])) {
            $notes[] = ['level' => 'info', 'text' => sprintf($this->l('The shop uses %s, chosen on the Performance page. Choosing a data cache below replaces it.'), $backend)];
        }

        return $this->render('admin/status.tpl', [
            'spc_status' => ['title' => $this->l('Cache status'), 'rows' => $rows, 'help' => '', 'notes' => $notes],
        ]);
    }

    protected function megabytes($bytes)
    {
        return round($bytes / 1048576) . ' MB';
    }

    /* ---- forms ---- */

    protected function helper($submit)
    {
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = $submit;

        return $helper;
    }

    protected function switchField($name, $label, $desc)
    {
        return [
            'type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
            'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]],
        ];
    }

    protected function renderCacheForm()
    {
        $available = SpcCacheBackend::available();
        $options = [['id' => SpcCacheBackend::OFF, 'name' => $this->l('Off')]];
        foreach ([SpcCacheBackend::REDIS, SpcCacheBackend::APCU, SpcCacheBackend::MEMCACHED] as $b) {
            $options[] = ['id' => $b, 'name' => $this->backendName($b) . ($available[$b] ? '' : ' (' . $this->l('not installed on this server') . ')')];
        }
        $current = SpcCacheBackend::current();

        $helper = $this->helper('submitSpcCache');
        $helper->fields_value = [
            self::K_BACKEND => isset(SpcCacheBackend::$classes[$current]) || $current === SpcCacheBackend::OFF ? $current : SpcCacheBackend::OFF,
            self::K_REDIS_HOST => $this->conf(self::K_REDIS_HOST),
            self::K_REDIS_PORT => $this->conf(self::K_REDIS_PORT),
            self::K_REDIS_PASSWORD => '',
            self::K_REDIS_DB => $this->conf(self::K_REDIS_DB),
            self::K_REDIS_PREFIX => $this->conf(self::K_REDIS_PREFIX),
            self::K_MEMCACHED_HOST => $this->conf(self::K_MEMCACHED_HOST),
            self::K_MEMCACHED_PORT => $this->conf(self::K_MEMCACHED_PORT),
        ];

        return $helper->generateForm([['form' => [
            'id_form' => 'spc-cache',
            'legend' => ['title' => $this->l('Data cache'), 'icon' => 'icon-hdd'],
            'description' => $this->l('Keeps database query results in memory, so pages wait less for the database. A cache is only switched on after it answers a real test (connect, password, write and read); if it is down later, the shop keeps working without it.'),
            'input' => [
                ['type' => 'select', 'name' => self::K_BACKEND, 'label' => $this->l('Data cache'), 'options' => ['query' => $options, 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'name' => self::K_REDIS_HOST, 'label' => $this->l('Redis host'), 'desc' => $this->l('An address such as 127.0.0.1, or a socket path starting with /.'), 'class' => 'fixed-width-xl'],
                ['type' => 'text', 'name' => self::K_REDIS_PORT, 'label' => $this->l('Redis port'), 'class' => 'fixed-width-sm'],
                ['type' => 'password', 'name' => self::K_REDIS_PASSWORD, 'label' => $this->l('Redis password'), 'desc' => $this->l('Leave empty to keep the saved password.')],
                ['type' => 'text', 'name' => self::K_REDIS_DB, 'label' => $this->l('Redis database'), 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'name' => self::K_REDIS_PREFIX, 'label' => $this->l('Redis key prefix'), 'desc' => $this->l('Every key this shop writes starts with this; emptying the cache deletes only these keys, never the whole server.'), 'class' => 'fixed-width-xl'],
                ['type' => 'text', 'name' => self::K_MEMCACHED_HOST, 'label' => $this->l('Memcached host'), 'class' => 'fixed-width-xl'],
                ['type' => 'text', 'name' => self::K_MEMCACHED_PORT, 'label' => $this->l('Memcached port'), 'class' => 'fixed-width-sm'],
            ],
            'submit' => ['title' => $this->l('Save and test')],
        ]]]);
    }

    protected function renderBuiltinForm()
    {
        $helper = $this->helper('submitSpcBuiltin');
        $helper->fields_value = [
            self::PS_COMPILE => (int) Configuration::get(self::PS_COMPILE),
            self::PS_SMARTY_CACHE => (int) Configuration::get(self::PS_SMARTY_CACHE),
            self::PS_CCC_CSS => (int) Configuration::get(self::PS_CCC_CSS),
            self::PS_CCC_JS => (int) Configuration::get(self::PS_CCC_JS),
            self::PS_HTACCESS => (int) Configuration::get(self::PS_HTACCESS),
            self::K_WARMUP => (int) $this->conf(self::K_WARMUP),
        ];

        return $helper->generateForm([['form' => [
            'id_form' => 'spc-builtin',
            'legend' => ['title' => $this->l('PrestaShop speed settings'), 'icon' => 'icon-cogs'],
            'description' => $this->l('The same switches as on Advanced Parameters > Performance, with the fast choice explained.'),
            'input' => [
                ['type' => 'select', 'name' => self::PS_COMPILE, 'label' => $this->l('Template compiling'), 'desc' => $this->l('"Never recompile" is fastest for a live shop; after editing the theme, empty the cache below.'), 'options' => ['query' => [
                    ['id' => 0, 'name' => $this->l('Never recompile template files')],
                    ['id' => 1, 'name' => $this->l('Recompile templates if the files have been updated')],
                    ['id' => 2, 'name' => $this->l('Force compilation (slow, for development)')],
                ], 'id' => 'id', 'name' => 'name']],
                $this->switchField(self::PS_SMARTY_CACHE, $this->l('Template cache'), $this->l('Keeps rendered template parts between requests.')),
                $this->switchField(self::PS_CCC_CSS, $this->l('Combine and compress CSS'), $this->l('One stylesheet instead of dozens.')),
                $this->switchField(self::PS_CCC_JS, $this->l('Combine and compress JavaScript'), $this->l('One script instead of dozens.')),
                $this->switchField(self::PS_HTACCESS, $this->l('Browser caching'), $this->l('Lets browsers keep images, CSS and scripts (writes rules to .htaccess; Apache and LiteSpeed).')),
                $this->switchField(self::K_WARMUP, $this->l('Warm up after emptying'), $this->l('After "Empty the cache", visit the home page, every category and the best-selling products, so no customer gets the slow first load.')),
            ],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }

    protected function renderActions()
    {
        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/admin.js');

        return $this->render('admin/cache-actions.tpl', [
            'spc_actions' => [
                'url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'autostart' => $this->warmNow,
                'opcache' => is_array(SpcOpcache::status()),
            ],
        ]);
    }
}
