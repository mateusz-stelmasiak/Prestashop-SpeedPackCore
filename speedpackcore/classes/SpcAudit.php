<?php
/**
 * SpeedPack Core - the speed audit: the shop measured with each part switched off and on.
 *
 * "Off" never touches the shop's settings. The audit's own requests carry a signed cookie
 * (spc_audit) naming the parts that may run for them; every other visitor is served as usual.
 * The cookie is signed with a key only this shop knows and expires after 15 minutes, and all it
 * can do is switch speed-ups off for the request that carries it.
 *
 * What is measured, each in a few seconds:
 *   pages      - the server's answer time for five of the shop's own pages (the data cache)
 *   cart       - adding to the cart: PrestaShop's cart controller against the lean endpoint
 *   cartspeed  - the cart's address lookups, counted and timed inside PHP
 * The navigation test (SmartPrefetch, InstantNav) runs in the admin's own browser, see
 * views/js/audit.js; its numbers come back with the save.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcAudit
{
    public const COOKIE = 'spc_audit';
    public const HEADER = 'X-SpeedPack-Audit';
    public const K_KEY = 'SPC_AUDIT_KEY';
    public const K_HISTORY = 'SPC_AUDIT_HISTORY';
    public const K_DONE = 'SPC_AUDIT_DONE';
    public const LIFETIME = 900;
    public const KEEP = 12;
    public const TIMEOUT = 15;

    /** Every part the audit can switch off. */
    public const PARTS = ['cache', 'smartprefetch', 'instantnav', 'instantcart', 'cartspeed'];

    /** @var bool whether apply() has run for this request */
    protected static $applied = false;

    /** @var array|false|null parts allowed for this request; null when it is not an audit request */
    protected static $parts = false;

    /** The shop's own signing key, made once. */
    public static function key()
    {
        $key = (string) Configuration::get(self::K_KEY);
        if (Tools::strlen($key) < 32) {
            $key = Tools::passwdGen(48);
            Configuration::updateValue(self::K_KEY, $key);
        }

        return $key;
    }

    /** A cookie value that lets only these parts run, for 15 minutes. */
    public static function token(array $parts)
    {
        $parts = array_values(array_intersect($parts, self::PARTS));
        $payload = implode(',', $parts) . '|' . (time() + self::LIFETIME);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . hash_hmac('sha256', $payload, self::key());
    }

    /**
     * The parts this request may run, from a valid audit cookie, or null for an ordinary visitor.
     *
     * @return string[]|null
     */
    public static function parts()
    {
        if (self::$parts !== false) {
            return self::$parts;
        }
        self::$parts = null;
        $raw = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : '';
        if ($raw === '' || strpos($raw, '.') === false) {
            return null;
        }
        list($data, $signature) = explode('.', $raw, 2);
        $payload = (string) base64_decode(strtr($data, '-_', '+/'));
        if (!hash_equals(hash_hmac('sha256', $payload, self::key()), $signature)) {
            return null;
        }
        list($list, $expires) = array_pad(explode('|', $payload, 2), 2, '0');
        if ((int) $expires < time()) {
            return null;
        }
        self::$parts = $list === '' ? [] : array_values(array_intersect(explode(',', $list), self::PARTS));

        return self::$parts;
    }

    /** How the shop names a configuration in its answer: "none", or the parts that ran. */
    public static function label(array $parts)
    {
        $parts = array_values(array_intersect(self::PARTS, $parts));

        return $parts ? implode(',', $parts) : 'none';
    }

    /**
     * At the very start of a shop request (actionDispatcherBefore): when the request carries a valid
     * audit cookie, give it the configuration it asks for, and say so in a response header.
     *
     * - Without "cache", PrestaShop's database layer stops using the data cache for the request,
     *   whatever the cache is (Redis, APCu, Memcached): Db::disableCache().
     * - The header names the configuration that really ran. The audit checks it on every answer:
     *   a page cache in front of the shop (a module, LiteSpeed, a CDN) answers without running
     *   PrestaShop, so its answers carry no header – and are not counted as a measurement.
     * - The answer must not be stored by any cache on the way.
     *
     * @return string[]|null the parts that run, or null for an ordinary visitor
     */
    public static function apply()
    {
        if (self::$applied) {
            return self::parts();
        }
        self::$applied = true;
        $parts = self::parts();
        if ($parts === null) {
            return null;
        }
        if (!in_array('cache', $parts, true)) {
            Db::getInstance()->disableCache();
            Db::getInstance(false)->disableCache();
        }
        if (!headers_sent()) {
            header(self::HEADER . ': ' . self::label($parts));
            header('Cache-Control: no-store, private');
        }

        return $parts;
    }

    /** Whether the audit has switched this part off for the current request. */
    public static function off($part)
    {
        $parts = self::parts();

        return $parts !== null && !in_array($part, $parts, true);
    }

    /* ------------------------------------------------------------------ *
     *  What to measure
     * ------------------------------------------------------------------ */

    /**
     * The pages, the product for the cart test and the cookies for each test.
     *
     * @return array
     */
    public static function plan($context, Module $module)
    {
        $idLang = (int) $context->language->id;
        $idShop = (int) $context->shop->id;
        $pages = [['name' => $module->l('Home page', 'spcaudit'), 'url' => $context->link->getPageLink('index', true)]];

        $categories = Db::getInstance()->executeS(
            'SELECT c.id_category, cl.name, COUNT(cp.id_product) AS products FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . ')
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . ')
            INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp ON (cp.id_category = c.id_category)
            WHERE c.active = 1 AND c.id_category NOT IN (' . (int) Configuration::get('PS_ROOT_CATEGORY') . ', ' . (int) Configuration::get('PS_HOME_CATEGORY') . ')
            GROUP BY c.id_category, cl.name ORDER BY products DESC LIMIT 2'
        );
        foreach ((array) $categories as $row) {
            $pages[] = ['name' => (string) $row['name'], 'url' => $context->link->getCategoryLink((int) $row['id_category'], null, $idLang)];
        }

        $products = self::products($idShop, $idLang, 2, false);
        foreach ($products as $row) {
            $pages[] = ['name' => (string) $row['name'], 'url' => $context->link->getProductLink((int) $row['id_product'], null, null, null, $idLang)];
        }

        $buyable = self::products($idShop, $idLang, 1, true);
        $on = function ($key) {
            $value = Configuration::get($key);

            return $value === false || $value === '' || (bool) $value;
        };
        // the navigation test keeps the server side as it is, so only the browser side differs
        $server = ['cache', 'cartspeed', 'instantcart'];
        $links = (string) Configuration::get('SPC_NAV_LINKS');

        return [
            'pages' => $pages,
            'home' => $context->link->getPageLink('index', true),
            'product' => $buyable ? (int) $buyable[0]['id_product'] : 0,
            'cache' => SpcCacheBackend::current(),
            'links' => $links !== '' ? $links : '#header .top-menu a[data-depth="0"]',
            'enabled' => [
                'cache' => SpcCacheBackend::current() !== SpcCacheBackend::OFF,
                'smartprefetch' => $on('SPC_SP_ENABLED'),
                'instantnav' => $on('SPC_NAV_ENABLED'),
                'instantcart' => $on('SPC_IC_ENABLED'),
                'cartspeed' => (bool) Configuration::get('SPC_CS_ENABLED'),
            ],
            'tokens' => [
                'off' => self::token([]),
                'all' => self::token(self::PARTS),
                'nav_off' => self::token($server),
                'nav_smartprefetch' => self::token(array_merge($server, ['smartprefetch'])),
                'nav_instantnav' => self::token(array_merge($server, ['instantnav'])),
            ],
            'cookie' => self::COOKIE,
            'expires' => self::LIFETIME,
        ];
    }

    /** Best sellers first; with $buyable, only ones a list may add without a choice. */
    protected static function products($idShop, $idLang, $limit, $buyable)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT p.id_product, pl.name FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop = ' . (int) $idShop . ')
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = ' . (int) $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'product_sale` sale ON (sale.id_product = p.id_product)
            ' . ($buyable ? 'LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON (sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int) $idShop . ')' : '') . '
            WHERE ps.active = 1 AND ps.visibility IN (\'both\', \'catalog\')
            ' . ($buyable ? 'AND ps.available_for_order = 1 AND ps.cache_default_attribute = 0 AND p.customizable = 0 AND (IFNULL(sa.quantity, 0) > 0 OR ps.out_of_stock = 1)' : '') . '
            ORDER BY IFNULL(sale.quantity, 0) DESC, p.date_add DESC LIMIT ' . (int) $limit
        );

        return is_array($rows) ? $rows : [];
    }

    /* ------------------------------------------------------------------ *
     *  Measuring
     * ------------------------------------------------------------------ */

    /**
     * One page, with SpeedPack off and on, alternating so a busy moment on the server does not
     * land on one side only. A first request warms PHP and the database for both.
     *
     * @return array ['off' => ms, 'on' => ms] (median server answer time) or ['error' => text]
     */
    public static function page($url, array $tokens)
    {
        $want = ['off' => self::label([]), 'on' => self::label(self::PARTS)];
        // a first request warms PHP, the database and the data cache for both sides
        $first = self::request(self::bust($url), $tokens['all']);
        if (!$first['ok']) {
            return ['error' => $first['error']];
        }
        $times = ['off' => [], 'on' => []];
        for ($i = 0; $i < 3; ++$i) {
            // alternating, and the order turns each round, so a busy moment lands on both sides
            foreach ($i % 2 ? ['on', 'off'] : ['off', 'on'] as $side) {
                $r = self::request(self::bust($url), $tokens[$side === 'off' ? 'off' : 'all']);
                if (!$r['ok']) {
                    return ['error' => $r['error']];
                }
                if ($r['audit'] !== $want[$side]) {
                    return ['error' => 'page_cache', 'code' => 'page_cache'];
                }
                $times[$side][] = $r['ttfb'];
            }
        }

        return ['off' => self::median($times['off']), 'on' => self::median($times['on']), 'verified' => true];
    }

    /**
     * The address with a query no cache has seen, so a page cache in front of the shop (keyed on
     * the full address) has to pass the request on to PrestaShop.
     */
    public static function bust($url)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'spc_t=' . bin2hex(random_bytes(6));
    }

    /**
     * Adding to the cart: PrestaShop's cart controller against the lean endpoint, three times
     * each, from a fresh visitor session. The product is taken out again afterwards.
     *
     * @return array ['core' => ms, 'lean' => ms] or ['error' => text]
     */
    public static function cart($context, $idProduct, $token)
    {
        if ($idProduct <= 0) {
            return ['error' => 'no product can be added from a list'];
        }
        $jar = [];
        $home = self::request($context->link->getPageLink('index', true), $token, $jar);
        if (!$home['ok'] || !preg_match('/"static_token"\s*:\s*"([a-f0-9]{32})"/', $home['body'], $m)) {
            return ['error' => 'the shop page did not give a cart token'];
        }
        $static = $m[1];
        $cartUrl = $context->link->getPageLink('cart', true);
        $leanUrl = $context->link->getModuleLink('speedpackcore', 'add', [], true);
        $add = ['token' => $static, 'id_product' => $idProduct, 'id_product_attribute' => 0, 'qty' => 1];
        // the first add makes the cart; it is not timed, so both sides add to an existing cart
        $first = self::request($leanUrl, $token, $jar, $add);
        if (!$first['ok'] || strpos($first['body'], '"ok":true') === false) {
            return ['error' => 'the product could not be added'];
        }
        $times = ['core' => [], 'lean' => []];
        for ($i = 0; $i < 3; ++$i) {
            $core = self::request($cartUrl, $token, $jar, $add + ['add' => 1, 'action' => 'update', 'ajax' => 1]);
            $lean = self::request($leanUrl, $token, $jar, $add);
            if (!$core['ok'] || !$lean['ok'] || strpos($lean['body'], '"ok":true') === false) {
                return ['error' => 'the product could not be added'];
            }
            $times['core'][] = $core['total'];
            $times['lean'][] = $lean['total'];
        }
        // the test cart is left empty
        self::request($context->link->getModuleLink('speedpackcore', 'remove', [], true), $token, $jar, ['token' => $static, 'lines' => json_encode([['p' => $idProduct, 'a' => 0, 'c' => 0]])]);

        return ['core' => self::median($times['core']), 'lean' => self::median($times['lean'])];
    }

    /**
     * CartSpeed: the address lookups a cart page makes, with the remembered answers and without,
     * counted as database queries and timed.
     *
     * @return array ['off' => [queries, ms], 'on' => [queries, ms], 'lookups' => n] or ['error' => text]
     */
    public static function cartSpeed($context)
    {
        if (!property_exists('Address', 'spc_exists')) {
            return ['error' => 'the CartSpeed override is not installed'];
        }
        $id = (int) Db::getInstance()->getValue('SELECT id_address FROM `' . _DB_PREFIX_ . 'address` WHERE deleted = 0 ORDER BY id_address DESC');
        if ($id <= 0) {
            return ['error' => 'the shop has no address to look up yet'];
        }
        $lookups = 73;
        $group = (int) $context->shop->id_shop_group;
        $shop = (int) $context->shop->id;
        $was = Configuration::get('SPC_CS_ENABLED');
        $out = ['lookups' => $lookups];
        foreach (['off' => 0, 'on' => 1] as $side => $flag) {
            // in memory only, for this request: the saved setting is not touched
            Configuration::set('SPC_CS_ENABLED', $flag, $group, $shop);
            $before = self::questions();
            $start = microtime(true);
            for ($i = 0; $i < $lookups; ++$i) {
                Address::addressExists($id);
            }
            $ms = (microtime(true) - $start) * 1000;
            $out[$side] = ['queries' => max(0, self::questions() - $before - 1), 'ms' => round($ms, 2)];
        }
        Configuration::set('SPC_CS_ENABLED', $was, $group, $shop);

        return $out;
    }

    /** Queries this database connection has run so far (MySQL and MariaDB keep the count). */
    protected static function questions()
    {
        $row = Db::getInstance()->getRow('SHOW SESSION STATUS LIKE \'Questions\'');

        return is_array($row) && isset($row['Value']) ? (int) $row['Value'] : 0;
    }

    /**
     * One request to the shop as a first-time visitor would make it, with the audit cookie.
     * $jar keeps the visitor's cookies between requests; $post makes it a form POST.
     */
    public static function request($url, $token, array &$jar = [], ?array $post = null)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'the server has no cURL'];
        }
        $cookies = [self::COOKIE . '=' . $token];
        foreach ($jar as $name => $value) {
            $cookies[] = $name . '=' . $value;
        }
        $headers = [];
        $audit = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'SpeedPackCore/1.4 (+speed audit)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/json;q=0.9', 'Cookie: ' . implode('; ', $cookies)],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers, &$audit) {
                if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $m)) {
                    $headers[$m[1]] = $m[2];
                }
                if (stripos($line, self::HEADER . ':') === 0) {
                    $audit = trim(substr($line, strlen(self::HEADER) + 1));
                }

                return strlen($line);
            },
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000;
        $total = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $error = curl_error($ch);
        curl_close($ch);
        foreach ($headers as $name => $value) {
            $jar[$name] = $value;
        }
        if ($body === false || $code < 200 || $code >= 400) {
            return ['ok' => false, 'error' => $body === false ? $error : 'HTTP ' . $code];
        }

        return ['ok' => true, 'ttfb' => round($ttfb), 'total' => round($total), 'body' => (string) $body, 'audit' => $audit];
    }

    protected static function median(array $values)
    {
        sort($values);
        $n = count($values);
        if (!$n) {
            return 0;
        }

        return $n % 2 ? $values[(int) ($n / 2)] : round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }

    /* ------------------------------------------------------------------ *
     *  History
     * ------------------------------------------------------------------ */

    /** @return array past audits, oldest first */
    public static function history()
    {
        $list = json_decode((string) Configuration::get(self::K_HISTORY), true);

        return is_array($list) ? $list : [];
    }

    /**
     * Keep one audit's results: numbers only, in the known shape, so nothing posted can be
     * stored as anything else.
     */
    public static function save(array $raw)
    {
        $n = function ($v) {
            return is_numeric($v) ? round((float) $v, 1) : null;
        };
        $pair = function ($v, $a, $b) use ($n) {
            return is_array($v) ? [$a => $n(isset($v[$a]) ? $v[$a] : null), $b => $n(isset($v[$b]) ? $v[$b] : null)] : null;
        };
        $run = [
            'at' => date('Y-m-d H:i'),
            'cache' => preg_replace('/[^a-z]/', '', (string) (isset($raw['cache']) ? $raw['cache'] : '')),
            'pages' => $pair(isset($raw['pages']) ? $raw['pages'] : null, 'off', 'on'),
            'cart' => $pair(isset($raw['cart']) ? $raw['cart'] : null, 'core', 'lean'),
            'cartspeed' => $pair(isset($raw['cartspeed']) ? $raw['cartspeed'] : null, 'off', 'on'),
            'nav' => [],
        ];
        foreach (['off', 'smartprefetch', 'instantnav', 'all'] as $mode) {
            $run['nav'][$mode] = isset($raw['nav'][$mode]) ? $n($raw['nav'][$mode]) : null;
        }
        $list = self::history();
        $list[] = $run;
        $list = array_slice($list, -self::KEEP);
        Configuration::updateValue(self::K_HISTORY, json_encode($list));
        Configuration::updateValue(self::K_DONE, 1);

        return $run;
    }
}
