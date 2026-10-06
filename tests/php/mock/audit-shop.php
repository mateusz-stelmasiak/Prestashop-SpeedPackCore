<?php
/**
 * A mock shop for the audit test, run with php -S. Each request goes through the module's own
 * SpcAudit::apply(), as PrestaShop would at actionDispatcherBefore: the audit cookie is checked
 * with the real key, the data cache is switched off through Db::disableCache(), and the
 * X-SpeedPack-Audit header goes out. Pages answer in 40 ms with the data cache, 220 ms without.
 *
 * The test writes the shared state to the system temp folder: the audit key, and "pagecache"
 * to make this shop behave like one behind a page cache (the same stored answer, no header).
 */
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'ps_');
function spc_state()
{
    $s = json_decode((string) @file_get_contents(sys_get_temp_dir() . '/spc-audit-shop.json'), true);

    return is_array($s) ? $s : ['key' => '', 'pagecache' => false];
}
class Configuration { static function get($k) { return $k === 'SPC_AUDIT_KEY' ? spc_state()['key'] : false; } static function updateValue($k, $v) { return true; } }
class Tools { static function strlen($s) { return strlen((string) $s); } static function passwdGen($n = 8) { return str_repeat('x', $n); } }
class Db
{
    static $i = []; public $cache = true;
    static function getInstance($master = true) { $k = (int) $master; if (!isset(self::$i[$k])) { self::$i[$k] = new Db(); } return self::$i[$k]; }
    function disableCache() { $this->cache = false; }
}
require (getenv('SPC_ROOT') ?: dirname(__DIR__, 3)) . '/speedpackcore/classes/SpcAudit.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = function ($what) { file_put_contents(sys_get_temp_dir() . '/spc-audit-shop.log', $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . ' ' . $what . "\n", FILE_APPEND); };
$state = spc_state();

if ($state['pagecache'] && $_SERVER['REQUEST_METHOD'] === 'GET' && strpos($path, '/module/') !== 0) {
    // a page cache: the stored page, whatever the cookie; PrestaShop does not run
    $log('cached');
    echo '<html><body>cached copy</body></html>';
    exit;
}

$parts = SpcAudit::apply();
$label = $parts === null ? 'visitor' : SpcAudit::label($parts);
$cache = Db::getInstance()->cache && Db::getInstance(false)->cache;
$log('audit=' . $label . ' cache=' . ($cache ? 'on' : 'off'));

if (strpos($path, '/module/speedpackcore/add') === 0) {
    usleep(30000);
    if (($_POST['token'] ?? '') !== str_repeat('ab', 16)) { echo '{"ok":false}'; exit; }
    echo json_encode(['ok' => true, 'count' => 1]);
    exit;
}
if (strpos($path, '/module/speedpackcore/remove') === 0) { echo '{"ok":true}'; exit; }
if ($path === '/pl/cart') { usleep(160000); echo '{"success":true}'; exit; }
setcookie('PrestaShop-abc', 'visitor1', 0, '/');
usleep($cache ? 40000 : 220000);
echo '<html><script>var prestashop = {"static_token":"' . str_repeat('ab', 16) . '"};</script><body>' . htmlspecialchars($path) . '</body></html>';
