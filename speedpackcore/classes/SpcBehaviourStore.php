<?php
/**
 * SpeedPack Core - Behaviour: where shopper visits are kept, and the questions asked of them.
 *
 * Three tables:
 *   spc_bh_session  one visit: when, device, where it came from, how far it got (cart, checkout
 *                   step, order) and its path, a string of page keys (" index category:3 product:7 ")
 *   spc_bh_view     one page shown: which page, when, engaged time, scroll depth and its Core Web
 *                   Vitals (LCP, TTFB, FCP of a page load; INP and CLS of every page shown)
 *   spc_bh_event    what happened on it: add to cart, a checkout step, pay, an error, a search
 *
 * A page key is the page type PrestaShop gives the page (index, category, product, cart,
 * checkout…), with the object's id where there is one: "product:7". Times are Unix seconds, so
 * reports can be cut into buckets (15 min, hour, day, week) in the shop's time zone.
 *
 * Nothing here knows about requests or cookies: the collect controller hands in what the shop
 * window sent, and the session it belongs to.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcBehaviourStore
{
    /** a visit ends after half an hour without a page or an action */
    public const GAP = 1800;

    /** most page views kept per visit (anything past it is a script, not a shopper) */
    public const MAX_VIEWS = 400;

    /** engaged time counted per page at most */
    public const MAX_MS = 3600000;

    public const DEVICES = ['desktop', 'tablet', 'mobile'];

    /** checkout steps in order, as the shop window names them */
    public const STEPS = ['personal' => 1, 'addresses' => 2, 'delivery' => 3, 'payment' => 4, 'pay' => 5];

    public const EVENTS = ['cart', 'step', 'pay', 'error', 'search', 'reorder'];

    /** engaged time buckets of the time-on-page histogram, in seconds */
    public const DWELL = [5, 15, 30, 60, 180, 600];

    /** sessions read into PHP for paths and funnels at most (the most recent ones) */
    public const SAMPLE = 20000;

    /** Core Web Vitals: Google's limits for good and poor (CLS in thousandths), and the caps kept */
    public const VITALS = [
        'lcp' => ['col' => 'lcp_ms', 'good' => 2500, 'poor' => 4000, 'max' => 120000],
        'inp' => ['col' => 'inp_ms', 'good' => 200, 'poor' => 500, 'max' => 60000],
        'cls' => ['col' => 'cls', 'good' => 100, 'poor' => 250, 'max' => 65000],
        'ttfb' => ['col' => 'ttfb_ms', 'good' => 800, 'poor' => 1800, 'max' => 120000],
        'fcp' => ['col' => 'fcp_ms', 'good' => 1800, 'poor' => 3000, 'max' => 120000],
    ];

    public static function table($name)
    {
        return '`' . _DB_PREFIX_ . 'spc_bh_' . $name . '`';
    }

    /* ------------------------------------------------------------------ *
     *  Tables
     * ------------------------------------------------------------------ */

    public static function install()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';
        $sql = [
            'CREATE TABLE IF NOT EXISTS ' . self::table('session') . ' (
                `id_session` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `started` INT UNSIGNED NOT NULL,
                `last` INT UNSIGNED NOT NULL,
                `views` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `active_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `device` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `source` VARCHAR(16) NOT NULL DEFAULT \'direct\',
                `ref` VARCHAR(64) NOT NULL DEFAULT \'\',
                `campaign` VARCHAR(64) NOT NULL DEFAULT \'\',
                `ordered_before` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
                `cart_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `checkout` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `ordered_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `total` DECIMAL(20,2) NOT NULL DEFAULT 0,
                `entry` VARCHAR(48) NOT NULL DEFAULT \'\',
                `exit` VARCHAR(48) NOT NULL DEFAULT \'\',
                `path` TEXT NOT NULL,
                PRIMARY KEY (`id_session`),
                KEY `shop_started` (`id_shop`, `started`),
                KEY `cart` (`id_cart`),
                KEY `order` (`id_order`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS ' . self::table('view') . ' (
                `id_view` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_session` INT UNSIGNED NOT NULL,
                `seq` SMALLINT UNSIGNED NOT NULL,
                `vkey` CHAR(8) NOT NULL,
                `at` INT UNSIGNED NOT NULL,
                `page` VARCHAR(48) NOT NULL,
                `url` VARCHAR(255) NOT NULL DEFAULT \'\',
                `nav` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `active_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `scroll` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `lcp_ms` INT UNSIGNED NULL,
                `inp_ms` INT UNSIGNED NULL,
                `cls` SMALLINT UNSIGNED NULL,
                `ttfb_ms` INT UNSIGNED NULL,
                `fcp_ms` INT UNSIGNED NULL,
                PRIMARY KEY (`id_view`),
                UNIQUE KEY `session_vkey` (`id_session`, `vkey`),
                KEY `session_seq` (`id_session`, `seq`),
                KEY `page_at` (`page`, `at`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS ' . self::table('event') . ' (
                `id_event` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_session` INT UNSIGNED NOT NULL,
                `id_view` INT UNSIGNED NOT NULL DEFAULT 0,
                `at` INT UNSIGNED NOT NULL,
                `type` VARCHAR(12) NOT NULL,
                `detail` VARCHAR(128) NOT NULL DEFAULT \'\',
                `value` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_event`),
                KEY `session` (`id_session`),
                KEY `type_at` (`type`, `at`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($sql as $q) {
            if (!Db::getInstance()->execute($q)) {
                return false;
            }
        }

        return self::migrate();
    }

    /** Columns added after a table was created (1.6.0: Core Web Vitals). */
    public static function migrate()
    {
        $db = Db::getInstance();
        // 1.6.0: Core Web Vitals; 1.6.1: the cart a visit filled, to show its path on carts and orders
        $add = [
            'view' => [
                'lcp_ms' => 'INT UNSIGNED NULL', 'inp_ms' => 'INT UNSIGNED NULL', 'cls' => 'SMALLINT UNSIGNED NULL',
                'ttfb_ms' => 'INT UNSIGNED NULL', 'fcp_ms' => 'INT UNSIGNED NULL',
            ],
            'session' => ['id_cart' => 'INT UNSIGNED NOT NULL DEFAULT 0, ADD KEY `cart` (`id_cart`), ADD KEY `order` (`id_order`)'],
        ];
        foreach ($add as $table => $cols) {
            $have = [];
            foreach ($db->executeS('SHOW COLUMNS FROM ' . self::table($table)) ?: [] as $c) {
                $have[$c['Field']] = true;
            }
            foreach ($cols as $col => $type) {
                if (!isset($have[$col]) && !$db->execute('ALTER TABLE ' . self::table($table) . ' ADD `' . $col . '` ' . $type)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function uninstall()
    {
        foreach (['event', 'view', 'session'] as $t) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS ' . self::table($t));
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     *  Recording
     * ------------------------------------------------------------------ */

    /**
     * What one shop window sent: page views, engaged time and events, in order.
     *
     * @param array $state ['id' => session id, 'last' => last activity] from the visitor's cookie
     * @param array $messages the shop window's messages (see views/js/behaviour.js)
     * @param array $env id_shop, host, id_customer (0 when not linked), returning (callable or bool)
     * @param int $now
     *
     * @return array the new state for the cookie
     */
    public static function collect(array $state, array $messages, array $env, $now)
    {
        $db = Db::getInstance();
        $id = isset($state['id']) ? (int) $state['id'] : 0;
        $last = isset($state['last']) ? (int) $state['last'] : 0;
        $session = $id ? $db->getRow('SELECT * FROM ' . self::table('session') . ' WHERE id_session = ' . $id) : false;
        // an ongoing visit of this shop; anything else starts a new one with its next page view
        if (!$session || (int) $session['id_shop'] !== (int) $env['id_shop'] || $now - max($last, (int) $session['last']) > self::GAP) {
            $session = false;
        }
        $views = [];
        foreach (array_slice($messages, 0, 40) as $m) {
            if (!is_array($m) || !isset($m['t'])) {
                continue;
            }
            if ($m['t'] === 'v') {
                // only a real page view opens a visit
                if (self::page(isset($m['p']) ? $m['p'] : '', 0) === '' || !isset($m['k']) || self::vkey($m['k']) === '') {
                    continue;
                }
                if (!$session) {
                    $session = self::open($m, $env, $now);
                }
                $view = self::view($session, $m, $now);
                if ($view) {
                    $views[$view['vkey']] = $view['id_view'];
                }
                continue;
            }
            if (!$session) {
                continue;
            }
            $k = isset($m['k']) ? self::vkey($m['k']) : '';
            if ($k !== '' && !isset($views[$k])) {
                $views[$k] = (int) $db->getValue('SELECT id_view FROM ' . self::table('view') . ' WHERE id_session = ' . (int) $session['id_session'] . ' AND vkey = \'' . $k . '\'');
            }
            $idView = $k !== '' ? $views[$k] : 0;
            if ($m['t'] === 't' && $idView) {
                self::time($session, $idView, $m);
            } elseif ($m['t'] === 'e') {
                self::event($session, $idView, $m, $now);
            }
        }
        if (!$session) {
            return ['id' => 0, 'last' => 0];
        }
        // the visit's cart (made during the visit, or carried over from an earlier one)
        $cart = isset($env['id_cart']) ? (int) $env['id_cart'] : 0;
        $db->execute('UPDATE ' . self::table('session') . ' SET last = GREATEST(last, ' . (int) $now . ')' . ($cart > 0 ? ', id_cart = ' . $cart : '') . ' WHERE id_session = ' . (int) $session['id_session']);

        return ['id' => (int) $session['id_session'], 'last' => (int) $now];
    }

    /** A new visit, from its first page view. */
    protected static function open(array $m, array $env, $now)
    {
        $db = Db::getInstance();
        list($source, $ref, $campaign) = self::source($m, isset($env['host']) ? $env['host'] : '');
        $returning = isset($env['returning']) ? $env['returning'] : false;
        if (is_callable($returning)) {
            $returning = $returning();
        }
        $row = [
            'id_shop' => (int) $env['id_shop'],
            'started' => (int) $now,
            'last' => (int) $now,
            'device' => isset($m['d']) ? max(0, min(2, (int) $m['d'])) : 0,
            'source' => $source,
            'ref' => $ref,
            'campaign' => $campaign,
            'ordered_before' => $returning ? 1 : 0,
            'id_customer' => isset($env['id_customer']) ? (int) $env['id_customer'] : 0,
            'id_cart' => isset($env['id_cart']) ? (int) $env['id_cart'] : 0,
            'path' => ' ',
        ];
        $cols = [];
        $vals = [];
        foreach ($row as $c => $v) {
            $cols[] = '`' . $c . '`';
            $vals[] = is_int($v) ? (string) $v : '\'' . self::esc($v) . '\'';
        }
        $db->execute('INSERT INTO ' . self::table('session') . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
        $row['id_session'] = (int) $db->Insert_ID();
        $row += ['views' => 0, 'cart_at' => 0, 'checkout' => 0, 'ordered_at' => 0, 'entry' => '', 'exit' => ''];

        return $row;
    }

    /** One page shown; returns the stored row (id_view, vkey) or null. */
    protected static function view(array &$session, array $m, $now)
    {
        $db = Db::getInstance();
        $page = self::page(isset($m['p']) ? $m['p'] : '', isset($m['i']) ? $m['i'] : 0);
        $k = isset($m['k']) ? self::vkey($m['k']) : '';
        if ($page === '' || $k === '' || (int) $session['views'] >= self::MAX_VIEWS) {
            return null;
        }
        $sid = (int) $session['id_session'];
        $known = $db->getValue('SELECT id_view FROM ' . self::table('view') . ' WHERE id_session = ' . $sid . ' AND vkey = \'' . $k . '\'');
        if ($known) {
            return ['id_view' => (int) $known, 'vkey' => $k];
        }
        $seq = (int) $session['views'] + 1;
        $url = isset($m['u']) ? self::clip((string) $m['u'], 255) : '';
        $nav = isset($m['n']) ? max(0, min(2, (int) $m['n'])) : 0;
        $db->execute('INSERT IGNORE INTO ' . self::table('view') . ' (id_session, seq, vkey, at, page, url, nav) VALUES ('
            . $sid . ', ' . $seq . ', \'' . $k . '\', ' . (int) $now . ', \'' . self::esc($page) . '\', \'' . self::esc($url) . '\', ' . $nav . ')');
        $idView = (int) $db->Insert_ID();
        if (!$idView) {
            return null;
        }
        // the path keeps each page once per stretch: product, product, cart reads "product cart"
        $path = (string) $session['path'];
        $tail = trim(substr($path, -50));
        $tail = substr($tail, (int) strrpos(' ' . $tail, ' '));
        $append = $tail !== $page && strlen($path) < 4000 ? $page . ' ' : '';
        $db->execute('UPDATE ' . self::table('session') . ' SET views = ' . $seq
            . ($seq === 1 ? ', entry = \'' . self::esc($page) . '\'' : '')
            . ', `exit` = \'' . self::esc($page) . '\''
            . ($append !== '' ? ', path = CONCAT(path, \'' . self::esc($append) . '\')' : '')
            . ' WHERE id_session = ' . $sid);
        $session['views'] = $seq;
        $session['path'] = $path . $append;
        if ($seq === 1) {
            $session['entry'] = $page;
        }
        $session['exit'] = $page;
        if (strpos($page, 'order-confirmation') === 0) {
            self::ordered($sid, 0, 0, $now);
        }

        return ['id_view' => $idView, 'vkey' => $k];
    }

    /** Engaged time and scroll depth of a page so far (the shop window sends running totals). */
    protected static function time(array $session, $idView, array $m)
    {
        $db = Db::getInstance();
        $ms = isset($m['ms']) ? max(0, min(self::MAX_MS, (int) $m['ms'])) : 0;
        $scroll = isset($m['s']) ? max(0, min(100, (int) $m['s'])) : 0;
        $set = ['active_ms = GREATEST(active_ms, ' . $ms . ')', 'scroll = GREATEST(scroll, ' . $scroll . ')'];
        // the browser reports running values: LCP, INP and CLS only grow, TTFB and FCP are fixed
        foreach (['l' => 'lcp', 'in' => 'inp', 'c' => 'cls', 'b' => 'ttfb', 'f' => 'fcp'] as $k => $name) {
            if (isset($m[$k]) && is_numeric($m[$k])) {
                $v = max(0, min(self::VITALS[$name]['max'], (int) $m[$k]));
                $col = self::VITALS[$name]['col'];
                $set[] = in_array($name, ['ttfb', 'fcp'], true)
                    ? '`' . $col . '` = COALESCE(`' . $col . '`, ' . $v . ')'
                    : '`' . $col . '` = GREATEST(COALESCE(`' . $col . '`, 0), ' . $v . ')';
            }
        }
        $db->execute('UPDATE ' . self::table('view') . ' SET ' . implode(', ', $set) . ' WHERE id_view = ' . (int) $idView);
        $sid = (int) $session['id_session'];
        $db->execute('UPDATE ' . self::table('session') . ' SET active_ms = (SELECT COALESCE(SUM(active_ms), 0) FROM ' . self::table('view') . ' WHERE id_session = ' . $sid . ') WHERE id_session = ' . $sid);
    }

    protected static function event(array $session, $idView, array $m, $now)
    {
        $type = isset($m['e']) ? (string) $m['e'] : '';
        if (!in_array($type, self::EVENTS, true)) {
            return;
        }
        $detail = isset($m['d']) ? self::clip(preg_replace('/\s+/u', ' ', (string) $m['d']), 128) : '';
        $value = isset($m['x']) ? max(-1, min(2000000000, (int) $m['x'])) : 0;
        $sid = (int) $session['id_session'];
        $db = Db::getInstance();
        if ($type === 'step' || $type === 'pay') {
            $step = $type === 'pay' ? 'pay' : $detail;
            if (!isset(self::STEPS[$step])) {
                return;
            }
            $detail = $step;
            $db->execute('UPDATE ' . self::table('session') . ' SET checkout = GREATEST(checkout, ' . self::STEPS[$step] . ') WHERE id_session = ' . $sid);
        }
        if ($type === 'cart') {
            $db->execute('UPDATE ' . self::table('session') . ' SET cart_at = IF(cart_at = 0, ' . (int) $now . ', cart_at) WHERE id_session = ' . $sid);
        }
        $db->execute('INSERT INTO ' . self::table('event') . ' (id_session, id_view, at, type, detail, value) VALUES ('
            . $sid . ', ' . (int) $idView . ', ' . (int) $now . ', \'' . $type . '\', \'' . self::esc($detail) . '\', ' . $value . ')');
    }

    /** A visit that ended in an order (from the order hook, or the confirmation page). */
    public static function ordered($idSession, $idOrder, $total, $now)
    {
        return Db::getInstance()->execute('UPDATE ' . self::table('session') . ' SET ordered_at = IF(ordered_at = 0, ' . (int) $now . ', ordered_at)'
            . ($idOrder ? ', id_order = ' . (int) $idOrder . ', total = ' . round((float) $total, 2) : '')
            . ', checkout = GREATEST(checkout, 5) WHERE id_session = ' . (int) $idSession);
    }

    /** Where a visit came from: search, social, e-mail, ads, another site or direct. */
    public static function source(array $m, $host)
    {
        $a = isset($m['a']) && is_array($m['a']) ? $m['a'] : [];
        $utm = function ($k) use ($a) {
            return isset($a[$k]) ? self::clip(Tools::strtolower(trim((string) $a[$k])), 64) : '';
        };
        $campaign = $utm('c');
        $medium = $utm('m');
        $ref = '';
        if (!empty($m['r']) && is_string($m['r'])) {
            $ref = (string) parse_url($m['r'], PHP_URL_HOST);
            $ref = self::clip(preg_replace('/^www\./', '', Tools::strtolower($ref)), 64);
            if ($ref !== '' && $host !== '' && $ref === preg_replace('/^www\./', '', Tools::strtolower($host))) {
                $ref = '';
            }
        }
        if (!empty($a['g']) || in_array($medium, ['cpc', 'ppc', 'paid', 'paidsocial', 'ads'], true)) {
            return ['ads', $ref ?: $utm('s'), $campaign];
        }
        if ($medium === 'email' || $medium === 'newsletter') {
            return ['email', $ref ?: $utm('s'), $campaign];
        }
        $name = $ref ?: $utm('s');
        $groups = [
            'search' => '/(^|\.)(google|bing|duckduckgo|yahoo|yandex|ecosia|qwant|baidu|seznam|onet|wp)\./',
            'social' => '/(^|\.)(facebook|fb|instagram|tiktok|pinterest|linkedin|twitter|x|t|youtube|reddit|threads|lnkd)\.|^l\.facebook|^lm\.facebook/',
        ];
        if ($name !== '') {
            foreach ($groups as $group => $re) {
                if (preg_match($re, $name . '.') || preg_match($re, $name)) {
                    return [$group, $name, $campaign];
                }
            }
            if (!empty($a['f'])) {
                return ['social', $name, $campaign];
            }

            return ['other', $name, $campaign];
        }

        return [!empty($a['f']) ? 'social' : 'direct', '', $campaign];
    }

    /** Old visits go, a batch at a time. @return int sessions removed */
    public static function purge($days, $now, $batch = 2000)
    {
        $db = Db::getInstance();
        $ids = $db->executeS('SELECT id_session FROM ' . self::table('session') . ' WHERE `last` < ' . ((int) $now - max(1, (int) $days) * 86400) . ' LIMIT ' . (int) $batch);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_map(function ($r) { return (int) $r['id_session']; }, $ids));
        foreach (['event', 'view', 'session'] as $t) {
            $db->execute('DELETE FROM ' . self::table($t) . ' WHERE id_session IN (' . $in . ')');
        }

        return count($ids);
    }

    /* ------------------------------------------------------------------ *
     *  Reports
     * ------------------------------------------------------------------ */

    /**
     * Everything the Behaviour tab shows for one set of filters.
     *
     * @param array $f from, to (Unix), bucket (seconds, 0 = by the span), device, source,
     *                 outcome (ordered|abandoned|bounced|browsing), returning (0|1), q (search)
     */
    public static function report(array $f, $idShop, $idLang, $now)
    {
        $f = self::filters($f, $now);
        $where = self::where($f, $idShop, $idLang);
        $out = ['filters' => $f, 'search' => $where['search']];
        if ($where['sql'] === null) {
            return $out + ['empty' => true];
        }
        $w = $where['sql'];
        $db = Db::getInstance();
        $S = self::table('session');
        $V = self::table('view');
        $E = self::table('event');

        $k = $db->getRow('SELECT COUNT(*) sessions, COALESCE(SUM(views), 0) views, COALESCE(SUM(views = 1), 0) bounced,
            COALESCE(SUM(cart_at > 0), 0) carts, COALESCE(SUM(ordered_at > 0), 0) orders, COALESCE(SUM(ordered_before), 0) repeat_buyers,
            COALESCE(SUM(total), 0) revenue FROM ' . $S . ' s WHERE ' . $w);
        $n = (int) $k['sessions'];
        $out['kpi'] = [
            'sessions' => $n,
            'views' => (int) $k['views'],
            'perSession' => $n ? round($k['views'] / $n, 1) : 0,
            'bounce' => $n ? round(100 * $k['bounced'] / $n, 1) : 0,
            'cart' => $n ? round(100 * $k['carts'] / $n, 1) : 0,
            'conversion' => $n ? round(100 * $k['orders'] / $n, 1) : 0,
            'orders' => (int) $k['orders'],
            'revenue' => round((float) $k['revenue'], 2),
            'returning' => $n ? round(100 * $k['repeat_buyers'] / $n, 1) : 0,
            'engaged' => $n ? (int) self::median('SELECT active_ms FROM ' . $S . ' s WHERE ' . $w, $n) : 0,
            'live' => (int) $db->getValue('SELECT COUNT(*) FROM ' . $S . ' WHERE id_shop = ' . (int) $idShop . ' AND `last` >= ' . ((int) $now - 300)),
        ];

        // the timeline, in buckets of the shop's local time
        $b = (int) $f['bucket'];
        $off = (int) date('Z', (int) $f['to']);
        $bucket = 'FLOOR((s.started + ' . $off . ') / ' . $b . ') * ' . $b . ' - ' . $off;
        $rows = $db->executeS('SELECT ' . $bucket . ' t, COUNT(*) sessions, SUM(views) views, SUM(cart_at > 0) carts, SUM(ordered_at > 0) orders
            FROM ' . $S . ' s WHERE ' . $w . ' GROUP BY t ORDER BY t');
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['t']] = ['sessions' => (int) $r['sessions'], 'views' => (int) $r['views'], 'carts' => (int) $r['carts'], 'orders' => (int) $r['orders']];
        }
        $timeline = [];
        $first = (int) (floor(($f['from'] + $off) / $b) * $b - $off);
        for ($t = $first, $i = 0; $t <= $f['to'] && $i < 400; $t += $b, ++$i) {
            $timeline[] = ['t' => $t] + (isset($by[$t]) ? $by[$t] : ['sessions' => 0, 'views' => 0, 'carts' => 0, 'orders' => 0]);
        }
        $out['timeline'] = ['bucket' => $b, 'points' => $timeline];

        // pages: views, engaged time, how often a visit started or ended there, and converted
        $out['pages'] = array_map(function ($r) {
            return [
                'page' => $r['page'], 'views' => (int) $r['views'], 'sessions' => (int) $r['sessions'],
                'avgMs' => (int) round((float) $r['avg_ms']), 'scroll' => (int) round((float) $r['scroll']),
                'entries' => (int) $r['entries'], 'exits' => (int) $r['exits'],
                'exitRate' => $r['views'] ? round(100 * $r['exits'] / $r['views'], 1) : 0,
                'conversion' => $r['sessions'] ? round(100 * $r['converted'] / $r['sessions'], 1) : 0,
            ];
        }, $db->executeS('SELECT v.page, COUNT(*) views, COUNT(DISTINCT v.id_session) sessions,
            AVG(NULLIF(v.active_ms, 0)) avg_ms, AVG(NULLIF(v.scroll, 0)) scroll, SUM(v.seq = 1) entries, SUM(v.seq = s.views) exits,
            COUNT(DISTINCT IF(s.ordered_at > 0, v.id_session, NULL)) converted
            FROM ' . $V . ' v JOIN ' . $S . ' s ON s.id_session = v.id_session WHERE ' . $w . ' GROUP BY v.page ORDER BY views DESC LIMIT 40'));

        // time on page, quantised
        $sum = [];
        $prev = 0;
        foreach (self::DWELL as $sec) {
            $sum[] = 'SUM(v.active_ms >= ' . ($prev * 1000) . ' AND v.active_ms < ' . ($sec * 1000) . ')';
            $prev = $sec;
        }
        $sum[] = 'SUM(v.active_ms >= ' . ($prev * 1000) . ')';
        $d = $db->getRow('SELECT ' . implode(', ', $sum) . ' FROM ' . $V . ' v JOIN ' . $S . ' s ON s.id_session = v.id_session WHERE ' . $w . ' AND v.active_ms > 0');
        $out['dwell'] = ['edges' => self::DWELL, 'counts' => array_map('intval', array_values($d ?: []))];

        // routes: one page to the next
        $out['routes'] = array_map(function ($r) {
            return ['from' => $r['a'], 'to' => $r['b'], 'count' => (int) $r['n'], 'share' => $r['total'] ? round(100 * $r['n'] / $r['total'], 1) : 0];
        }, $db->executeS('SELECT r.a, r.b, r.n, (SELECT COUNT(*) FROM ' . $V . ' x JOIN ' . $S . ' s ON s.id_session = x.id_session WHERE ' . $w . ' AND x.page = r.a) total
            FROM (SELECT a.page a, b.page b, COUNT(*) n FROM ' . $V . ' a JOIN ' . $V . ' b ON b.id_session = a.id_session AND b.seq = a.seq + 1
            JOIN ' . $S . ' s ON s.id_session = a.id_session WHERE ' . $w . ' AND a.page <> b.page GROUP BY a.page, b.page ORDER BY n DESC LIMIT 30) r'));

        $out += self::journeys($w, $n);
        $out['vitals'] = self::vitals($w);

        // failure points from the events
        $out['failures'] = [
            'emptySearch' => self::pairs($db->executeS('SELECT e.detail k, COUNT(*) n FROM ' . $E . ' e JOIN ' . $S . ' s ON s.id_session = e.id_session WHERE ' . $w . ' AND e.type = \'search\' AND e.value = 0 AND e.detail <> \'\' GROUP BY e.detail ORDER BY n DESC LIMIT 15')),
            'notFound' => self::pairs($db->executeS('SELECT v.url k, COUNT(*) n FROM ' . $V . ' v JOIN ' . $S . ' s ON s.id_session = v.id_session WHERE ' . $w . ' AND v.page = \'pagenotfound\' GROUP BY v.url ORDER BY n DESC LIMIT 15')),
            'errors' => self::pairs($db->executeS('SELECT e.detail k, COUNT(*) n FROM ' . $E . ' e JOIN ' . $S . ' s ON s.id_session = e.id_session WHERE ' . $w . ' AND e.type = \'error\' GROUP BY e.detail ORDER BY n DESC LIMIT 15')),
        ];

        $out['sessions'] = self::sessions($w, 50);
        $out['labels'] = self::labels($out, $idLang, $idShop);

        return $out;
    }

    /** Paths, paths of success, the funnel and where unfinished carts were left. */
    protected static function journeys($w, $n)
    {
        $rows = Db::getInstance()->executeS('SELECT s.path, s.views, s.started, s.cart_at, s.ordered_at, s.checkout, s.`exit`, s.ordered_before
            FROM ' . self::table('session') . ' s WHERE ' . $w . ' ORDER BY s.started DESC LIMIT ' . self::SAMPLE);
        $paths = [];
        $success = [];
        $abandoned = [];
        $toOrder = [];
        $pagesToOrder = [];
        $cartToOrder = [0 => [], 1 => []];
        $funnel = ['sessions' => 0, 'product' => 0, 'cart' => 0, 'checkout' => 0, 'personal' => 0, 'addresses' => 0, 'delivery' => 0, 'payment' => 0, 'pay' => 0, 'ordered' => 0];
        $left = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $r) {
            $keys = preg_split('/ +/', trim((string) $r['path']), -1, PREG_SPLIT_NO_EMPTY);
            $types = self::collapse(array_map(function ($k) { return explode(':', $k)[0]; }, $keys));
            $ordered = (int) $r['ordered_at'] > 0;
            $step = (int) $r['checkout'];
            $sig = implode(' ', array_slice($types, 0, 6)) . (count($types) > 6 ? ' …' : '');
            if (!isset($paths[$sig])) {
                $paths[$sig] = ['n' => 0, 'ordered' => 0];
            }
            ++$paths[$sig]['n'];
            $paths[$sig]['ordered'] += $ordered ? 1 : 0;

            ++$funnel['sessions'];
            $funnel['product'] += in_array('product', $types, true) ? 1 : 0;
            $cart = (int) $r['cart_at'] > 0;
            $funnel['cart'] += $cart ? 1 : 0;
            $checkout = $step > 0 || in_array('checkout', $types, true) || in_array('order', $types, true);
            $funnel['checkout'] += $checkout ? 1 : 0;
            foreach (self::STEPS as $name => $i) {
                $funnel[$name] += $step >= $i ? 1 : 0;
            }
            $funnel['ordered'] += $ordered ? 1 : 0;

            if ($ordered) {
                $cut = array_search('order-confirmation', $types, true);
                $run = $cut === false ? $types : array_slice($types, 0, $cut);
                $sig = (count($run) > 6 ? '… ' : '') . implode(' ', array_slice($run, -6));
                $success[$sig] = (isset($success[$sig]) ? $success[$sig] : 0) + 1;
                $toOrder[] = (int) $r['ordered_at'] - (int) $r['started'];
                $pagesToOrder[] = $cut === false ? count($keys) : count(array_slice($keys, 0, (int) array_search('order-confirmation', $keys, true)));
                if ((int) $r['cart_at'] > 0 && (int) $r['ordered_at'] >= (int) $r['cart_at']) {
                    $cartToOrder[(int) $r['ordered_before'] ? 1 : 0][] = (int) $r['ordered_at'] - (int) $r['cart_at'];
                }
            } elseif ($cart) {
                $abandoned[$r['exit']] = (isset($abandoned[$r['exit']]) ? $abandoned[$r['exit']] : 0) + 1;
                ++$left[min(5, $step)];
            }
        }
        uasort($paths, function ($a, $b) { return $b['n'] - $a['n']; });
        arsort($success);
        arsort($abandoned);
        $list = [];
        foreach (array_slice($paths, 0, 15, true) as $sig => $p) {
            $list[] = ['path' => explode(' ', $sig), 'count' => $p['n'], 'conversion' => round(100 * $p['ordered'] / max(1, $p['n']), 1)];
        }
        $wins = [];
        foreach (array_slice($success, 0, 10, true) as $sig => $c) {
            $wins[] = ['path' => explode(' ', $sig), 'count' => $c];
        }

        return [
            'sample' => ['read' => count($rows), 'of' => $n],
            'paths' => $list,
            'success' => [
                'paths' => $wins,
                'secondsToOrder' => self::med($toOrder),
                'pagesToOrder' => self::med($pagesToOrder),
                'cartToOrder' => ['new' => self::med($cartToOrder[0]), 'returning' => self::med($cartToOrder[1]), 'newCount' => count($cartToOrder[0]), 'returningCount' => count($cartToOrder[1])],
            ],
            'funnel' => $funnel,
            'abandoned' => ['exits' => self::pairs(array_map(function ($k, $c) { return ['k' => $k, 'n' => $c]; }, array_keys(array_slice($abandoned, 0, 10, true)), array_slice($abandoned, 0, 10, true))), 'lastStep' => $left],
        ];
    }

    /**
     * Core Web Vitals as shoppers got them: the 75th percentile (the figure Google judges a page
     * by) and the share of good, needs-improvement and poor page views, overall, by device and
     * for the pages with the most measurements.
     */
    protected static function vitals($w)
    {
        $cols = [];
        foreach (self::VITALS as $name => $v) {
            $cols[] = 'v.`' . $v['col'] . '` ' . $name;
        }
        $rows = Db::getInstance()->executeS('SELECT v.page, s.device, ' . implode(', ', $cols) . ' FROM ' . self::table('view') . ' v
            JOIN ' . self::table('session') . ' s ON s.id_session = v.id_session WHERE ' . $w . '
            AND (v.lcp_ms IS NOT NULL OR v.inp_ms IS NOT NULL OR v.cls IS NOT NULL OR v.ttfb_ms IS NOT NULL)
            ORDER BY v.id_view DESC LIMIT ' . self::SAMPLE);
        $sum = function (array $rows) {
            $out = [];
            foreach (self::VITALS as $name => $v) {
                $values = [];
                foreach ($rows as $r) {
                    if ($r[$name] !== null) {
                        $values[] = (int) $r[$name];
                    }
                }
                $out[$name] = self::rate($name, $values);
            }

            return $out;
        };
        $byDevice = [];
        $byPage = [];
        foreach ($rows as $r) {
            $byDevice[self::DEVICES[min(2, (int) $r['device'])]][] = $r;
            $byPage[$r['page']][] = $r;
        }
        uasort($byPage, function ($a, $b) { return count($b) - count($a); });
        $pages = [];
        foreach (array_slice($byPage, 0, 15, true) as $page => $list) {
            $pages[] = ['page' => (string) $page, 'views' => count($list)] + $sum($list);
        }
        $devices = [];
        foreach ($byDevice as $device => $list) {
            $devices[$device] = $sum($list);
        }

        return ['views' => count($rows), 'all' => $sum($rows), 'devices' => $devices, 'pages' => $pages];
    }

    /** p75 of one metric, its rating and the shares of good, needs-improvement and poor. */
    public static function rate($name, array $values)
    {
        $n = count($values);
        if (!$n) {
            return null;
        }
        sort($values);
        $p75 = $values[(int) ceil(0.75 * $n) - 1];
        $limits = self::VITALS[$name];
        $good = 0;
        $poor = 0;
        foreach ($values as $v) {
            $good += $v <= $limits['good'] ? 1 : 0;
            $poor += $v > $limits['poor'] ? 1 : 0;
        }

        return [
            'p75' => $p75, 'n' => $n,
            'rating' => $p75 <= $limits['good'] ? 'good' : ($p75 <= $limits['poor'] ? 'ni' : 'poor'),
            'good' => round(100 * $good / $n, 1), 'ni' => round(100 * ($n - $good - $poor) / $n, 1), 'poor' => round(100 * $poor / $n, 1),
        ];
    }

    /** The most recent matching visits, with their paths. */
    protected static function sessions($w, $limit)
    {
        $rows = Db::getInstance()->executeS('SELECT s.id_session, s.started, s.`last`, s.views, s.active_ms, s.device, s.source, s.ref, s.campaign, s.ordered_before,
            s.id_customer, s.cart_at, s.checkout, s.ordered_at, s.total, s.path FROM ' . self::table('session') . ' s WHERE ' . $w . ' ORDER BY s.started DESC LIMIT ' . (int) $limit);

        return array_map(function ($r) {
            $keys = preg_split('/ +/', trim((string) $r['path']), -1, PREG_SPLIT_NO_EMPTY);

            return [
                'id' => (int) $r['id_session'], 'started' => (int) $r['started'], 'seconds' => (int) $r['last'] - (int) $r['started'],
                'views' => (int) $r['views'], 'activeMs' => (int) $r['active_ms'], 'device' => self::DEVICES[min(2, (int) $r['device'])],
                'source' => $r['source'], 'ref' => $r['ref'], 'campaign' => $r['campaign'], 'returning' => (bool) $r['ordered_before'],
                'customer' => (int) $r['id_customer'], 'outcome' => self::outcome($r), 'total' => (float) $r['total'],
                'path' => array_slice($keys, 0, 40), 'more' => max(0, count($keys) - 40),
            ];
        }, $rows);
    }

    /** One visit, page by page, with what happened on each. */
    public static function session($id, $idShop, $idLang)
    {
        $db = Db::getInstance();
        $s = $db->getRow('SELECT * FROM ' . self::table('session') . ' WHERE id_session = ' . (int) $id . ' AND id_shop = ' . (int) $idShop);
        if (!$s) {
            return ['error' => 'not found'];
        }
        $out = self::detail($s);
        $out['labels'] = self::labels(['x' => array_column($out['views'], 'page')], $idLang, $idShop);

        return $out;
    }

    /** A visit row with its pages and what happened on each. */
    protected static function detail(array $s)
    {
        $db = Db::getInstance();
        $id = (int) $s['id_session'];
        $views = $db->executeS('SELECT id_view, seq, at, page, url, nav, active_ms, scroll, lcp_ms, inp_ms, cls FROM ' . self::table('view') . ' WHERE id_session = ' . $id . ' ORDER BY seq');
        $events = $db->executeS('SELECT id_view, at, type, detail, value FROM ' . self::table('event') . ' WHERE id_session = ' . $id . ' ORDER BY id_event');
        $byView = [];
        foreach ($events as $e) {
            $byView[(int) $e['id_view']][] = ['at' => (int) $e['at'], 'type' => $e['type'], 'detail' => $e['detail'], 'value' => (int) $e['value']];
        }
        $out = [
            'id' => $id, 'started' => (int) $s['started'], 'last' => (int) $s['last'], 'outcome' => self::outcome($s),
            'device' => self::DEVICES[min(2, (int) $s['device'])], 'source' => $s['source'], 'ref' => $s['ref'], 'campaign' => $s['campaign'],
            'activeMs' => (int) $s['active_ms'], 'pages' => (int) $s['views'], 'cartAt' => (int) $s['cart_at'], 'orderedAt' => (int) $s['ordered_at'],
            'total' => (float) $s['total'], 'views' => [],
        ];
        foreach ($views ?: [] as $v) {
            $out['views'][] = [
                'seq' => (int) $v['seq'], 'at' => (int) $v['at'], 'page' => $v['page'], 'url' => $v['url'], 'nav' => (int) $v['nav'],
                'activeMs' => (int) $v['active_ms'], 'scroll' => (int) $v['scroll'],
                'lcp' => $v['lcp_ms'] === null ? null : (int) $v['lcp_ms'], 'inp' => $v['inp_ms'] === null ? null : (int) $v['inp_ms'], 'cls' => $v['cls'] === null ? null : (int) $v['cls'],
                'events' => isset($byView[(int) $v['id_view']]) ? $byView[(int) $v['id_view']] : [],
            ];
        }

        return $out;
    }

    /**
     * Every visit behind a cart or an order, oldest first, with a summary: how many visits over
     * how long, pages, engaged time, where the shopper first came from and on what devices.
     *
     * @return array|null null when no visit was recorded for it
     */
    public static function journey($idShop, $idLang, $idCart, $idOrder)
    {
        $or = [];
        if ((int) $idCart > 0) {
            $or[] = 'id_cart = ' . (int) $idCart;
        }
        if ((int) $idOrder > 0) {
            $or[] = 'id_order = ' . (int) $idOrder;
        }
        if (!$or) {
            return null;
        }
        $rows = Db::getInstance()->executeS('SELECT * FROM ' . self::table('session') . ' WHERE id_shop = ' . (int) $idShop . ' AND (' . implode(' OR ', $or) . ') ORDER BY started LIMIT 12');
        if (!$rows) {
            return null;
        }
        $visits = array_map([self::class, 'detail'], $rows);
        $first = $visits[0];
        $last = $visits[count($visits) - 1];
        $ordered = array_values(array_filter($visits, function ($v) { return $v['orderedAt'] > 0; }));
        $carted = array_values(array_filter($visits, function ($v) { return $v['cartAt'] > 0; }));
        $end = $ordered ? $ordered[0]['orderedAt'] : $last['last'];
        $out = [
            'visits' => $visits,
            'summary' => [
                'visits' => count($visits),
                'from' => $first['started'],
                'to' => $end,
                'span' => max(0, $end - $first['started']),
                'days' => (int) floor(max(0, $end - $first['started']) / 86400),
                'pages' => array_sum(array_column($visits, 'pages')),
                'activeMs' => array_sum(array_column($visits, 'activeMs')),
                'source' => $first['source'], 'ref' => $first['ref'], 'campaign' => $first['campaign'],
                'devices' => array_values(array_unique(array_column($visits, 'device'))),
                'cartAt' => $carted ? $carted[0]['cartAt'] : 0,
                'orderedAt' => $ordered ? $ordered[0]['orderedAt'] : 0,
                'outcome' => $ordered ? 'ordered' : $last['outcome'],
            ],
        ];
        $keys = [];
        foreach ($visits as $v) {
            $keys = array_merge($keys, array_column($v['views'], 'page'));
        }
        $out['labels'] = self::labels(['x' => $keys], $idLang, $idShop);

        return $out;
    }

    /* ------------------------------------------------------------------ *
     *  Filters and search
     * ------------------------------------------------------------------ */

    public static function filters(array $f, $now)
    {
        $to = isset($f['to']) && (int) $f['to'] > 0 ? (int) $f['to'] : (int) $now;
        $from = isset($f['from']) && (int) $f['from'] > 0 ? (int) $f['from'] : $to - 7 * 86400;
        if ($from >= $to) {
            $from = $to - 86400;
        }
        $span = $to - $from;
        $bucket = isset($f['bucket']) ? (int) $f['bucket'] : 0;
        if (!in_array($bucket, [900, 3600, 86400, 604800], true)) {
            $bucket = $span <= 6 * 3600 ? 900 : ($span <= 3 * 86400 ? 3600 : ($span <= 120 * 86400 ? 86400 : 604800));
        }
        // never more than 400 points
        while ($span / $bucket > 400 && $bucket < 604800) {
            $bucket = $bucket === 900 ? 3600 : ($bucket === 3600 ? 86400 : 604800);
        }
        $pick = function ($k, array $allowed) use ($f) {
            return isset($f[$k]) && in_array((string) $f[$k], $allowed, true) ? (string) $f[$k] : '';
        };

        return [
            'from' => $from, 'to' => $to, 'bucket' => $bucket,
            'device' => $pick('device', self::DEVICES),
            'source' => $pick('source', ['direct', 'search', 'social', 'email', 'ads', 'other']),
            'outcome' => $pick('outcome', ['ordered', 'abandoned', 'bounced', 'browsing']),
            'returning' => $pick('returning', ['0', '1']),
            'q' => isset($f['q']) ? self::clip(trim((string) $f['q']), 200) : '',
        ];
    }

    /**
     * The WHERE of a report (sessions "s"), and what the search was understood as.
     *
     * @return array ['sql' => string|null (null: the search matches nothing), 'search' => array]
     */
    public static function where(array $f, $idShop, $idLang)
    {
        $w = ['s.id_shop = ' . (int) $idShop, 's.started >= ' . (int) $f['from'], 's.started <= ' . (int) $f['to']];
        if ($f['device'] !== '') {
            $w[] = 's.device = ' . (int) array_search($f['device'], self::DEVICES, true);
        }
        if ($f['source'] !== '') {
            $w[] = 's.source = \'' . self::esc($f['source']) . '\'';
        }
        $outcomes = [
            'ordered' => 's.ordered_at > 0',
            'abandoned' => 's.cart_at > 0 AND s.ordered_at = 0',
            'bounced' => 's.views = 1',
            'browsing' => 's.cart_at = 0 AND s.ordered_at = 0',
        ];
        if ($f['outcome'] !== '') {
            $w[] = $outcomes[$f['outcome']];
        }
        if ($f['returning'] !== '') {
            $w[] = 's.ordered_before = ' . (int) $f['returning'];
        }
        $search = ['terms' => []];
        if ($f['q'] !== '') {
            $steps = [];
            foreach (array_filter(array_map('trim', preg_split('/\s*(?:>|→|->)\s*/u', $f['q']))) as $term) {
                $match = self::term($term, $idLang, $idShop);
                $search['terms'][] = ['term' => $term] + $match;
                if ($match['sql'] !== null) {
                    $w[] = $match['sql'];
                    continue;
                }
                if (!$match['keys']) {
                    return ['sql' => null, 'search' => $search];
                }
                $steps[] = '(' . implode('|', $match['keys']) . ')';
            }
            if ($steps) {
                $w[] = 's.path REGEXP \'' . self::esc(' ' . implode(' (.* )?', $steps) . ' ') . '\'';
            }
        }

        return ['sql' => implode(' AND ', $w), 'search' => $search];
    }

    /**
     * One search term: a page key ("product:7"), an address ("/pl/koszyk"), a customer
     * ("customer:12" or an e-mail), a page type by name ("cart", "koszyk") or the name of a
     * product, category or CMS page.
     *
     * @return array ['keys' => page keys it stands for, 'sql' => a condition of its own, or null]
     */
    public static function term($term, $idLang, $idShop)
    {
        $db = Db::getInstance();
        $t = Tools::strtolower(trim($term));
        if (preg_match('/^[a-z][a-z0-9-]{0,30}(:\d{1,10})?$/', $t) && !isset(self::aliases()[$t])) {
            if (preg_match('/^customer:(\d+)$/', $t, $m)) {
                return ['keys' => [], 'sql' => 's.id_customer = ' . (int) $m[1], 'as' => 'customer'];
            }
            if (strpos($t, ':') !== false || in_array($t, self::types(), true)) {
                return ['keys' => [$t], 'sql' => null, 'as' => 'page'];
            }
        }
        if ($t !== '' && $t[0] === '/') {
            return ['keys' => [], 'as' => 'address', 'sql' => 'EXISTS (SELECT 1 FROM ' . self::table('view') . ' sv WHERE sv.id_session = s.id_session AND sv.url LIKE \'' . self::esc(addcslashes($t, '%_\\')) . '%\')'];
        }
        if (strpos($t, '@') !== false) {
            $ids = $db->executeS('SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer` WHERE email LIKE \'%' . self::esc(addcslashes($t, '%_\\')) . '%\' LIMIT 20');
            $ids = array_map(function ($r) { return (int) $r['id_customer']; }, $ids ?: []);

            return ['keys' => [], 'as' => 'customer', 'sql' => $ids ? 's.id_customer IN (' . implode(',', $ids) . ')' : 's.id_customer = -1'];
        }
        $aliases = self::aliases();
        if (isset($aliases[$t])) {
            return ['keys' => [$aliases[$t]], 'sql' => null, 'as' => 'page'];
        }
        $like = '\'%' . self::esc(addcslashes($t, '%_\\')) . '%\'';
        $keys = [];
        $named = [
            'product' => 'SELECT id_product id FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_lang = ' . (int) $idLang . ' AND id_shop = ' . (int) $idShop . ' AND name LIKE ' . $like,
            'category' => 'SELECT id_category id FROM `' . _DB_PREFIX_ . 'category_lang` WHERE id_lang = ' . (int) $idLang . ' AND id_shop = ' . (int) $idShop . ' AND name LIKE ' . $like,
            'cms' => 'SELECT id_cms id FROM `' . _DB_PREFIX_ . 'cms_lang` WHERE id_lang = ' . (int) $idLang . ' AND meta_title LIKE ' . $like,
        ];
        foreach ($named as $type => $sql) {
            try {
                foreach ($db->executeS($sql . ' LIMIT 25') ?: [] as $r) {
                    $keys[] = $type . ':' . (int) $r['id'];
                }
            } catch (Exception $e) {
                // a shop without that table (or column) has no such pages
            }
        }

        return ['keys' => array_values(array_unique($keys)), 'sql' => null, 'as' => 'name'];
    }

    /** Page types by the words a shop owner would type. */
    public static function aliases()
    {
        return [
            'home' => 'index', 'strona główna' => 'index', 'główna' => 'index',
            'koszyk' => 'cart', 'basket' => 'cart',
            'zamówienie' => 'checkout', 'kasa' => 'checkout', 'order' => 'checkout',
            'confirmation' => 'order-confirmation', 'potwierdzenie' => 'order-confirmation', 'ordered' => 'order-confirmation',
            'szukaj' => 'search', 'wyszukiwanie' => 'search',
            '404' => 'pagenotfound', 'not found' => 'pagenotfound',
            'konto' => 'my-account', 'account' => 'my-account', 'logowanie' => 'authentication', 'login' => 'authentication',
            'kontakt' => 'contact', 'nowości' => 'new-products', 'promocje' => 'prices-drop', 'bestsellery' => 'best-sales',
        ];
    }

    /** PrestaShop's front page types (body id) a key may start with. */
    public static function types()
    {
        return ['index', 'category', 'product', 'cms', 'cart', 'checkout', 'order', 'order-confirmation', 'search', 'pagenotfound',
            'my-account', 'authentication', 'registration', 'identity', 'addresses', 'address', 'history', 'order-detail', 'order-follow',
            'order-slip', 'discount', 'guest-tracking', 'password', 'contact', 'stores', 'sitemap', 'new-products', 'prices-drop',
            'best-sales', 'manufacturer', 'supplier', 'module', ];
    }

    /* ------------------------------------------------------------------ *
     *  Names for page keys
     * ------------------------------------------------------------------ */

    /** Product, category, CMS, brand and supplier names for every key in a result. */
    public static function labels(array $data, $idLang, $idShop)
    {
        $want = [];
        array_walk_recursive($data, function ($v, $k) use (&$want) {
            if (is_string($v) && preg_match('/^(product|category|cms|manufacturer|supplier):(\d+)$/', $v, $m)) {
                $want[$m[1]][(int) $m[2]] = true;
            }
        });
        $sql = [
            'product' => 'SELECT id_product id, name FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_lang = ' . (int) $idLang . ' AND id_shop = ' . (int) $idShop . ' AND id_product IN (%s)',
            'category' => 'SELECT id_category id, name FROM `' . _DB_PREFIX_ . 'category_lang` WHERE id_lang = ' . (int) $idLang . ' AND id_shop = ' . (int) $idShop . ' AND id_category IN (%s)',
            'cms' => 'SELECT id_cms id, meta_title name FROM `' . _DB_PREFIX_ . 'cms_lang` WHERE id_lang = ' . (int) $idLang . ' AND id_cms IN (%s)',
            'manufacturer' => 'SELECT id_manufacturer id, name FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE id_manufacturer IN (%s)',
            'supplier' => 'SELECT id_supplier id, name FROM `' . _DB_PREFIX_ . 'supplier` WHERE id_supplier IN (%s)',
        ];
        $out = [];
        foreach ($want as $type => $ids) {
            try {
                foreach (Db::getInstance()->executeS(sprintf($sql[$type], implode(',', array_map('intval', array_keys($ids))))) ?: [] as $r) {
                    $out[$type . ':' . (int) $r['id']] = (string) $r['name'];
                }
            } catch (Exception $e) {
                // shown by its key
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /** A page key from the page type and object id the shop window read off the page. */
    public static function page($type, $id)
    {
        $type = Tools::strtolower((string) $type);
        if (!preg_match('/^[a-z][a-z0-9-]{0,30}$/', $type)) {
            return '';
        }
        $id = (int) $id;

        return $id > 0 && $id < 4000000000 ? $type . ':' . $id : $type;
    }

    protected static function vkey($k)
    {
        return is_string($k) && preg_match('/^[0-9a-f]{8}$/', $k) ? $k : '';
    }

    protected static function outcome(array $r)
    {
        if ((int) $r['ordered_at'] > 0) {
            return 'ordered';
        }
        if ((int) $r['cart_at'] > 0) {
            return (int) $r['checkout'] > 0 ? 'checkout' : 'cart';
        }

        return (int) $r['views'] <= 1 ? 'bounced' : 'browsing';
    }

    protected static function collapse(array $list)
    {
        $out = [];
        foreach ($list as $x) {
            if (!$out || end($out) !== $x) {
                $out[] = $x;
            }
        }

        return $out;
    }

    protected static function pairs($rows)
    {
        return array_map(function ($r) { return ['key' => (string) $r['k'], 'count' => (int) $r['n']]; }, $rows ?: []);
    }

    protected static function median($sql, $n)
    {
        return Db::getInstance()->getValue($sql . ' ORDER BY active_ms LIMIT 1 OFFSET ' . (int) floor(($n - 1) / 2));
    }

    protected static function med(array $v)
    {
        if (!$v) {
            return null;
        }
        sort($v);
        $n = count($v);

        return $n % 2 ? $v[($n - 1) / 2] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    protected static function clip($s, $len)
    {
        $s = (string) $s;

        return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
    }

    protected static function esc($s)
    {
        return Db::getInstance()->escape((string) $s);
    }
}
