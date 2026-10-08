<?php
/**
 * SpeedPack Core - the page cache warmed up again: pages a change cleared are opened in the
 * background, so the next visitor gets them ready instead of waiting for PrestaShop to build them.
 *
 *   - a change (a product, its stock or price, a category...) puts the addresses of the pages it
 *     cleared in a queue; "Clear cache" and the like queue the pages that were kept;
 *   - after a shop page has been sent (the visitor no longer waits: fastcgi_finish_request), a few
 *     queued pages are opened, at most one warm-up at a time;
 *   - the settings page warms the whole catalogue in steps, and a cron address works the queue and
 *     then the catalogue, for shops on servers without fastcgi_finish_request.
 *
 * Each page is opened as a computer and, when the page cache keeps phones apart, as a phone, with
 * the picture formats a current browser takes. Its own requests are marked, so they never start a
 * warm-up of their own.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcWarm
{
    public const K_ENABLED = 'SPC_PC_WARM';
    public const K_QUEUE = 'SPC_PC_WARM_QUEUE';
    public const K_LOCK = 'SPC_PC_WARM_AT';
    public const K_CURSOR = 'SPC_PC_WARM_CURSOR';

    /** the longest queue kept (the most recent addresses) */
    public const MAX_QUEUE = 400;
    /** pages warmed after one visitor's page, and the time it may take */
    public const PER_VISIT = 6;
    public const VISIT_SECONDS = 8;

    /** marks the warm-up's own requests */
    public const AGENT = 'SpeedPackCore-Warm';

    public const ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
    public const UA_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 SpeedPackCore-Warm';
    public const UA_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36 SpeedPackCore-Warm';

    public static function enabled()
    {
        $v = Configuration::get(self::K_ENABLED);

        return SpcPageCache::enabled() && ($v === false || (int) $v === 1);
    }

    /** Whether this request is one of the warm-up's own. */
    public static function isWarmRequest()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) && strpos((string) $_SERVER['HTTP_USER_AGENT'], self::AGENT) !== false;
    }

    /* ------------------------------------------------------------------ *
     *  The queue
     * ------------------------------------------------------------------ */

    public static function queue()
    {
        $q = json_decode((string) Configuration::get(self::K_QUEUE), true);

        return is_array($q) ? array_values(array_filter($q, 'is_string')) : [];
    }

    /** Addresses added at the end, each once; the oldest go past MAX_QUEUE. */
    public static function push(array $urls)
    {
        if (!$urls || !self::enabled()) {
            return 0;
        }
        $q = self::queue();
        foreach ($urls as $u) {
            $u = (string) $u;
            if ($u !== '' && preg_match('#^https?://#i', $u) && !in_array($u, $q, true)) {
                $q[] = $u;
            }
        }
        $q = array_slice($q, -self::MAX_QUEUE);
        Configuration::updateGlobalValue(self::K_QUEUE, json_encode($q));

        return count($q);
    }

    /** The first $n addresses, taken out of the queue. */
    public static function take($n)
    {
        $q = self::queue();
        $out = array_slice($q, 0, $n);
        Configuration::updateGlobalValue(self::K_QUEUE, json_encode(array_slice($q, $n)));

        return $out;
    }

    /** The addresses of kept pages (index rows), made absolute with their shop's address. */
    public static function urlsOf(array $rows)
    {
        $out = [];
        foreach ($rows as $r) {
            $path = isset($r['url']) ? (string) $r['url'] : '';
            if ($path === '' || $path[0] !== '/') {
                continue;
            }
            $base = self::base(isset($r['id_shop']) ? (int) $r['id_shop'] : 0);
            if ($base !== '') {
                // the audit's and campaigns' own parameters never make a page of their own
                $parts = explode('?', $path, 2);
                $keep = '';
                if (isset($parts[1])) {
                    parse_str($parts[1], $q);
                    $q = array_filter($q, function ($k) { return !preg_match(SpcPageCache::IGNORED, (string) $k); }, ARRAY_FILTER_USE_KEY);
                    $keep = $q ? '?' . http_build_query($q) : '';
                }
                $out[] = $base . $parts[0] . $keep;
            }
        }

        return array_values(array_unique($out));
    }

    /** @var array shop id => scheme and host, worked out once (tests may set them) */
    public static $bases = [];

    /** scheme and host of a shop (no trailing slash) */
    protected static function base($idShop)
    {
        $memo = &self::$bases;
        if (!isset($memo[$idShop])) {
            $memo[$idShop] = '';
            try {
                $shop = $idShop ? new Shop($idShop) : Context::getContext()->shop;
                $domain = Configuration::get('PS_SSL_ENABLED') ? $shop->domain_ssl : $shop->domain;
                if ($domain) {
                    $memo[$idShop] = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . $domain;
                }
            } catch (Throwable $e) {
                $memo[$idShop] = '';
            }
        }

        return $memo[$idShop];
    }

    /* ------------------------------------------------------------------ *
     *  Warming
     * ------------------------------------------------------------------ */

    /**
     * One address opened as a computer, and as a phone when phones get pages of their own.
     *
     * @return array ['url', 'states' => ['HIT'|'MISS'|'BYPASS …'|'' ...], 'ms' => total]
     */
    public static function warm($url)
    {
        $agents = [self::UA_DESKTOP];
        if ((int) Configuration::get(SpcPageCache::K_MOBILE)) {
            $agents[] = self::UA_PHONE;
        }
        $states = [];
        $ms = 0;
        foreach ($agents as $ua) {
            $r = self::open($url, $ua);
            $states[] = $r['state'];
            $ms += $r['ms'];
        }

        return ['url' => $url, 'states' => $states, 'ms' => $ms];
    }

    protected static function open($url, $ua)
    {
        if (!function_exists('curl_init')) {
            return ['state' => 'no cURL', 'ms' => 0];
        }
        $state = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Accept: ' . self::ACCEPT],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$state) {
                if (stripos($line, 'X-SpeedPack-Cache:') === 0) {
                    $state = trim(substr($line, 18));
                }

                return strlen($line);
            },
        ]);
        curl_exec($ch);
        $ms = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['state' => $code >= 200 && $code < 400 ? $state : 'HTTP ' . $code, 'ms' => $ms];
    }

    /**
     * Queued pages warmed, until $max pages or $seconds are used.
     *
     * @return int pages warmed
     */
    public static function work($max, $seconds)
    {
        $t0 = microtime(true);
        $n = 0;
        while ($n < $max && microtime(true) - $t0 < $seconds) {
            $urls = self::take(1);
            if (!$urls) {
                break;
            }
            self::warm($urls[0]);
            ++$n;
        }

        return $n;
    }

    /**
     * After a shop page is sent: a few queued pages warmed, when nothing else is warming. Only
     * where PHP can end the visitor's request first, so no visitor ever waits for it.
     */
    public static function afterVisit()
    {
        if (!self::enabled() || self::isWarmRequest() || !function_exists('fastcgi_finish_request') || !self::queue()) {
            return false;
        }
        $now = time();
        if ((int) Configuration::get(self::K_LOCK) > $now - 30) {
            return false;
        }
        Configuration::updateGlobalValue(self::K_LOCK, $now);
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            @set_time_limit(30);
            ignore_user_abort(true);
            SpcWarm::work(SpcWarm::PER_VISIT, SpcWarm::VISIT_SECONDS);
            Configuration::updateGlobalValue(SpcWarm::K_LOCK, 0);
        });

        return true;
    }

    /* ------------------------------------------------------------------ *
     *  The whole catalogue
     * ------------------------------------------------------------------ */

    /**
     * Every page the page cache keeps, in every active language: the home page, the CMS pages,
     * the categories and the products (best sellers first), brands and suppliers when kept.
     *
     * @return string[] addresses
     */
    public static function catalogue($context, $limit = 3000)
    {
        $idShop = (int) $context->shop->id;
        $link = $context->link;
        $pages = array_filter(explode(',', (string) Configuration::get(SpcPageCache::K_PAGES))) ?: SpcPageCache::PAGES;
        $db = Db::getInstance();
        $out = [];
        foreach (Language::getLanguages(true, $idShop) as $lang) {
            $l = (int) $lang['id_lang'];
            if (in_array('index', $pages, true)) {
                $out[] = $link->getPageLink('index', true, $l);
            }
            if (in_array('category', $pages, true)) {
                foreach ($db->executeS('SELECT c.id_category FROM `' . _DB_PREFIX_ . 'category` c INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . ')
                    WHERE c.active = 1 AND c.id_category NOT IN (' . (int) Configuration::get('PS_ROOT_CATEGORY') . ', ' . (int) Configuration::get('PS_HOME_CATEGORY') . ') ORDER BY c.level_depth, c.position') ?: [] as $r) {
                    $out[] = $link->getCategoryLink((int) $r['id_category'], null, $l);
                }
            }
            if (in_array('cms', $pages, true)) {
                foreach ($db->executeS('SELECT c.id_cms FROM `' . _DB_PREFIX_ . 'cms` c INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON (cs.id_cms = c.id_cms AND cs.id_shop = ' . $idShop . ') WHERE c.active = 1 AND c.indexation = 1') ?: [] as $r) {
                    $out[] = $link->getCMSLink((int) $r['id_cms'], null, null, $l);
                }
            }
            if (in_array('product', $pages, true)) {
                foreach ($db->executeS('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product_shop` p LEFT JOIN `' . _DB_PREFIX_ . 'product_sale` s ON (s.id_product = p.id_product)
                    WHERE p.id_shop = ' . $idShop . ' AND p.active = 1 AND p.visibility IN (\'both\', \'catalog\') ORDER BY IFNULL(s.quantity, 0) DESC, p.date_add DESC LIMIT ' . (int) $limit) ?: [] as $r) {
                    $out[] = $link->getProductLink((int) $r['id_product'], null, null, null, $l);
                }
            }
            foreach (['manufacturer' => 'getManufacturerLink', 'supplier' => 'getSupplierLink'] as $page => $fn) {
                if (in_array($page, $pages, true)) {
                    foreach ($db->executeS('SELECT id_' . $page . ' id FROM `' . _DB_PREFIX_ . $page . '` WHERE active = 1') ?: [] as $r) {
                        $out[] = $link->$fn((int) $r['id'], null, $l);
                    }
                }
            }
            foreach (['new-products', 'prices-drop', 'best-sales'] as $page) {
                if (in_array($page, $pages, true)) {
                    $out[] = $link->getPageLink($page, true, $l);
                }
            }
        }

        return array_slice(array_values(array_unique(array_filter($out))), 0, $limit);
    }

    /**
     * For cron: the queue first, then the catalogue from where the last run stopped, for at most
     * $seconds. Answers what was done.
     */
    public static function cron($context, $seconds = 25)
    {
        $t0 = microtime(true);
        $queued = self::work(1000, $seconds);
        $warmed = 0;
        $all = self::catalogue($context);
        $cursor = (int) Configuration::get(self::K_CURSOR);
        if ($cursor >= count($all)) {
            $cursor = 0;
        }
        while ($cursor < count($all) && microtime(true) - $t0 < $seconds) {
            self::warm($all[$cursor]);
            ++$cursor;
            ++$warmed;
        }
        Configuration::updateGlobalValue(self::K_CURSOR, $cursor >= count($all) ? 0 : $cursor);

        return ['queued' => $queued, 'catalogue' => $warmed, 'cursor' => $cursor, 'total' => count($all)];
    }

    /**
     * The settings page's steps (views/js/warm.js): "plan" lists the catalogue's addresses,
     * "batch" warms the ones posted (a few at a time, so no step runs long). Answers JSON.
     */
    public static function ajax($context, $op)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        @set_time_limit(60);
        if (!SpcPageCache::enabled()) {
            $answer = ['error' => 'off'];
        } elseif ($op === 'plan') {
            $answer = ['urls' => self::catalogue($context)];
        } elseif ($op === 'batch') {
            $urls = json_decode((string) Tools::getValue('urls'), true);
            $base = rtrim($context->shop->getBaseURL(true), '/');
            $host = (string) parse_url($base, PHP_URL_HOST);
            $out = [];
            foreach (array_slice(is_array($urls) ? $urls : [], 0, 8) as $u) {
                // only this shop's own pages
                if (is_string($u) && strcasecmp((string) parse_url($u, PHP_URL_HOST), $host) === 0) {
                    $out[] = self::warm($u);
                }
            }
            $answer = ['done' => $out];
        } else {
            $answer = ['error' => 'unknown'];
        }
        echo json_encode($answer);
        exit;
    }

    /** The cron address's key: the shop's audit key, hashed for this use. */
    public static function token()
    {
        return Tools::substr(hash_hmac('sha256', 'spc-warm', SpcAudit::key()), 0, 24);
    }
}
