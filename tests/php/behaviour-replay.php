<?php
require __DIR__ . '/bootstrap.php';
// The two halves of Behaviour together: everything behaviour.js sent in the browser test
// (tests/browser/behaviour.e2e.js, written to SPC_BH_MESSAGES) stored by the real store in MariaDB.
// Every page view and event the browser sent must be kept, with the browser's page types.
//   php behaviour-replay.php MESSAGES_JSON
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'bhr_');
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

$msgs = json_decode((string) @file_get_contents($argv[1]), true);
ok(is_array($msgs) && count($msgs) > 20, 'messages from the browser test: ' . (is_array($msgs) ? count($msgs) : 0));
SpcBehaviourStore::uninstall();
SpcBehaviourStore::install();
$db = Db::getInstance();
$state = ['id' => 0, 'last' => 0];
$now = time();
// as the shop window sent them: a view in its own request, the rest batched after it
foreach ($msgs as $m) {
    $state = SpcBehaviourStore::collect($state, [$m], ['id_shop' => 1, 'host' => '127.0.0.1', 'id_customer' => 0, 'returning' => false], $now++);
}
$v = array_values(array_filter($msgs, function ($m) { return $m['t'] === 'v'; }));
$e = array_values(array_filter($msgs, function ($m) { return $m['t'] === 'e'; }));
$keys = array_column($v, 'k');
$withView = array_filter($e, function ($m) use ($keys) { return in_array($m['k'], $keys, true); });
ok((int) $db->getValue('SELECT COUNT(*) FROM bhr_spc_bh_view') === count($v), 'every page view kept (' . count($v) . ')');
ok((int) $db->getValue('SELECT COUNT(*) FROM bhr_spc_bh_event') === count($withView), 'every event kept (' . count($withView) . ')');
$types = array_column($db->executeS('SELECT DISTINCT SUBSTRING_INDEX(page, \':\', 1) t FROM bhr_spc_bh_view ORDER BY t'), 't');
ok(!array_diff(['cart', 'category', 'checkout', 'index', 'product', 'search'], $types), 'page types as the browser read them: ' . implode(', ', $types));
ok((int) $db->getValue("SELECT COUNT(*) FROM bhr_spc_bh_view WHERE page = 'product:7'") >= 1 && (int) $db->getValue("SELECT COUNT(*) FROM bhr_spc_bh_view WHERE page = 'category:3' AND nav = 1") >= 1, 'objects and InstantNav swaps kept');
ok((int) $db->getValue('SELECT MAX(checkout) FROM bhr_spc_bh_session') === 5 && (int) $db->getValue('SELECT COUNT(*) FROM bhr_spc_bh_session WHERE cart_at > 0') >= 1, 'checkout steps, pay and the add to cart reach the visit');
ok((int) $db->getValue('SELECT SUM(active_ms) FROM bhr_spc_bh_view') > 3000, 'engaged time kept');
$pv = $db->getRow("SELECT lcp_ms, ttfb_ms, fcp_ms, cls, inp_ms FROM bhr_spc_bh_view WHERE page = 'product:7' AND lcp_ms IS NOT NULL LIMIT 1");
ok($pv && $pv['lcp_ms'] > 0 && $pv['ttfb_ms'] > 0 && $pv['cls'] >= 100 && $pv['inp_ms'] >= 250, 'Core Web Vitals from Chromium stored: ' . json_encode($pv));
ok($db->getValue("SELECT detail FROM bhr_spc_bh_event WHERE type = 'search'") === 'kimchy' && $db->getValue("SELECT detail FROM bhr_spc_bh_event WHERE type = 'error'") === 'Produkt niedostępny', 'a search and an error, word for word');
$ad = array_values(array_filter($v, function ($m) { return isset($m['a']); }));
ok($ad && SpcBehaviourStore::source($ad[0], '127.0.0.1') === ['ads', 'fb', 'jesien'], 'the ad link as the browser sent it: source ads, fb, its campaign (a visit keeps the source it started with)');
SpcBehaviourStore::uninstall();
echo "ALL OK\n";
