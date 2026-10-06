<?php
require __DIR__ . '/bootstrap.php';
// Behaviour against a real MariaDB: recording (what a shop window sends, visit by visit), and every
// report figure for a set of scripted visits whose answers are known: KPIs, the timeline in local
// buckets, pages, time on page, routes, paths, paths of success, the funnel, failure points, search,
// filters, one visit in detail, labels, other shops and the clean-up of old visits.
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'bht_');
date_default_timezone_set('Europe/Warsaw');

class Db
{
    static $i; public $pdo;
    static function getInstance() { if (!self::$i) { self::$i = new Db(); self::$i->pdo = new PDO(getenv('SPC_DB_DSN') ?: 'mysql:host=localhost;dbname=spctest;charset=utf8mb4', getenv('SPC_DB_USER') ?: 'lp', getenv('SPC_DB_PASS') ?: 'lp', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } return self::$i; }
    function execute($sql) { return $this->pdo->exec($sql) !== false; }
    function executeS($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function getRow($sql) { $r = $this->executeS($sql); return $r ? $r[0] : false; }
    function getValue($sql) { $r = $this->getRow($sql); return $r ? reset($r) : false; }
    function Insert_ID() { return (int) $this->pdo->lastInsertId(); }
    function escape($s) { return substr($this->pdo->quote((string) $s), 1, -1); }
}
class Tools { static function strtolower($s) { return mb_strtolower((string) $s); } }
require SPC_MODULE . '/classes/SpcBehaviourStore.php';

$db = Db::getInstance();
function q($sql) { return Db::getInstance()->getValue($sql); }

// ---------- the shop's own tables the report reads names from
foreach (['spc_bh_event', 'spc_bh_view', 'spc_bh_session', 'product_lang', 'category_lang', 'cms_lang', 'customer'] as $t) {
    $db->execute("DROP TABLE IF EXISTS bht_$t");
}
$db->execute('CREATE TABLE bht_product_lang (id_product INT, id_shop INT, id_lang INT, name VARCHAR(128))');
$db->execute("INSERT INTO bht_product_lang VALUES (7, 1, 1, 'Kimchi klasyczne'), (8, 1, 1, 'Zakwas buraczany'), (9, 1, 1, 'Kimchi ostre')");
$db->execute('CREATE TABLE bht_category_lang (id_category INT, id_shop INT, id_lang INT, name VARCHAR(128))');
$db->execute("INSERT INTO bht_category_lang VALUES (3, 1, 1, 'Fermentowane')");
$db->execute('CREATE TABLE bht_cms_lang (id_cms INT, id_lang INT, meta_title VARCHAR(128))');
$db->execute('CREATE TABLE bht_customer (id_customer INT, email VARCHAR(128))');
$db->execute("INSERT INTO bht_customer VALUES (5, 'anna@example.com')");

ok(SpcBehaviourStore::install() && SpcBehaviourStore::install(), 'tables created (twice: the settings page repeats it safely)');

// ---------- a shop window, scripted
$T0 = strtotime('2026-10-05 10:00:00');
$now = $T0;
function vk() { return sprintf('%08x', mt_rand(0, 0x7fffffff)); }

/**
 * One visit: each step is [page type, id, engaged seconds, events, extra view fields]; sent the way
 * behaviour.js sends it: the view when shown, then running time totals and events.
 */
function visit(array $steps, array $env = [], array $first = [], $state = ['id' => 0, 'last' => 0])
{
    global $now;
    $env += ['id_shop' => 1, 'host' => 'sklep.test', 'id_customer' => 0, 'returning' => false];
    foreach ($steps as $i => $s) {
        list($type, $id, $sec) = $s;
        $events = isset($s[3]) ? $s[3] : [];
        $k = vk();
        $v = ['t' => 'v', 'k' => $k, 'p' => $type, 'i' => $id, 'u' => isset($s[4]) ? $s[4] : "/pl/$type" . ($id ? "/$id" : ''), 'n' => $i ? 1 : 0, 'd' => isset($env['device']) ? $env['device'] : 0];
        if ($i === 0) { $v += $first; }
        $state = SpcBehaviourStore::collect($state, [$v], $env, $now);
        $msgs = [];
        foreach ($events as $e) { $msgs[] = ['t' => 'e', 'k' => $k] + $e; }
        // half the time first, then the total: the store keeps the larger running total
        $msgs[] = ['t' => 't', 'k' => $k, 'ms' => $sec * 500, 's' => 40];
        $msgs[] = ['t' => 't', 'k' => $k, 'ms' => $sec * 1000, 's' => 80];
        $now += $sec;
        $state = SpcBehaviourStore::collect($state, $msgs, $env, $now);
        $now += 2;
    }

    return $state;
}

$checkout = function ($upTo, $extra = []) {
    $ev = [];
    foreach (['personal', 'addresses', 'delivery', 'payment'] as $i => $step) {
        if ($i < $upTo) { $ev[] = ['e' => 'step', 'd' => $step]; }
    }

    return array_merge($ev, $extra);
};

$google = ['r' => 'https://www.google.com/search?q=kimchi'];
$orders = 0;
// A: 10 orders: home > category > product (cart) > cart > checkout (all steps, pay) > confirmation
for ($i = 0; $i < 10; ++$i) {
    $now = $T0 + count([]) + $i * 600;
    $state = visit([
        ['index', 0, 20], ['category', 3, 30], ['product', 7, 40, [['e' => 'cart']]], ['cart', 0, 25],
        ['checkout', 0, 120, $checkout(4, [['e' => 'pay']])], ['order-confirmation', 0, 15, [], '/pl/potwierdzenie-zamowienia?id_order=' . (100 + $i)],
    ], ['id_customer' => $i < 5 ? 5 : 0, 'returning' => $i < 5]);
    SpcBehaviourStore::ordered($state['id'], 100 + $i, 89.5, $now);
}
// B: 6 carts left at delivery, with an error shown there
for ($i = 0; $i < 6; ++$i) {
    $now = $T0 + 6000 + $i * 600;
    visit([['index', 0, 10], ['product', 7, 50, [['e' => 'cart']]], ['cart', 0, 20],
        ['checkout', 0, 90, $checkout(3, [['e' => 'error', 'd' => "delivery: Brak dostawy pod ten kod 'O'Hara'"]])]]);
}
// C: 4 carts left on the cart page
for ($i = 0; $i < 4; ++$i) {
    $now = $T0 + 9600 + $i * 600;
    visit([['category', 3, 15], ['product', 8, 35, [['e' => 'cart']]], ['cart', 0, 12]], ['device' => 1]);
}
// D: 8 phones from Google, one product page each
for ($i = 0; $i < 8; ++$i) {
    $now = $T0 + 12000 + $i * 600;
    visit([['product', 7, 4]], ['device' => 2], $google);
}
// E: 3 empty searches that end on a page that is gone
for ($i = 0; $i < 3; ++$i) {
    $now = $T0 + 16800 + $i * 600;
    visit([['index', 0, 6], ['search', 0, 8, [['e' => 'search', 'd' => 'kimchy', 'x' => 0]]], ['pagenotfound', 0, 3, [], '/pl/stara-strona']],
        [], ['a' => ['s' => 'newsletter', 'm' => 'email', 'c' => 'jesien']]);
}
$end = $now;
// another shop, and a visit from long ago
$now = $T0 + 100;
visit([['index', 0, 5], ['product', 7, 5]], ['id_shop' => 2]);
$now = $T0 - 200 * 86400;
visit([['index', 0, 5]]);

// ---------- recording
ok((int) q('SELECT COUNT(*) FROM bht_spc_bh_session WHERE id_shop = 1') === 32 && (int) q('SELECT COUNT(*) FROM bht_spc_bh_session') === 33, 'one visit per scripted visit (31 + an old one + another shop)');
$s = $db->getRow('SELECT * FROM bht_spc_bh_session WHERE id_shop = 1 AND started = ' . $T0);
ok($s['path'] === ' index category:3 product:7 cart checkout order-confirmation ' && $s['entry'] === 'index' && $s['exit'] === 'order-confirmation' && (int) $s['views'] === 6, 'a visit keeps its path, entry and exit page');
ok((int) $s['checkout'] === 5 && (int) $s['ordered_at'] > 0 && (int) $s['id_order'] === 100 && (float) $s['total'] === 89.5 && (int) $s['cart_at'] > 0, 'the order, its total, the furthest checkout step and the first add to cart');
ok((int) $s['active_ms'] === 250000, 'engaged time of a visit: the sum of its pages (running totals, the larger one kept)');
ok((int) q('SELECT scroll FROM bht_spc_bh_view WHERE id_session = ' . $s['id_session'] . ' AND seq = 3') === 80, 'scroll depth: the deepest point');
ok((int) $s['id_customer'] === 5 && (int) $s['ordered_before'] === 1, 'a signed-in shopper who ordered before');
$d = $db->getRow("SELECT source, ref FROM bht_spc_bh_session WHERE id_shop = 1 AND device = 2 LIMIT 1");
ok($d['source'] === 'search' && $d['ref'] === 'google.com', 'a visit from Google: source search, google.com');
$e = $db->getRow("SELECT source, campaign FROM bht_spc_bh_session WHERE path LIKE '% search %' LIMIT 1");
ok($e['source'] === 'email' && $e['campaign'] === 'jesien', 'a newsletter link: source e-mail, its campaign');
ok(q("SELECT detail FROM bht_spc_bh_event WHERE type = 'error' LIMIT 1") === "delivery: Brak dostawy pod ten kod 'O'Hara'", 'an error message with quotes is stored as it was');

// the same view sent twice, a gap of over half an hour, junk
$now = $end + 5000;
$k = vk();
$st = SpcBehaviourStore::collect(['id' => 0, 'last' => 0], [['t' => 'v', 'k' => $k, 'p' => 'index', 'i' => 0, 'u' => '/']], ['id_shop' => 1, 'host' => 'sklep.test'], $now);
$st2 = SpcBehaviourStore::collect($st, [['t' => 'v', 'k' => $k, 'p' => 'index', 'i' => 0, 'u' => '/']], ['id_shop' => 1, 'host' => 'sklep.test'], $now + 5);
ok($st2['id'] === $st['id'] && (int) q('SELECT views FROM bht_spc_bh_session WHERE id_session = ' . $st['id']) === 1, 'a page view sent twice (a retried beacon) counts once');
$st3 = SpcBehaviourStore::collect($st2, [['t' => 'v', 'k' => vk(), 'p' => 'index', 'i' => 0]], ['id_shop' => 1, 'host' => 'sklep.test'], $now + 1900);
ok($st3['id'] !== $st['id'], 'after more than 30 minutes without a page or an action, a new visit');
$junk = [['t' => 'v', 'k' => vk(), 'p' => "x' OR 1=1 --", 'i' => 1], ['t' => 'v', 'k' => 'nothex!!', 'p' => 'index'], ['t' => 'e', 'k' => vk(), 'e' => 'drop table'], 'text', ['t' => 't', 'k' => vk(), 'ms' => 99]];
$st4 = SpcBehaviourStore::collect(['id' => 0, 'last' => 0], $junk, ['id_shop' => 1, 'host' => 'sklep.test'], $now + 4000);
ok($st4['id'] === 0 && (int) q('SELECT COUNT(*) FROM bht_spc_bh_session WHERE started >= ' . ($now + 4000)) === 0, 'messages that are not page views of a real page type store nothing');
$db->execute('DELETE FROM bht_spc_bh_session WHERE started >= ' . $now);
$db->execute('DELETE FROM bht_spc_bh_view WHERE at >= ' . $now);

// sources
$src = function ($m, $host = 'sklep.test') { return SpcBehaviourStore::source($m, $host)[0]; };
ok($src(['r' => 'https://l.facebook.com/l.php']) === 'social' && $src(['a' => ['f' => 1]]) === 'social' && $src(['a' => ['g' => 1]]) === 'ads'
    && $src(['r' => 'https://www.bing.com/']) === 'search' && $src(['r' => 'https://sklep.test/pl/']) === 'direct' && $src([]) === 'direct'
    && $src(['r' => 'https://blog.example.org/post']) === 'other' && $src(['a' => ['m' => 'cpc', 's' => 'google']]) === 'ads', 'sources: social, ads, search engines, the shop itself, other sites');

// ---------- the report
$R = function ($f = []) use ($T0, $end) { return SpcBehaviourStore::report($f + ['from' => $T0 - 3600, 'to' => $end + 60, 'bucket' => 3600], 1, 1, $end + 60); };
$r = $R();
$k = $r['kpi'];
echo '    kpi ', json_encode($k), "\n";
ok($k['sessions'] === 31 && $k['orders'] === 10 && $k['conversion'] == 32.3 && $k['bounce'] == 25.8 && $k['cart'] == 64.5 && $k['revenue'] == 895.0, 'KPIs: 31 visits, 10 orders (32.3%), 8 one-page visits, 20 with a cart, revenue');
ok($k['returning'] == 16.1 && $k['views'] === 10 * 6 + 6 * 4 + 4 * 3 + 8 + 3 * 3, 'returning shoppers and page views');

$pts = $r['timeline']['points'];
ok(array_sum(array_column($pts, 'sessions')) === 31 && array_sum(array_column($pts, 'orders')) === 10, 'timeline: every visit and order in a bucket');
ok(count(array_filter($pts, function ($p) { return ($p['t'] + date('Z', $p['t'])) % 3600 !== 0; })) === 0 && count($pts) >= 6, 'timeline: hour buckets on the local clock (' . count($pts) . ' points)');
$day = $R(['bucket' => 86400]);
ok(count($day['timeline']['points']) === 1 + (int) (date('j', $end) !== date('j', $T0 - 3600)) && date('H:i', $day['timeline']['points'][0]['t']) === '00:00', 'timeline: day buckets start at local midnight');
$auto = SpcBehaviourStore::filters(['from' => $T0 - 30 * 86400, 'to' => $T0], $T0);
ok($auto['bucket'] === 86400 && SpcBehaviourStore::filters(['from' => $T0 - 3 * 3600, 'to' => $T0], $T0)['bucket'] === 900 && SpcBehaviourStore::filters(['from' => $T0 - 365 * 86400, 'to' => $T0, 'bucket' => 900], $T0)['bucket'] === 86400, 'bucket size follows the span, never more than 400 points');

$pages = array_column($r['pages'], null, 'page');
$p7 = $pages['product:7'];
ok($p7['views'] === 24 && $p7['entries'] === 8 && $p7['exits'] === 8 && $p7['exitRate'] == 33.3 && $p7['conversion'] == 41.7, 'pages: Kimchi viewed 24 times, 8 visits started and ended there, 10 of 24 visits ordered');
ok($p7['avgMs'] === (int) round((10 * 40 + 6 * 50 + 8 * 4) * 1000 / 24) && $p7['scroll'] === 80, 'pages: average engaged time and scroll on Kimchi');
ok($pages['checkout']['exits'] === 6 && $pages['pagenotfound']['exits'] === 3, 'pages: where visits ended');
ok($r['labels']['product:7'] === 'Kimchi klasyczne' && $r['labels']['category:3'] === 'Fermentowane', 'pages: product and category names');

$dw = $r['dwell'];
ok(array_sum($dw['counts']) === $k['views'] && $dw['counts'][0] === 8 + 3 + 0 && $dw['counts'][6] === 0, 'time on page in quantised buckets (<5 s: the 8 bounces and the 3 404s)');

$routes = [];
foreach ($r['routes'] as $x) { $routes[$x['from'] . '>' . $x['to']] = $x; }
ok($r['routes'][0]['count'] === 16 && $routes['product:7>cart']['count'] === 16, 'routes: Kimchi to the cart is among the most taken (16)');
ok($routes['index>category:3']['count'] === 10 && $routes['cart>checkout']['count'] === 16 && $routes['search>pagenotfound']['count'] === 3 && $routes['product:7>cart']['share'] == 66.7, 'routes: counts and share of the page\'s views');

$paths = [];
foreach ($r['paths'] as $x) { $paths[implode(' ', $x['path'])] = $x; }
ok($paths['index category product cart checkout order-confirmation']['count'] === 10 && $paths['index category product cart checkout order-confirmation']['conversion'] == 100.0
    && $paths['product']['count'] === 8 && $paths['index product cart checkout']['conversion'] == 0.0, 'paths: by page type, with how many ended in an order');

$su = $r['success'];
ok($su['paths'][0]['path'] === ['index', 'category', 'product', 'cart', 'checkout'] && $su['paths'][0]['count'] === 10, 'paths of success: the pages before an order');
ok($su['secondsToOrder'] === 20 + 30 + 40 + 25 + 120 + 5 * 2 && $su['pagesToOrder'] === 5, 'paths of success: time and pages to an order (' . $su['secondsToOrder'] . ' s)');
ok($su['cartToOrder']['newCount'] === 5 && $su['cartToOrder']['returningCount'] === 5 && $su['cartToOrder']['new'] === 25 + 120 + 3 * 2, 'cart to order, new shoppers against shoppers who ordered before');

$fu = $r['funnel'];
ok($fu === ['sessions' => 31, 'product' => 28, 'cart' => 20, 'checkout' => 16, 'personal' => 16, 'addresses' => 16, 'delivery' => 16, 'payment' => 10, 'pay' => 10, 'ordered' => 10], 'funnel: ' . json_encode($fu));
ok($r['abandoned']['exits'] === [['key' => 'checkout', 'count' => 6], ['key' => 'cart', 'count' => 4]] && $r['abandoned']['lastStep'][3] === 6 && $r['abandoned']['lastStep'][0] === 4, 'failure points: carts left in checkout (at delivery) and on the cart page');
ok($r['failures']['emptySearch'] === [['key' => 'kimchy', 'count' => 3]] && $r['failures']['notFound'] === [['key' => '/pl/stara-strona', 'count' => 3]] && $r['failures']['errors'][0]['count'] === 6, 'failure points: empty searches, 404s, errors shown');

ok(count($r['sessions']) === 31 && $r['sessions'][0]['started'] >= $r['sessions'][30]['started'] && $r['sessions'][0]['outcome'] === 'browsing' && in_array('ordered', array_column($r['sessions'], 'outcome'), true), 'visits: most recent first, with their outcome');

// ---------- filters and search
$n = function ($f) use ($R) { $x = $R($f); return isset($x['kpi']) ? $x['kpi']['sessions'] : -1; };
ok($n(['device' => 'mobile']) === 8 && $n(['device' => 'tablet']) === 4 && $n(['source' => 'search']) === 8 && $n(['source' => 'email']) === 3, 'filters: device and source');
ok($n(['outcome' => 'ordered']) === 10 && $n(['outcome' => 'abandoned']) === 10 && $n(['outcome' => 'bounced']) === 8 && $n(['outcome' => 'browsing']) === 11 && $n(['returning' => '1']) === 5 && $n(['returning' => '0']) === 26, 'filters: outcome and returning shoppers');
ok($n(['q' => 'kimchi']) === 24, 'search by name: "kimchi" finds the visits through either Kimchi');
ok($n(['q' => 'Kimchi > koszyk']) === 16 && $n(['q' => 'koszyk > Kimchi']) === 0 && $n(['q' => 'home → product:7 > checkout']) === 16, 'search for a sequence: one page after the other, in that order');
ok($n(['q' => 'product:8']) === 4 && $n(['q' => 'cart']) === 20 && $n(['q' => '404']) === 3, 'search by page key and by page type');
ok($n(['q' => '/pl/stara']) === 3 && $n(['q' => 'customer:5']) === 5 && $n(['q' => 'anna@example']) === 5, 'search by address and by customer');
ok($n(['q' => 'Kimchi', 'outcome' => 'ordered', 'device' => 'desktop']) === 10, 'search and filters together');
$none = $R(['q' => 'pierogi']);
ok(!empty($none['empty']) && $none['search']['terms'][0]['keys'] === [], 'a name nothing on the shop has: an empty answer that says so');
$evil = $R(['q' => "') OR 1=1 -- > %"]);
ok(!empty($evil['empty']) || (isset($evil['kpi']) && $evil['kpi']['sessions'] === 0), 'a search full of SQL is only a search');

// ---------- one visit
$one = SpcBehaviourStore::session((int) $s['id_session'], 1, 1);
ok(count($one['views']) === 6 && $one['views'][2]['page'] === 'product:7' && $one['views'][2]['events'][0]['type'] === 'cart' && $one['views'][4]['events'][4]['type'] === 'pay', 'one visit page by page, with what happened on each');
ok($one['views'][2]['activeMs'] === 40000 && $one['labels']['product:7'] === 'Kimchi klasyczne' && $one['outcome'] === 'ordered', 'one visit: engaged time per page and names');
ok(SpcBehaviourStore::session((int) $s['id_session'], 2, 1) === ['error' => 'not found'], 'a visit of another shop is not shown');

// for the browser test of the report: this report and visit as the module answers them, with a
// product name carrying markup (it must show as text)
if (getenv('SPC_BH_REPORT')) {
    $dump = $R();
    $dump['labels']['product:8'] = '<img src=x onerror="window.__pwned=1">Zakwas';
    file_put_contents(getenv('SPC_BH_REPORT'), json_encode(['report' => $dump, 'session' => $one]));
}

// ---------- clean-up
ok(SpcBehaviourStore::purge(90, $end) === 1 && (int) q('SELECT COUNT(*) FROM bht_spc_bh_session') === 32 && (int) q('SELECT COUNT(*) FROM bht_spc_bh_view WHERE at < ' . ($T0 - 86400)) === 0, 'visits older than the kept days go, with their pages');
ok(SpcBehaviourStore::uninstall() && q("SHOW TABLES LIKE 'bht_spc_bh_session'") === false, 'uninstall drops the tables');
foreach (['product_lang', 'category_lang', 'cms_lang', 'customer'] as $t) { $db->execute("DROP TABLE IF EXISTS bht_$t"); }
echo "ALL OK\n";
