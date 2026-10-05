<?php
/**
 * The cache section's moving parts: which data cache PrestaShop uses, the
 * Redis class it needs, and the numbers behind the charts.
 *
 * PrestaShop reads its cache choice from app/config/parameters.php while it
 * starts, before the database is available, so that file is the one switch
 * that matters. It is only ever rewritten after the chosen back end has been
 * proven to work from this request - a cache that cannot connect is never
 * switched on, and the Redis class is always in place before the file names it.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcCacheBackend
{
    public const OFF = 'off';
    public const REDIS = 'redis';
    public const APCU = 'apcu';
    public const MEMCACHED = 'memcached';

    /** PrestaShop cache class for each back end. */
    public static $classes = [
        self::REDIS => 'CacheRedis',
        self::APCU => 'CacheApc',
        self::MEMCACHED => 'CacheMemcached',
    ];

    /**
     * Named in parameters.php when the cache is off. A core class whose
     * constructor never throws, so PrestaShop can build it safely even if
     * something asks for the cache while it is switched off.
     */
    public const IDLE_CLASS = 'CacheMemcached';

    /** Where the Redis class lives once installed. */
    public static function redisClassFile()
    {
        return _PS_OVERRIDE_DIR_ . 'classes/cache/CacheRedis.php';
    }

    public static function parametersFile()
    {
        return _PS_ROOT_DIR_ . '/app/config/parameters.php';
    }

    /* ------------------------------------------------------------------ *
     *  What the server offers
     * ------------------------------------------------------------------ */

    /** @return array back end => bool */
    public static function available()
    {
        return [
            self::REDIS => class_exists('Redis') && extension_loaded('redis'),
            self::APCU => extension_loaded('apcu') && function_exists('apcu_store')
                && (PHP_SAPI !== 'cli' ? (bool) ini_get('apc.enabled') : (bool) ini_get('apc.enable_cli')),
            self::MEMCACHED => class_exists('Memcached') && extension_loaded('memcached'),
        ];
    }

    /* ------------------------------------------------------------------ *
     *  What PrestaShop is set to now
     * ------------------------------------------------------------------ */

    /** @return array|null the parameters array, or null when it cannot be read */
    public static function readParameters()
    {
        $file = self::parametersFile();
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $config = include $file;

        return (is_array($config) && isset($config['parameters']) && is_array($config['parameters']))
            ? $config
            : null;
    }

    /** The back end in force according to parameters.php. */
    public static function current()
    {
        $config = self::readParameters();
        if ($config === null) {
            return self::OFF;
        }

        $p = $config['parameters'];
        if (empty($p['ps_cache_enable'])) {
            return self::OFF;
        }

        $class = isset($p['ps_caching']) ? (string) $p['ps_caching'] : '';
        $backend = array_search($class, self::$classes, true);

        /* Something this module does not manage, such as the old Memcache
         * class, chosen on PrestaShop's Performance page. */
        return $backend === false ? $class : $backend;
    }

    /* ------------------------------------------------------------------ *
     *  Switching
     * ------------------------------------------------------------------ */

    /**
     * Point PrestaShop at a back end.
     *
     * @param string $backend one of the constants
     * @param array $redis Redis settings, used when $backend is REDIS
     * @param array $memcached array('host' => ..., 'port' => ...), used when $backend is MEMCACHED
     *
     * @return string[] errors, empty on success
     */
    public static function activate($backend, array $redis, array $memcached, Module $module)
    {
        if ($backend === self::OFF) {
            return self::writeParameters(false, self::IDLE_CLASS, $module);
        }

        if (!isset(self::$classes[$backend])) {
            return [$module->l('Unknown cache type.', 'spccachebackend')];
        }

        $available = self::available();
        if (empty($available[$backend])) {
            return [$module->l('That cache is not installed on this server.', 'spccachebackend')];
        }

        if ($backend === self::REDIS) {
            if (Configuration::get('PS_DISABLE_OVERRIDES')) {
                return [$module->l('Redis needs overrides, but "Disable all overrides" is on under Advanced Parameters > Performance.', 'spccachebackend')];
            }

            $test = self::testRedis($redis);
            if ($test !== true) {
                return [sprintf($module->l('Redis did not answer: %s', 'spccachebackend'), $test)];
            }

            $errors = self::installRedisClass($redis, $module);
            if ($errors) {
                return $errors;
            }
        }

        if ($backend === self::MEMCACHED) {
            $test = self::testMemcached($memcached);
            if ($test !== true) {
                return [sprintf($module->l('Memcached did not answer: %s', 'spccachebackend'), $test)];
            }
            self::registerMemcachedServer($memcached);
        }

        if ($backend === self::APCU) {
            $probe = 'speedpackcore_probe_' . mt_rand();
            if (!apcu_store($probe, 1, 5) || apcu_fetch($probe) !== 1) {
                return [$module->l('APCu is loaded but not storing anything (is apc.enabled on?).', 'spccachebackend')];
            }
            apcu_delete($probe);
        }

        return self::writeParameters(true, self::$classes[$backend], $module);
    }

    /**
     * Rewrite the two cache entries of parameters.php, the way PrestaShop
     * writes that file itself, keeping one untouched copy of the original.
     *
     * @return string[] errors
     */
    public static function writeParameters($enabled, $class, Module $module)
    {
        $file = self::parametersFile();
        $config = self::readParameters();

        if ($config === null) {
            return [$module->l('app/config/parameters.php could not be read.', 'spccachebackend')];
        }
        if (!is_writable($file) || !is_writable(dirname($file))) {
            return [$module->l('app/config/parameters.php is not writable.', 'spccachebackend')];
        }

        $p = $config['parameters'];
        if (isset($p['ps_cache_enable'], $p['ps_caching'])
            && (bool) $p['ps_cache_enable'] === (bool) $enabled
            && (string) $p['ps_caching'] === (string) $class
        ) {
            return [];
        }

        $backup = $file . '.speedpackcore.bak';
        if (!file_exists($backup)) {
            @copy($file, $backup);
        }

        $config['parameters']['ps_cache_enable'] = (bool) $enabled;
        $config['parameters']['ps_caching'] = (string) $class;

        $php = '<?php return ' . var_export($config, true) . ';' . "\n";
        $tmp = $file . '.' . uniqid('tmp', true);

        if (file_put_contents($tmp, $php, LOCK_EX) !== strlen($php)) {
            @unlink($tmp);

            return [$module->l('app/config/parameters.php could not be written.', 'spccachebackend')];
        }
        @chmod($tmp, fileperms($file) & 0777);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return [$module->l('app/config/parameters.php could not be replaced.', 'spccachebackend')];
        }

        self::invalidate($file);
        self::forgetParameters();

        return [];
    }

    /**
     * PrestaShop keeps a copy of parameters.php per environment and a
     * compiled container that also holds the cache choice. Both are dropped
     * so every entry point sees the new setting on its next request.
     */
    protected static function forgetParameters()
    {
        foreach ((array) glob(_PS_ROOT_DIR_ . '/var/cache/*/appParameters.php') as $cached) {
            @unlink($cached);
            self::invalidate($cached);
        }

        Tools::clearSf2Cache();
    }

    protected static function invalidate($file)
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /* ------------------------------------------------------------------ *
     *  The Redis class
     * ------------------------------------------------------------------ */

    /**
     * Write the Redis class, with its settings baked in, and make sure the
     * autoloader can find it before anything is pointed at it.
     *
     * @return string[] errors
     */
    public static function installRedisClass(array $redis, Module $module)
    {
        $template = _PS_MODULE_DIR_ . 'speedpackcore/install/CacheRedis.php.dist';
        $source = @file_get_contents($template);
        $marker = "    /* @speedpackcore:settings */\n    protected static \$settings = array();";

        if ($source === false || strpos($source, $marker) === false) {
            return [$module->l('The Redis class template is missing from the module.', 'spccachebackend')];
        }

        $settings = [
            'host' => (string) $redis['host'],
            'port' => (int) $redis['port'],
            'password' => (string) $redis['password'],
            'database' => (int) $redis['database'],
            'prefix' => (string) $redis['prefix'],
            'timeout' => 0.5,
        ];

        $code = str_replace(
            $marker,
            "    /* @speedpackcore:settings */\n    protected static \$settings = " . var_export($settings, true) . ';',
            $source
        );

        $target = self::redisClassFile();
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return [$module->l('override/classes/cache could not be created.', 'spccachebackend')];
        }

        $existed = is_file($target);
        if (@file_put_contents($target, $code, LOCK_EX) !== strlen($code)) {
            return [$module->l('override/classes/cache/CacheRedis.php could not be written.', 'spccachebackend')];
        }
        @chmod($target, 0644);
        self::invalidate($target);

        if (!$existed) {
            self::rebuildClassIndex();
        }

        // loaded straight from the file for this request; the next requests find it through the
        // class index PrestaShop rebuilds once the stale one is gone
        if (!class_exists('CacheRedis', false)) {
            require_once $target;
        }
        if (!class_exists('CacheRedis', false)) {
            return [$module->l('PrestaShop cannot load the Redis class. Clear the cache under Advanced Parameters > Performance and try again.', 'spccachebackend')];
        }

        return [];
    }

    /** Only when nothing names it any more. */
    public static function removeRedisClass()
    {
        $target = self::redisClassFile();
        if (!is_file($target)) {
            return true;
        }
        if (self::current() === self::REDIS) {
            return false;
        }

        if (!@unlink($target)) {
            return false;
        }
        self::invalidate($target);
        self::rebuildClassIndex();

        return true;
    }

    /**
     * Every environment's class index is stale once a class file comes or goes. Deleting it is
     * enough: PrestaShop writes a fresh one on its next request.
     */
    protected static function rebuildClassIndex()
    {
        foreach ((array) glob(_PS_ROOT_DIR_ . '/var/cache/*/class_index.php') as $index) {
            @unlink($index);
            self::invalidate($index);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Connections
     * ------------------------------------------------------------------ */

    /** @return Redis|string a connected client, or why not */
    public static function redisClient(array $s)
    {
        if (!class_exists('Redis')) {
            return 'the phpredis extension is not loaded';
        }

        try {
            $redis = new Redis();
            $ok = strpos((string) $s['host'], '/') === 0
                ? $redis->connect((string) $s['host'])
                : $redis->connect((string) $s['host'], (int) $s['port'], 1.0);
            if (!$ok) {
                return 'connection refused';
            }
            if ((string) $s['password'] !== '' && !$redis->auth((string) $s['password'])) {
                return 'wrong password';
            }
            if ((int) $s['database'] > 0 && !$redis->select((int) $s['database'])) {
                return 'database ' . (int) $s['database'] . ' cannot be selected';
            }

            return $redis;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /** @return true|string */
    public static function testRedis(array $s)
    {
        $redis = self::redisClient($s);
        if (!$redis instanceof Redis) {
            return $redis;
        }

        try {
            $probe = $s['prefix'] . 'speedpackcore_probe';
            $ok = $redis->setex($probe, 5, '1') && $redis->get($probe) === '1';
            $redis->del($probe);
            $redis->close();

            return $ok ? true : 'it would not store a value';
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /** @return true|string */
    public static function testMemcached(array $s)
    {
        if (!class_exists('Memcached')) {
            return 'the memcached extension is not loaded';
        }

        $m = new Memcached();
        $m->addServer((string) $s['host'], (int) $s['port']);
        $versions = $m->getVersion();

        return (is_array($versions) && $versions && !in_array('255.255.255', $versions, true))
            ? true
            : 'no server at ' . $s['host'] . ':' . (int) $s['port'];
    }

    /** PrestaShop's Memcached class reads its servers from this table. */
    protected static function registerMemcachedServer(array $s)
    {
        foreach ((array) CacheMemcached::getMemcachedServers() as $server) {
            if ($server['ip'] === (string) $s['host'] && (int) $server['port'] === (int) $s['port']) {
                return;
            }
        }

        CacheMemcached::addServer((string) $s['host'], (int) $s['port'], 1);
    }

    /* ------------------------------------------------------------------ *
     *  Emptying
     * ------------------------------------------------------------------ */

    /** Empty the data cache in force. Redis loses this shop's keys only. */
    public static function flush(array $redis)
    {
        $backend = self::current();

        if ($backend === self::REDIS) {
            $client = self::redisClient($redis);
            if (!$client instanceof Redis) {
                return false;
            }
            try {
                $client->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
                $glob = addcslashes((string) $redis['prefix'], '*?[]\\') . '*';
                $iterator = null;
                while (($keys = $client->scan($iterator, $glob, 1000)) !== false) {
                    if ($keys) {
                        $client->del($keys);
                    }
                }

                return true;
            } catch (Exception $e) {
                return false;
            }
        }

        if ($backend === self::APCU && function_exists('apcu_clear_cache')) {
            return apcu_clear_cache();
        }

        // PrestaShop defines these while it starts, from parameters.php
        $enabled = defined('_PS_CACHE_ENABLED_') && constant('_PS_CACHE_ENABLED_');
        $system = defined('_PS_CACHING_SYSTEM_') ? (string) constant('_PS_CACHING_SYSTEM_') : '';
        if ($backend === self::MEMCACHED && $enabled && $system === 'CacheMemcached') {
            return Cache::getInstance()->flush();
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     *  Numbers for the charts
     * ------------------------------------------------------------------ */

    /**
     * Hits, misses and memory of the back end in force, or null when there
     * is nothing to measure.
     *
     * @return array|null array('hits', 'misses', 'used', 'limit', 'scope')
     */
    public static function stats(array $redis)
    {
        $backend = self::current();

        if ($backend === self::REDIS) {
            $client = self::redisClient($redis);
            if (!$client instanceof Redis) {
                return null;
            }
            try {
                $info = $client->info();
                $client->close();
            } catch (Exception $e) {
                return null;
            }

            return [
                'hits' => (int) self::pick($info, 'keyspace_hits'),
                'misses' => (int) self::pick($info, 'keyspace_misses'),
                'used' => (float) self::pick($info, 'used_memory'),
                'limit' => (float) self::pick($info, 'maxmemory'),
                'scope' => 'server',
            ];
        }

        if ($backend === self::APCU && function_exists('apcu_cache_info')) {
            $info = @apcu_cache_info(true);
            $sma = @apcu_sma_info(true);
            if (!is_array($info)) {
                return null;
            }
            // shared memory: segments x their size, and what is still free in them
            $limit = (float) self::pick($sma, 'num_seg') * (float) self::pick($sma, 'seg_size');
            $free = (float) self::pick($sma, 'avail_mem');

            return [
                'hits' => (int) self::pick($info, 'num_hits'),
                'misses' => (int) self::pick($info, 'num_misses'),
                'used' => $limit > 0 ? $limit - $free : (float) self::pick($info, 'mem_size'),
                'limit' => $limit,
                'scope' => 'apcu',
            ];
        }

        if ($backend === self::MEMCACHED && class_exists('Memcached')) {
            $m = new Memcached();
            foreach ((array) CacheMemcached::getMemcachedServers() as $server) {
                $m->addServer($server['ip'], (int) $server['port']);
            }
            $all = @$m->getStats();
            if (!is_array($all) || !$all) {
                return null;
            }

            $sum = ['hits' => 0, 'misses' => 0, 'used' => 0, 'limit' => 0, 'scope' => 'server'];
            foreach ($all as $server) {
                if (!is_array($server) || empty($server['pid'])) {
                    continue;
                }
                $sum['hits'] += (int) self::pick($server, 'get_hits');
                $sum['misses'] += (int) self::pick($server, 'get_misses');
                $sum['used'] += (float) self::pick($server, 'bytes');
                $sum['limit'] += (float) self::pick($server, 'limit_maxbytes');
            }

            return $sum;
        }

        return null;
    }

    protected static function pick($array, $key)
    {
        return (is_array($array) && isset($array[$key])) ? $array[$key] : 0;
    }
}
