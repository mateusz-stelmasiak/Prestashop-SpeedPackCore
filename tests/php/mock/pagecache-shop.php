<?php
/**
 * A shop front as far as the page cache sees it, for php -S: each request goes through
 * SpcPageCache::serve() (at the dispatcher) and, when built, SpcPageCache::store() (on the way
 * out). The page carries the time it was built, so a kept page is told from a new one.
 *   SPC_PC_TMP: folder for the kept pages; SPC_DB_*: the database (as tests/php/pagecache.php)
 */
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'pcs_');
define('_PS_CACHE_DIR_', getenv('SPC_PC_TMP') . '/');
$root = (getenv('SPC_ROOT') ?: dirname(__DIR__, 4)) . '/speedpackcore';

class Db
{
    static $i; public $pdo;
    static function getInstance($master = true) { if (!self::$i) { self::$i = new Db(); self::$i->pdo = new PDO(getenv('SPC_DB_DSN') ?: 'mysql:host=localhost;dbname=spctest;charset=utf8mb4', getenv('SPC_DB_USER') ?: 'lp', getenv('SPC_DB_PASS') ?: 'lp', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } return self::$i; }
    function execute($sql) { return $this->pdo->exec($sql) !== false; }
    function executeS($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function getRow($sql) { $r = $this->executeS($sql); return $r ? $r[0] : false; }
    function getValue($sql) { $r = $this->getRow($sql); return $r ? reset($r) : false; }
    function escape($s) { return substr($this->pdo->quote((string) $s), 1, -1); }
}
class Configuration
{
    static function get($k)
    {
        $v = ['SPC_PC_ENABLED' => 1, 'SPC_PC_TTL' => 12, 'SPC_PC_PAGES' => 'index,category,product', 'SPC_PC_MOBILE' => 1, 'PS_SHOP_ENABLE' => 1];
        return isset($v[$k]) ? $v[$k] : false;
    }
}
class Tools
{
    static function getValue($k, $d = false) { return isset($_GET[$k]) ? $_GET[$k] : $d; }
    static function strtolower($s) { return mb_strtolower((string) $s); }
    static function usingSecureMode() { return false; }
    static function getHttpHost($a = false, $b = false, $c = false) { return $_SERVER['HTTP_HOST']; }
}
class Validate { static function isLoadedObject($o) { return is_object($o) && !empty($o->id); } }
class Module { static function isEnabled($m) { return false; } }
class SpcAudit { static function parts() { return isset($_COOKIE['spc_audit']) ? ['cache'] : null; } static function off($p) { return false; } }
class SpcCartAnswer { static function count($cart) { return 0; } }
class SpcOptimize { static function imageFormat() { return ''; } }
class Cookie { function __get($k) { return isset($_COOKIE['ps_' . $k]) ? $_COOKIE['ps_' . $k] : null; } }
require $root . '/classes/SpcFeature.php';
require $root . '/classes/SpcPageCache.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$controller = $path === '/' ? 'index' : (preg_match('#^/\d+-.+\.html$#', $path) ? 'product' : (strpos($path, '/koszyk') === 0 ? 'cart' : 'category'));
$context = (object) [
    'cookie' => new Cookie(), 'shop' => (object) ['id' => 1],
    'customer' => new class { function isLogged() { return !empty($_COOKIE['ps_id_customer']); } },
    'cart' => (object) ['id' => 0], 'controller' => (object) ['errors' => []],
];
if (SpcPageCache::serve($controller, $context)) {
    exit; // not reached: serve() ends the request
}
$html = '<!doctype html><html><head><title>' . $controller . '</title></head><body><h1>' . htmlspecialchars($path) . '</h1><p id="built">' . microtime(true) . '</p>'
    . str_repeat('<p>Kapusta kiszona z Alhambry</p>', 40) . '</body></html>';
SpcPageCache::store($controller, $html, $context);
header('Content-Type: text/html; charset=utf-8');
echo $html;
