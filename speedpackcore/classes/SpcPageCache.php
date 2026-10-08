<?php
/**
 * SpeedPack Core - Page cache: whole catalogue pages kept ready for visitors who are not signed in.
 *
 * A page is served from the cache only when it is the same for every such visitor: a GET for a
 * catalogue page (home, category, product, CMS, brand, supplier, the listings), from a visitor
 * who is not signed in and has no cart, with no notification waiting, and no preview or AJAX. What
 * else the page depends on is part of its key: the shop and address, the language, the currency,
 * the country, phone or computer, and which image formats the browser takes (when Optimize serves
 * WebP or AVIF). Campaign tags (utm_*, gclid, fbclid...) are left out of the key.
 *
 * Requests of the speed audit are never kept; they are answered from it only when they ask for
 * every part on (how the audit measures the page cache).
 *
 * Served at actionDispatcherBefore, before PrestaShop builds the page; stored at
 * actionOutputHTMLBefore, from the page PrestaShop has just built. Kept gzipped in
 * var/cache/<env>/spc-pages, with an index table for clearing: a product, its categories, the
 * home page and the listings when a product, its stock or its price changes; everything when a
 * category, a CMS page, a brand or the theme changes, and on PrestaShop's own "Clear cache".
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcPageCache extends SpcFeature
{
    public $id = 'pagecache';

    public const K_ENABLED = 'SPC_PC_ENABLED';
    public const K_TTL = 'SPC_PC_TTL';
    public const K_PAGES = 'SPC_PC_PAGES';
    public const K_MOBILE = 'SPC_PC_MOBILE';
    /** shoppers with a cart (not signed in) get kept pages too, their cart refreshed on the page */
    public const K_CARTS = 'SPC_PC_CARTS';

    /**
     * Put into a kept page sent to a shopper with a cart: the cart in the header is asked for
     * again the way PrestaShop's own cart block does it after a change (updateCart), so it shows
     * this shopper's cart and not the empty one the page was kept with. Sent after the page's
     * jQuery ready handlers, where the cart block starts listening.
     */
    public const CART_REFRESH = '<script id="spc-cart-refresh">(function(){var n=0;function emit(){prestashop.emit("updateCart",{reason:{linkAction:"refresh",cacheRefresh:true},resp:{}});}function go(){if(window.prestashop&&typeof prestashop.emit==="function"&&window.jQuery){jQuery(function(){setTimeout(emit,0);});}else if(n++<100){setTimeout(go,50);}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}})();</script>';

    /** the pages that may be cached (PrestaShop's controller names) */
    public const PAGES = ['index', 'category', 'product', 'cms', 'manufacturer', 'supplier', 'new-products', 'prices-drop', 'best-sales'];

    /** query parameters that never change a page (campaign tags, the speed audit's own) */
    public const IGNORED = '/^(utm_[a-z_]+|gclid|gbraid|wbraid|fbclid|msclkid|dclid|yclid|twclid|ttclid|_ga|_gl|mc_cid|mc_eid|spc_t|spc_start|spc_nav)$/';

    /** query parameters that mean "do not cache": previews, AJAX, actions, sign-out */
    public const BYPASS = '/^(ajax|action|adtoken|id_employee|preview|live_edit|logout|mylogout|submit[a-z_]*|token|spc_nocache|spc_nocrit)$/i';

    /** the cookie keys of a visitor who is not anonymous */
    public const PERSONAL = ['id_customer', 'id_cart', 'logged'];

    /** @var string|null why the current request is not served from or stored in the cache */
    public static $why;

    /** @var string|null the key of the current request, worked out once */
    protected static $key;

    public function install()
    {
        return Configuration::updateValue(self::K_ENABLED, 0)
            && Configuration::updateValue(self::K_TTL, 12)
            && Configuration::updateValue(self::K_PAGES, implode(',', self::PAGES))
            && Configuration::updateValue(self::K_MOBILE, 1)
            && Configuration::updateValue(self::K_CARTS, 1)
            && Configuration::updateValue(SpcWarm::K_ENABLED, 1)
            && self::installTable()
            && $this->registerHooks();
    }

    public function registerHooks()
    {
        $ok = true;
        foreach (self::hooks() as $hook) {
            $ok = $ok && $this->registerHook($hook);
        }

        return $ok;
    }

    /** What makes pages stale. */
    public static function hooks()
    {
        return [
            'actionOutputHTMLBefore',
            'actionObjectProductAddAfter', 'actionObjectProductUpdateAfter', 'actionObjectProductDeleteAfter', 'actionUpdateQuantity',
            'actionObjectSpecificPriceAddAfter', 'actionObjectSpecificPriceUpdateAfter', 'actionObjectSpecificPriceDeleteAfter',
            'actionObjectCategoryAddAfter', 'actionObjectCategoryUpdateAfter', 'actionObjectCategoryDeleteAfter',
            'actionObjectCmsAddAfter', 'actionObjectCmsUpdateAfter', 'actionObjectCmsDeleteAfter',
            'actionObjectManufacturerUpdateAfter', 'actionObjectSupplierUpdateAfter', 'actionObjectSpecificPriceRuleUpdateAfter',
            'actionClearCache', 'actionClearCompileCache', 'actionModuleInstallAfter',
        ];
    }

    public function uninstall()
    {
        self::flush();
        foreach ([self::K_ENABLED, self::K_TTL, self::K_PAGES, self::K_MOBILE, self::K_CARTS, SpcWarm::K_ENABLED, SpcWarm::K_QUEUE, SpcWarm::K_LOCK, SpcWarm::K_CURSOR, SpcCloudflare::K_ZONE, SpcCloudflare::K_TOKEN, SpcCloudflare::K_LAST] as $k) {
            Configuration::deleteByName($k);
        }
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . self::table());

        return true;
    }

    public static function enabled()
    {
        return (int) Configuration::get(self::K_ENABLED) === 1;
    }

    /**
     * A setting saved for the whole installation: every shop and group too. A value kept for one
     * shop (multistore, or left by another tool) would otherwise win over the one saved here, and
     * the switch would seem not to work.
     */
    public static function set($key, $value)
    {
        Configuration::updateGlobalValue($key, $value);
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'' . pSQL((string) $value) . '\', date_upd = NOW()
            WHERE `name` = \'' . pSQL($key) . '\' AND (id_shop IS NOT NULL OR id_shop_group IS NOT NULL)');
        if (method_exists('Configuration', 'loadConfiguration')) {
            Configuration::loadConfiguration();
        }

        return (string) Configuration::get($key) === (string) $value;
    }

    /**
     * The shop's home page opened twice as a first-time visitor (a browser, no cookies): what the
     * page cache did with each. ['first' => state, 'second' => state, 'ms' => [a, b]] or error.
     */
    public static function selfTest($context)
    {
        $url = $context->link->getPageLink('index', true);
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'spc_t=' . bin2hex(random_bytes(4));
        $out = ['url' => $url, 'first' => null, 'second' => null, 'ms' => []];
        foreach (['first', 'second'] as $i) {
            $jar = [];
            $r = SpcAudit::request($url, null, $jar, null, SpcAudit::BROWSER);
            if (!$r['ok']) {
                $out['error'] = $r['error'];

                return $out;
            }
            $out[$i] = $r['cache'] === null ? '' : $r['cache'];
            $out['ms'][] = $r['ttfb'];
        }

        return $out;
    }

    public static function table()
    {
        return '`' . _DB_PREFIX_ . 'spc_pagecache`';
    }

    public static function installTable()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';

        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . self::table() . ' (
            `id_entry` CHAR(40) NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `controller` VARCHAR(32) NOT NULL,
            `id_object` INT UNSIGNED NOT NULL DEFAULT 0,
            `url` VARCHAR(255) NOT NULL DEFAULT \'\',
            `created` INT UNSIGNED NOT NULL,
            `expires` INT UNSIGNED NOT NULL,
            `bytes` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_entry`),
            KEY `object` (`controller`, `id_object`),
            KEY `expires` (`expires`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4');
    }

    /** Where the pages are kept. */
    public static function folder()
    {
        return rtrim(defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : sys_get_temp_dir() . '/', '/') . '/spc-pages/';
    }

    public static function file($hash)
    {
        return self::folder() . substr($hash, 0, 2) . '/' . $hash . '.html.gz';
    }

    /* ------------------------------------------------------------------ *
     *  May this request use the cache?
     * ------------------------------------------------------------------ */

    /**
     * The cache key of this request, or null (and self::$why) when it must be built live.
     *
     * @param string $controller PrestaShop's controller name (index, product...)
     * @param array $req method, scheme, host, uri, accept, ajax (X-Requested-With), cookies (the
     *                   PrestaShop cookie's values), raw (the browser's cookie names), shop, mobile
     */
    public static function key($controller, array $req)
    {
        self::$why = null;
        $pages = array_filter(explode(',', (string) Configuration::get(self::K_PAGES)));
        if (!in_array($controller, $pages ?: self::PAGES, true)) {
            return self::no('page');
        }
        if ($req['method'] !== 'GET') {
            return self::no('method');
        }
        if (!empty($req['ajax'])) {
            return self::no('ajax');
        }
        foreach (self::PERSONAL as $k) {
            // a cart alone (not signed in): the page is the same, its cart is refreshed on it
            if ($k === 'id_cart' && !empty($req['carts'])) {
                continue;
            }
            if (!empty($req['cookies'][$k])) {
                return self::no('visitor');
            }
        }
        // ps_viewedproduct shows the visitor's own last products
        if (!empty($req['cookies']['viewed']) && !empty($req['viewed'])) {
            return self::no('viewed');
        }
        if (!empty($req['raw']['notifications'])) {
            return self::no('notifications');
        }
        $parts = parse_url($req['uri']);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $kept = [];
        foreach ($query as $k => $v) {
            if (preg_match(self::BYPASS, (string) $k)) {
                return self::no('param');
            }
            if (!preg_match(self::IGNORED, (string) $k)) {
                $kept[$k] = $v;
            }
        }
        ksort($kept);
        $c = $req['cookies'];
        $key = implode('|', [
            (int) $req['shop'],
            $req['scheme'] . '://' . Tools::strtolower($req['host']) . (isset($parts['path']) ? $parts['path'] : '/') . ($kept ? '?' . http_build_query($kept) : ''),
            'l' . (isset($c['id_lang']) ? (int) $c['id_lang'] : 0),
            'c' . (isset($c['id_currency']) ? (int) $c['id_currency'] : 0),
            'k' . (isset($c['iso_code_country']) ? preg_replace('/[^A-Z]/', '', (string) $c['iso_code_country']) : ''),
            'd' . (int) Configuration::get(self::K_MOBILE) * (int) $req['mobile'],
            'i' . (isset($req['images']) ? $req['images'] : ''),
        ]);

        return sha1($key);
    }

    protected static function no($why)
    {
        self::$why = $why;

        return null;
    }

    /** The request as PrestaShop has it at the dispatcher (no controller built yet). */
    public static function request($context)
    {
        $cookie = $context->cookie;
        $values = [];
        foreach (array_merge(self::PERSONAL, ['viewed', 'id_lang', 'id_currency', 'iso_code_country']) as $k) {
            $values[$k] = $cookie ? $cookie->__get($k) : null;
        }

        return [
            'method' => isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET',
            'scheme' => Tools::usingSecureMode() ? 'https' : 'http',
            'host' => (string) Tools::getHttpHost(false, false, true),
            'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/',
            'ajax' => (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || Tools::getValue('ajax'),
            'cookies' => $values,
            'raw' => $_COOKIE,
            'viewed' => Module::isEnabled('ps_viewedproduct'),
            'shop' => (int) $context->shop->id,
            'mobile' => method_exists($context, 'isMobile') && $context->isMobile() ? 1 : 0,
            'images' => SpcOptimize::imageFormat(),
            'carts' => self::cartsKept(),
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Serving and storing
     * ------------------------------------------------------------------ */

    /**
     * At the dispatcher: the stored page, sent and the request ended, when there is one.
     *
     * @return bool false when the page has to be built (the request goes on)
     */
    public static function serve($controller, $context, $now = null)
    {
        // the speed audit uses kept pages only when it asks for everything on (SpcAudit::full)
        if (!self::enabled() || (SpcAudit::parts() !== null && !SpcAudit::full()) || !(int) Configuration::get('PS_SHOP_ENABLE')) {
            return false;
        }
        $hash = self::key($controller, self::request($context));
        self::$key = $hash;
        if (!$hash) {
            self::header('BYPASS ' . self::$why);

            return false;
        }
        $page = self::read($hash, $now === null ? time() : $now);
        if ($page === null) {
            self::header('MISS');

            return false;
        }
        self::count('hit');
        $cookie = $context->cookie;
        self::send($page, $cookie && $cookie->__get('id_cart') ? self::CART_REFRESH : '');

        return true;
    }

    /** A stored page: [meta, gzipped body], or null when there is none or it has expired. */
    public static function read($hash, $now)
    {
        $file = self::file($hash);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false || strlen($raw) < 16) {
            return null;
        }
        $nl = strpos($raw, "\n");
        $meta = $nl ? json_decode(substr($raw, 0, $nl), true) : null;
        if (!is_array($meta) || (int) $meta['expires'] < $now) {
            return null;
        }

        return [$meta, substr($raw, $nl + 1)];
    }

    /** Sends a stored page: gzipped as it is when the browser takes it, plain otherwise. */
    protected static function send(array $page, $inject = '')
    {
        list($meta, $gz) = $page;
        if ($inject !== '') {
            // this shopper's own part, put in before </body> (the page itself stays as kept)
            $html = (string) gzdecode($gz);
            $at = strripos($html, '</body>');
            $html = $at === false ? $html . $inject : substr($html, 0, $at) . $inject . substr($html, $at);
            $gz = gzencode($html, 1);
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $plain = !self::acceptsGzip() || ini_get('zlib.output_compression');
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-SpeedPack-Cache: HIT' . ($inject !== '' ? ' cart' : ''));
            header('Age: ' . max(0, time() - (int) $meta['created']));
            header('Vary: Accept-Encoding');
            if (!$plain) {
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($gz));
            }
        }
        echo $plain ? gzdecode($gz) : $gz;
        exit;
    }

    protected static function acceptsGzip()
    {
        return isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos((string) $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false && function_exists('gzencode');
    }

    /**
     * After PrestaShop built a page: keep it, when it was built for an anonymous visitor and
     * came out as a full, normal page.
     */
    public static function store($controller, $html, $context, $now = null)
    {
        if (!self::enabled() || SpcAudit::parts() !== null) {
            return false;
        }
        $hash = self::$key !== null ? self::$key : self::key($controller, self::request($context));
        if (!$hash || !self::storable($html, $context)) {
            return false;
        }
        $now = $now === null ? time() : $now;
        $ttl = max(1, (int) Configuration::get(self::K_TTL)) * 3600;
        $object = 0;
        foreach (['id_product', 'id_category', 'id_cms', 'id_manufacturer', 'id_supplier'] as $param) {
            if ((int) Tools::getValue($param)) {
                $object = (int) Tools::getValue($param);
                break;
            }
        }

        return self::write($hash, $html, [
            'controller' => $controller, 'id_object' => $object, 'shop' => (int) $context->shop->id,
            'url' => isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 255) : '',
            'created' => $now, 'expires' => $now + $ttl,
        ]);
    }

    /** Whether shoppers with a cart get kept pages (on unless switched off). */
    public static function cartsKept()
    {
        $v = Configuration::get(self::K_CARTS);

        return $v === false || (int) $v === 1;
    }

    /** Only a complete page with nothing personal in it. */
    protected static function storable($html, $context)
    {
        if (strlen($html) < 512 || stripos($html, '</html>') === false) {
            return false;
        }
        if (function_exists('http_response_code') && (int) http_response_code() !== 200 && http_response_code() !== false) {
            return false;
        }
        if (isset($context->customer) && $context->customer->isLogged()) {
            return false;
        }
        if (Validate::isLoadedObject($context->cart) && SpcCartAnswer::count($context->cart) > 0) {
            return false;
        }
        $c = $context->controller;
        foreach (['errors', 'warning', 'success', 'info'] as $k) {
            if (!empty($c->$k)) {
                return false;
            }
        }

        return true;
    }

    public static function write($hash, $html, array $meta)
    {
        $file = self::file($hash);
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true)) {
            return false;
        }
        $gz = gzencode($html, 6);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['created' => $meta['created'], 'expires' => $meta['expires']]) . "\n" . $gz) === false || !@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }
        self::count('miss');
        $db = Db::getInstance();

        return $db->execute('REPLACE INTO ' . self::table() . ' (id_entry, id_shop, controller, id_object, url, created, expires, bytes) VALUES (\''
            . self::esc($hash) . '\', ' . (int) $meta['shop'] . ', \'' . self::esc($meta['controller']) . '\', ' . (int) $meta['id_object'] . ', \''
            . self::esc($meta['url']) . '\', ' . (int) $meta['created'] . ', ' . (int) $meta['expires'] . ', ' . strlen($gz) . ')');
    }

    protected static function esc($s)
    {
        return Db::getInstance()->escape((string) $s);
    }

    protected static function header($state)
    {
        if (!headers_sent()) {
            header('X-SpeedPack-Cache: ' . $state);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Clearing
     * ------------------------------------------------------------------ */

    /** Pages of these objects go: [controller => [ids]] (an empty list: every page of that kind). */
    public static function invalidate(array $what)
    {
        $db = Db::getInstance();
        $or = [];
        foreach ($what as $controller => $ids) {
            $ids = array_filter(array_map('intval', (array) $ids));
            $or[] = '(controller = \'' . self::esc($controller) . '\'' . ($ids ? ' AND id_object IN (' . implode(',', $ids) . ')' : '') . ')';
        }
        if (!$or) {
            return 0;
        }
        $rows = $db->executeS('SELECT id_entry, url, id_shop FROM ' . self::table() . ' WHERE ' . implode(' OR ', $or));
        // the pages cleared are warmed again in the background (SpcWarm), and purged at Cloudflare
        $urls = SpcWarm::urlsOf($rows ?: []);
        SpcCloudflare::purge($urls);
        SpcWarm::push($urls);

        return self::remove(array_column($rows ?: [], 'id_entry'));
    }

    /** A product changed: its page, its categories, the home page and the listings. */
    public static function productChanged($idProduct)
    {
        $categories = [];
        foreach (Db::getInstance()->executeS('SELECT id_category FROM `' . _DB_PREFIX_ . 'category_product` WHERE id_product = ' . (int) $idProduct) ?: [] as $r) {
            $categories[] = (int) $r['id_category'];
        }
        $manufacturer = (int) Db::getInstance()->getValue('SELECT id_manufacturer FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . (int) $idProduct);

        return self::invalidate(array_filter([
            'product' => [(int) $idProduct],
            'category' => $categories ?: null,
            'manufacturer' => $manufacturer ? [$manufacturer] : null,
            'index' => [], 'new-products' => [], 'prices-drop' => [], 'best-sales' => [],
        ], function ($v) { return $v !== null; }));
    }

    /** Everything goes (for one shop, or all). */
    public static function flush($idShop = null)
    {
        $db = Db::getInstance();
        try {
            $rows = $db->executeS('SELECT id_entry, url, id_shop FROM ' . self::table() . ($idShop ? ' WHERE id_shop = ' . (int) $idShop : '') . ' ORDER BY created DESC');
        } catch (Exception $e) {
            $rows = [];
        }
        SpcCloudflare::purgeAll();
        SpcWarm::push(array_slice(SpcWarm::urlsOf($rows ?: []), 0, SpcWarm::MAX_QUEUE));
        $n = self::remove(array_column($rows ?: [], 'id_entry'));
        // files of a lost index (an interrupted write, a restored database) go too
        if (!$idShop) {
            foreach (glob(self::folder() . '*/*.html.gz') ?: [] as $f) {
                @unlink($f);
            }
        }

        return $n;
    }

    /** Expired pages go, a batch at a time. */
    public static function purge($now, $batch = 500)
    {
        $rows = Db::getInstance()->executeS('SELECT id_entry FROM ' . self::table() . ' WHERE expires < ' . (int) $now . ' LIMIT ' . (int) $batch);

        return self::remove(array_column($rows ?: [], 'id_entry'));
    }

    protected static function remove(array $hashes)
    {
        if (!$hashes) {
            return 0;
        }
        foreach ($hashes as $h) {
            @unlink(self::file($h));
        }
        $in = implode(',', array_map(function ($h) { return '\'' . self::esc($h) . '\''; }, $hashes));
        Db::getInstance()->execute('DELETE FROM ' . self::table() . ' WHERE id_entry IN (' . $in . ')');

        return count($hashes);
    }

    /* ------------------------------------------------------------------ *
     *  Figures
     * ------------------------------------------------------------------ */

    /** Hits and misses of the day: one byte per request in a small file (no database write). */
    protected static function count($what)
    {
        $dir = self::folder();
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            @file_put_contents($dir . $what . '-' . date('Ymd') . '.n', '.', FILE_APPEND);
        }
    }

    /** @return array pages, bytes, hits and misses today, hit rate */
    public static function stats($idShop, $now)
    {
        $row = Db::getInstance()->getRow('SELECT COUNT(*) n, COALESCE(SUM(bytes), 0) b FROM ' . self::table() . ' WHERE id_shop = ' . (int) $idShop . ' AND expires >= ' . (int) $now) ?: ['n' => 0, 'b' => 0];
        $day = date('Ymd', $now);
        $hits = (int) @filesize(self::folder() . 'hit-' . $day . '.n');
        $misses = (int) @filesize(self::folder() . 'miss-' . $day . '.n');
        clearstatcache();

        return [
            'pages' => (int) $row['n'], 'bytes' => (int) $row['b'], 'hits' => $hits, 'misses' => $misses,
            'rate' => $hits + $misses ? round(100 * $hits / ($hits + $misses), 1) : null,
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Settings
     * ------------------------------------------------------------------ */

    /** Whether the cache folder can be written (made when it is missing). */
    public static function writable()
    {
        $dir = self::folder();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) && is_writable($dir);
    }

    /** A self-test's result in words: ['ok' => bool, 'title' => ..., 'text' => ...]. */
    protected function explainTest(array $t)
    {
        if (!empty($t['error'])) {
            return ['ok' => false, 'title' => $this->l('The shop could not be opened from the server'), 'text' => sprintf($this->l('Opening %1$s gave: %2$s. The test needs cURL and the shop reachable from its own server.'), $t['url'], $t['error'])];
        }
        $ms = $t['ms'];
        $first = (string) $t['first'];
        $second = (string) $t['second'];
        if ($second === 'HIT') {
            return ['ok' => true, 'title' => $this->l('It works'), 'text' => sprintf($this->l('The first visit was built (%1$d ms), the second one was served ready (%2$d ms).'), $ms[0], $ms[1])];
        }
        if ($first === '' && $second === '') {
            return ['ok' => false, 'title' => self::enabled() ? $this->l('The module did not answer') : $this->l('The page cache is off'), 'text' => self::enabled()
                ? $this->l('The answers carry no X-SpeedPack-Cache header: either a cache in front of the shop (LiteSpeed, a CDN, another cache module) answered, or PrestaShop did not run the module for this page. The hooks were registered again; test once more.')
                : $this->l('Switch it on above, save, then test again.')];
        }
        if (strpos($second, 'BYPASS') === 0) {
            $why = trim(substr($second, 6));
            $reasons = [
                'page' => $this->l('the home page is not among the pages kept'),
                'method' => $this->l('the request was not a plain GET'),
                'ajax' => $this->l('the request looked like AJAX'),
                'visitor' => $this->l('every visitor gets a cart or an account in the cookie: a module creates a cart on the first visit'),
                'viewed' => $this->l('the visitor has viewed products, which ps_viewedproduct shows on the page'),
                'notifications' => $this->l('a notification cookie was set'),
                'param' => $this->l('the address carries a parameter that is never kept'),
            ];

            return ['ok' => false, 'title' => $this->l('Pages are built every time'), 'text' => sprintf($this->l('Reason: %s.'), isset($reasons[$why]) ? $reasons[$why] : $why)];
        }
        if (!self::writable()) {
            return ['ok' => false, 'title' => $this->l('Pages cannot be kept'), 'text' => sprintf($this->l('The folder %s cannot be written by the web server. Give it write access (the same as var/cache).'), self::folder())];
        }

        return ['ok' => false, 'title' => $this->l('Pages are built but not kept'), 'text' => $this->l('The home page came out with something personal or a message on it (a notice, a cart, an error), or with a status other than 200, so it is not kept. Open it in a private window and look for a message or a cart.')];
    }

    public function summary()
    {
        $on = self::enabled();
        $fact = $this->l('Catalogue pages ready for visitors who are not signed in, without building them again.');
        if ($on) {
            try {
                $s = self::stats((int) $this->context->shop->id, time());
                if ($s['rate'] !== null) {
                    $fact = sprintf($this->l('%1$s%% of pages served ready today (%2$d pages kept).'), $s['rate'], $s['pages']);
                }
            } catch (Exception $e) {
                // the table is made by the settings page
            }
        }

        return ['on' => $on, 'status' => $on ? $this->l('On') : $this->l('Off'), 'fact' => $fact];
    }

    public function getContent()
    {
        $out = '';
        $this->context->controller->addCSS($this->module->getPathUri() . 'views/css/optimize.css');
        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/warm.js');
        self::installTable();
        if (!$this->isRegisteredInHook('actionOutputHTMLBefore')) {
            $this->registerHooks();
        }
        if (Tools::isSubmit('submitSpcPageCacheFlush')) {
            $n = self::flush();
            $out .= $this->displayConfirmation(sprintf($this->l('Page cache emptied (%d pages).'), $n));
        }
        if (Tools::isSubmit('submitSpcCloudflare') || Tools::isSubmit('submitSpcCloudflareTest')) {
            $zone = trim((string) Tools::getValue(SpcCloudflare::K_ZONE));
            $token = trim((string) Tools::getValue(SpcCloudflare::K_TOKEN));
            if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/i', $zone)) {
                $out .= $this->displayError($this->l('The zone ID is 32 letters and digits (Cloudflare, the domain, Overview, on the right).'));
            } else {
                Configuration::updateValue(SpcCloudflare::K_ZONE, $zone);
                // the token field shows dots once saved: dots leave the saved token as it is
                if ($token !== '' && strpos($token, '•') === false) {
                    Configuration::updateValue(SpcCloudflare::K_TOKEN, $token);
                }
                if ($zone === '') {
                    Configuration::updateValue(SpcCloudflare::K_TOKEN, '');
                }
                if (Tools::isSubmit('submitSpcCloudflareTest') && SpcCloudflare::configured()) {
                    $t = SpcCloudflare::test();
                    $out .= $t['ok'] ? $this->displayConfirmation(sprintf($this->l('Cloudflare answers: zone %s. Cleared pages will be purged there too.'), $t['name']))
                        : $this->displayError(sprintf($this->l('Cloudflare did not accept it: %s'), $t['error']));
                } else {
                    $out .= $this->displayConfirmation($this->l('Settings updated.'));
                }
            }
        }
        $test = null;
        if (Tools::isSubmit('submitSpcPageCacheTest')) {
            $test = $this->explainTest(self::selfTest($this->context));
        }
        if (Tools::isSubmit('submitSpcPageCache')) {
            $ttl = (int) Tools::getValue(self::K_TTL);
            $pages = array_values(array_filter(self::PAGES, function ($p) { return (bool) Tools::getValue('SPC_PC_PAGE_' . $p); }));
            if ($ttl < 1 || $ttl > 720) {
                $out .= $this->displayError($this->l('Keep pages for 1 to 720 hours.'));
            } else {
                $want = Tools::getValue(self::K_ENABLED) ? 1 : 0;
                $saved = self::set(self::K_ENABLED, $want);
                self::set(self::K_TTL, $ttl);
                self::set(self::K_MOBILE, Tools::getValue(self::K_MOBILE) ? 1 : 0);
                self::set(self::K_CARTS, Tools::getValue(self::K_CARTS) ? 1 : 0);
                self::set(SpcWarm::K_ENABLED, Tools::getValue(SpcWarm::K_ENABLED) ? 1 : 0);
                self::set(self::K_PAGES, implode(',', $pages ?: self::PAGES));
                self::flush();
                $out .= $saved
                    ? $this->displayConfirmation($this->l('Settings updated; the page cache was emptied.'))
                    : $this->displayError($this->l('PrestaShop did not keep the switch: check that the module may change settings for this shop (multistore: all shops).'));
            }
        }
        self::purge(time());
        $stats = self::stats((int) $this->context->shop->id, time());
        $out .= $this->render('admin/pagecache-status.tpl', ['spc_pc' => [
            'enabled' => self::enabled(),
            'pages' => $stats['pages'],
            'size' => round($stats['bytes'] / 1048576, 1),
            'hits' => $stats['hits'],
            'misses' => $stats['misses'],
            'rate' => $stats['rate'],
            'ttl' => (int) Configuration::get(self::K_TTL),
            'test' => $test,
            'warm' => [
                'on' => SpcWarm::enabled(),
                'queued' => count(SpcWarm::queue()),
                'auto' => function_exists('fastcgi_finish_request'),
                'url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'cron' => $this->context->link->getModuleLink($this->name, 'warm', ['key' => SpcWarm::token()], true),
                'texts' => json_encode([
                    'planning' => $this->l('Listing the catalogue...'),
                    'progress' => $this->l('%1$d of %2$d pages warmed'),
                    'done' => $this->l('%1$d pages warmed: %2$d were built now, %3$d were ready already.'),
                    'failed' => $this->l('Stopped: %s'),
                    'off' => $this->l('Switch the page cache on first.'),
                ]),
            ],
            'writable' => self::writable(),
            'folder' => self::folder(),
        ]]);

        $chosen = array_filter(explode(',', (string) Configuration::get(self::K_PAGES)));
        $names = [
            'index' => $this->l('Home page'), 'category' => $this->l('Categories'), 'product' => $this->l('Products'), 'cms' => $this->l('CMS pages'),
            'manufacturer' => $this->l('Brands'), 'supplier' => $this->l('Suppliers'), 'new-products' => $this->l('New products'),
            'prices-drop' => $this->l('Price drops'), 'best-sales' => $this->l('Best sellers'),
        ];
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitSpcPageCache';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            self::K_ENABLED => (int) self::enabled(),
            self::K_TTL => (int) Configuration::get(self::K_TTL) ?: 12,
            self::K_MOBILE => (int) Configuration::get(self::K_MOBILE),
            self::K_CARTS => (int) self::cartsKept(),
            SpcWarm::K_ENABLED => (int) (Configuration::get(SpcWarm::K_ENABLED) === false || (int) Configuration::get(SpcWarm::K_ENABLED) === 1),
        ];
        // a checkbox list: HelperForm reads one value per box (SPC_PC_PAGE_<page>)
        $helper->fields_value['SPC_PC_PAGE'] = '';
        foreach (self::PAGES as $p) {
            $helper->fields_value['SPC_PC_PAGE_' . $p] = in_array($p, $chosen, true);
        }
        $switch = function ($name, $label, $desc) {
            return ['type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]], ];
        };

        return $out . $helper->generateForm([['form' => [
            'id_form' => 'spc-pagecache',
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-bolt'],
            'description' => $this->l('Visitors who are not signed in get catalogue pages ready-made, in a few milliseconds instead of having PrestaShop build them. Signed-in customers, the cart page, the checkout, searches and anything personal are always built live. Pages are cleared when a product, its stock or price, a category or a page changes, and with "Clear cache" in PrestaShop. Visits served from the cache do not reach the visitor statistics of PrestaShop (Behaviour still counts them).'),
            'input' => [
                $switch(self::K_ENABLED, $this->l('Page cache'), $this->l('Test your shop as a visitor (a private window) after switching it on.')),
                ['type' => 'checkbox', 'name' => 'SPC_PC_PAGE', 'label' => $this->l('Pages kept'), 'values' => ['query' => array_map(function ($p) use ($names) { return ['id' => $p, 'name' => $names[$p]]; }, self::PAGES), 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'name' => self::K_TTL, 'label' => $this->l('Keep pages for'), 'suffix' => $this->l('hours'), 'class' => 'fixed-width-sm', 'desc' => $this->l('Changes in the back office clear the pages they touch at once; this is for what changes by itself (a price that starts on a date).')],
                $switch(self::K_MOBILE, $this->l('Separate pages for phones'), $this->l('Keep this on if the theme or a module shows phones a different page.')),
                $switch(self::K_CARTS, $this->l('Shoppers with a cart too'), $this->l('Shoppers who are not signed in but have something in their cart get the kept pages as well; the cart in the header is asked for again on the page, as PrestaShop does after a change. Switch off if a module shows something about the cart in the page itself (a free delivery bar, cart suggestions).')),
                $switch(SpcWarm::K_ENABLED, $this->l('Warm pages again'), $this->l('Pages a change clears are opened again in the background after a visitor has their page, so the next one gets them ready (needs PHP-FPM; otherwise use the cron address below).')),
            ],
            'submit' => ['title' => $this->l('Save')],
            'buttons' => [
                ['type' => 'submit', 'name' => 'submitSpcPageCacheFlush', 'title' => $this->l('Empty the page cache'), 'icon' => 'process-icon-eraser', 'class' => 'pull-left'],
                ['type' => 'submit', 'name' => 'submitSpcPageCacheTest', 'title' => $this->l('Test as a visitor'), 'icon' => 'process-icon-preview', 'class' => 'pull-left'],
            ],
        ]]]) . $this->cloudflareForm();
    }

    /** Cloudflare: the zone and the token, a test, and the last purge. */
    protected function cloudflareForm()
    {
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitSpcCloudflare';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            SpcCloudflare::K_ZONE => (string) Configuration::get(SpcCloudflare::K_ZONE),
            SpcCloudflare::K_TOKEN => (string) Configuration::get(SpcCloudflare::K_TOKEN) !== '' ? str_repeat('•', 12) : '',
        ];
        $last = json_decode((string) Configuration::get(SpcCloudflare::K_LAST), true);
        $desc = $this->l('When the shop sits behind Cloudflare: the pages cleared here are purged there too, and "Clear cache" purges it whole. An API token with the Cache Purge permission for the zone (Cloudflare, My Profile, API Tokens).');
        if (is_array($last)) {
            $desc .= ' ' . sprintf($this->l('Last purge: %1$s, %2$s.'), $last['at'], $last['ok'] ? $this->l('done') : sprintf($this->l('refused (%s)'), $last['error']));
        }

        return $helper->generateForm([['form' => [
            'id_form' => 'spc-cloudflare',
            'legend' => ['title' => 'Cloudflare', 'icon' => 'icon-cloud'],
            'description' => $desc,
            'input' => [
                ['type' => 'text', 'name' => SpcCloudflare::K_ZONE, 'label' => $this->l('Zone ID'), 'class' => 'fixed-width-xxl'],
                ['type' => 'text', 'name' => SpcCloudflare::K_TOKEN, 'label' => $this->l('API token'), 'class' => 'fixed-width-xxl', 'desc' => $this->l('Kept on the server; shown as dots once saved.')],
            ],
            'submit' => ['title' => $this->l('Save')],
            'buttons' => [['type' => 'submit', 'name' => 'submitSpcCloudflareTest', 'title' => $this->l('Save and test'), 'icon' => 'process-icon-ok', 'class' => 'pull-left']],
        ]]]);
    }
}
