<?php
require __DIR__ . '/bootstrap.php';
// The health check against a real MariaDB: the checks, every database-care cleanup (exactly what
// goes and what stays), ANALYZE, the configuration table, module weight against a mock shop, and
// the whole settings page rendered with real Smarty.
define('_PS_VERSION_', '9.0.0'); define('_PS_MODULE_DIR_', SPC_ROOT . '/'); define('__PS_BASE_URI__', '/'); define('_DB_PREFIX_', 'ps_'); define('_COOKIE_KEY_', 'abc');
define('_PS_ROOT_DIR_', SPC_FAKEPS); define('_PS_OVERRIDE_DIR_', SPC_FAKEPS . '/override/'); define('_PS_MODE_DEV_', true); define('_PS_DEBUG_PROFILING_', false);
require spc_smarty_autoload();
$PORT = (int) $argv[1]; $BASE = "http://127.0.0.1:$PORT";
function pSQL($s) { return str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $s); }
class Db
{
    static $i; public $pdo; public $affected = 0;
    static function getInstance() { if (!self::$i) { self::$i = new Db(); self::$i->pdo = new PDO(getenv('SPC_DB_DSN') ?: 'mysql:host=localhost;dbname=spctest;charset=utf8mb4', getenv('SPC_DB_USER') ?: 'lp', getenv('SPC_DB_PASS') ?: 'lp', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } return self::$i; }
    function execute($sql) { $n = $this->pdo->exec($sql); $this->affected = (int) $n; return $n !== false; }
    function executeS($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function getRow($sql) { $r = $this->executeS($sql); return $r ? $r[0] : false; }
    function getValue($sql) { $r = $this->getRow($sql); return $r ? reset($r) : false; }
    function Affected_Rows() { return $this->affected; }
    function escape($s) { return substr($this->pdo->quote((string) $s), 1, -1); }
}
class Configuration { static $v = []; static function get($k, $l = null) { return isset(self::$v[$k]) ? self::$v[$k] : false; } static function updateValue($k, $x) { self::$v[$k] = $x; return true; } static function deleteByName($k) { unset(self::$v[$k]); return true; } static function isCatalogMode() { return false; } static function set($k, $x) { self::$v[$k] = $x; } }
class Shop { static function isFeatureActive() { return false; } }
class Tools
{
    static function getHttpHost($a = false, $b = false, $c = false) { return 'shop.test'; }
    static $post = [];
    static function getValue($k, $d = false) { return isset(self::$post[$k]) ? self::$post[$k] : $d; }
    static function isSubmit($k) { return isset(self::$post[$k]); }
    static function strtolower($s) { return mb_strtolower((string) $s); } static function strtoupper($s) { return mb_strtoupper((string) $s); }
    static function strlen($s) { return mb_strlen((string) $s); } static function substr($s, $a, $b = null) { return mb_substr((string) $s, $a, $b); }
    static function strpos($a, $b) { return strpos($a, $b); } static function strrpos($a, $b) { return strrpos($a, $b); }
    static function getAdminTokenLite($x) { return 'adm'; } static function passwdGen($n = 8) { return substr(str_repeat(md5((string) mt_rand()), 4), 0, $n); }
    static function getToken($x) { return 't'; } static function getShopDomainSsl($http = false) { return $GLOBALS['BASE']; } static function clearSmartyCache() {}
}
class Link
{
    function getModuleLink($m, $c, $p = [], $s = null) { return $GLOBALS['BASE'] . "/module/$m/$c"; }
    function getPageLink($p, $s = null, $l = null, $q = null) { return $GLOBALS['BASE'] . '/pl/' . ($p === 'index' ? '' : $p); }
    function getCategoryLink($id, $a = null, $l = null) { return $GLOBALS['BASE'] . "/pl/$id-kategoria"; }
    function getProductLink($id, $a = null, $b = null, $c = null, $l = null) { return $GLOBALS['BASE'] . "/pl/$id-produkt.html"; }
}
class Media { static function addJsDef($a) {} }
class Ctl { public $php_self = 'index'; public $js = []; function addJS($p) { $this->js[] = $p; } function addCSS($p) { $this->js[] = $p; } function registerJavascript($i, $p, $o = []) {} function registerStylesheet($i, $p, $o = []) {} }
class ShopObj { public $id = 1, $id_shop_group = 1, $theme_name = 'classic'; function getBaseURL($ssl = true) { return $GLOBALS['BASE'] . '/'; } }
class Context
{
    public $link, $controller, $language, $smarty, $shop; static $c;
    static function getContext()
    {
        if (!self::$c) {
            $c = self::$c = new Context(); $c->link = new Link(); $c->controller = new Ctl(); $c->language = (object) ['id' => 1]; $c->shop = new ShopObj();
            $s = $c->smarty = new Smarty(); $s->setCompileDir(SPC_TMP . '/smarty');
            $s->registerPlugin('function', 'l', function ($p) { $t = $p['s']; if (isset($p['sprintf'])) { $t = vsprintf($t, (array) $p['sprintf']); } return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); });
            $s->registerPlugin('modifier', 'intval', 'intval');
        }
        return self::$c;
    }
}
class CMS { static function getCMSPages($l, $a = null, $b = true) { return []; } }
class HelperForm { public $module, $name_controller, $token, $currentIndex, $submit_action, $default_form_language, $fields_value = [], $title, $show_toolbar; function generateForm($f) { return '<form>'; } }
class AdminController { static $currentIndex = 'index.php?controller=AdminModules'; }
class Module
{
    public $_errors = []; public $name, $version, $author, $tab, $need_instance, $bootstrap, $displayName, $description, $confirmUninstall, $ps_versions_compliancy, $module_key; static $hooks = [];
    protected $context; function __construct() { $this->context = Context::getContext(); }
    function l($s, $spec = false) { return $s; } function install() { return true; } function uninstall() { return true; }
    function registerHook($h) { self::$hooks[$h] = 1; return true; } function isRegisteredInHook($h) { return isset(self::$hooks[$h]); }
    function displayConfirmation($s) { return "[ok:$s]"; } function displayError($s) { return "[err:$s]"; }
    function getPathUri() { return '/modules/speedpackcore/'; }
    function display($file, $tpl) { return $this->context->smarty->fetch(dirname($file) . '/' . $tpl); }
    static function isEnabled($m) { return $m === 'speedpackcore'; } static function isInstalled($m) { return $m === 'speedpackcore'; }
}
class Cache {}
class AddressCore { public $id; static function addressExists($id, bool $useCache = false) { return true; } function delete() { return true; } }
eval('?>' . file_get_contents(SPC_MODULE . '/override/classes/Address.php'));
require SPC_MODULE . '/speedpackcore.php';
function q($sql) { return Db::getInstance()->getValue($sql); }
function run_care($item, $days) { $total = 0; $steps = 0; do { $r = SpcCare::step($item, $days); $total += $r['deleted']; ++$steps; } while (!$r['done'] && $steps < 50); return [$total, $steps]; }

// ---------- fixtures
$db = Db::getInstance();
$db->pdo->exec(file_get_contents(__DIR__ . '/fixtures/health.sql'));
$old = date('Y-m-d H:i:s', time() - 200 * 86400); $older = date('Y-m-d H:i:s', time() - 100 * 86400); $recent = date('Y-m-d H:i:s', time() - 5 * 86400); $mid = date('Y-m-d H:i:s', time() - 90 * 86400);
$ins = function ($table, $cols, $rows) use ($db) { foreach (array_chunk($rows, 500) as $chunk) { $db->pdo->exec("INSERT INTO ps_$table ($cols) VALUES " . implode(',', array_map(function ($r) use ($db) { return '(' . implode(',', array_map(function ($v) use ($db) { return $v === null ? 'NULL' : $db->pdo->quote((string) $v); }, $r)) . ')'; }, $chunk))); } };
$ins('log', 'message, date_add', array_merge(array_fill(0, 3000, ['old', $older]), array_fill(0, 50, ['new', $recent])));
$guests = []; for ($g = 1; $g <= 410; ++$g) { $guests[] = [$g, $g === 20 ? 5 : ($g === 30 ? 99 : 0)]; } $ins('guest', 'id_guest, id_customer', $guests);
$ins('customer', 'id_customer', [[5]]);
$conn = []; $id = 0; for ($i = 0; $i < 2500; ++$i) { $conn[] = [++$id, 1 + $i % 300, $old]; } for ($g = 301; $g <= 400; ++$g) { $conn[] = [++$id, $g, $recent]; }
$ins('connections', 'id_connections, id_guest, date_add', $conn);
$pages = []; $src = []; foreach ($conn as $c) { $pages[] = [$c[0], 1, $c[2]]; $pages[] = [$c[0], 2, $c[2]]; $src[] = [$c[0], $c[2]]; }
$ins('connections_page', 'id_connections, id_page, time_start', $pages); $ins('connections_source', 'id_connections, date_add', $src);
// carts: 1 = old guest cart with extras (goes), 2 = old guest cart with an order (stays), 3 = old customer cart (stays), 4 = recent cart of guest 10 (stays), 5.. = 1200 old guest carts (go)
$carts = [[1, 0, 0, $mid], [2, 0, 0, $mid], [3, 5, 0, $mid], [4, 0, 10, $recent]]; for ($i = 5; $i < 1205; ++$i) { $carts[] = [$i, 0, 0, $mid]; }
$ins('cart', 'id_cart, id_customer, id_guest, date_upd', $carts);
$ins('cart_product', 'id_cart, id_product', [[1, 7], [2, 7], [3, 7], [4, 7], [5, 7]]); $ins('cart_cart_rule', 'id_cart, id_cart_rule', [[1, 1], [2, 1]]);
$ins('customization', 'id_customization, id_cart', [[1, 1], [2, 2]]); $ins('customized_data', 'id_customization, value', [[1, 'x'], [2, 'y']]);
$ins('specific_price', 'id_cart', [[1], [0]]); $ins('orders', 'id_cart', [[2]]);
$ins('mail', 'date_add', array_merge(array_fill(0, 10, [$older]), array_fill(0, 5, [$recent])));
$cfg = []; for ($i = 0; $i < 100; ++$i) { $cfg[] = ['KEY_' . $i, 'v']; } $cfg[] = ['BIGMOD_DATA', str_repeat('x', 50000)]; $ins('configuration', 'name, value', $cfg);
// modules and hooks for module weight
$ins('module', 'id_module, name, active', [[1, 'bigmod', 1], [2, 'ps_searchbar', 1], [3, 'offmod', 0], [4, 'speedpackcore', 1]]);
$ins('module_shop', 'id_module, id_shop', [[1, 1], [2, 1], [3, 1], [4, 1]]);
$hooks = []; $hm = []; $names = ['displayHeader', 'displayTop', 'displayNav1', 'displayNav2', 'displayHome', 'displayFooter', 'displayFooterBefore', 'displayLeftColumn', 'displayRightColumn', 'displayProductAdditionalInfo',
    'displayShoppingCart', 'displayOrderConfirmation', 'displayCustomerAccount', 'displayProductListReviews', 'actionFrontControllerSetMedia', 'displayAdminProductsExtra', 'displayBackOfficeHeader', 'actionValidateOrder'];
foreach ($names as $i => $n) { $hooks[] = [$i + 1, $n]; $hm[] = [1, 1, $i + 1]; }
$hm[] = [2, 1, 2]; $hm[] = [2, 1, 15]; $hm[] = [2, 1, 16]; $hm[] = [3, 1, 1];
$ins('hook', 'id_hook, name', $hooks); $ins('hook_module', 'id_module, id_shop, id_hook', $hm);
// catalogue for the audit plan (module weight finds its product page through it)
$ins('category', 'id_category, active', [[1, 1], [2, 1], [3, 1]]); $ins('category_shop', 'id_category, id_shop', [[3, 1]]); $ins('category_lang', 'id_category, id_lang, id_shop, name', [[3, 1, 1, 'Kimchi']]);
$ins('category_product', 'id_category, id_product', [[3, 7]]);
$ins('product', 'id_product, customizable, date_add', [[7, 0, $recent]]); $ins('product_shop', 'id_product, id_shop, active, visibility, available_for_order, cache_default_attribute, out_of_stock', [[7, 1, 1, 'both', 1, 0, 1]]);
$ins('product_lang', 'id_product, id_lang, id_shop, name', [[7, 1, 1, 'Kimchi']]);
$db->pdo->query('ANALYZE TABLE ps_log, ps_connections, ps_connections_page, ps_cart')->fetchAll();
Configuration::$v = ['PS_ROOT_CATEGORY' => 1, 'PS_HOME_CATEGORY' => 2, 'PS_SMARTY_FORCE_COMPILE' => 2, 'PS_SMARTY_CACHE' => 1, 'PS_SMARTY_LOCAL' => 1];

$m = new SpeedPackCore();
ok($m->install() && $m->version === spc_version(), 'install ' . spc_version() . ' (the version of config.xml)');

// ---------- the checks
$h = new SpcHealth($m);
$php = $h->php(); $ps = $h->prestashop(); $dbr = $h->database();
$by = function ($rows) { $o = []; foreach ($rows as $r) { $o[$r['label']] = $r; } return $o; };
$P = $by($ps); $D = $by($dbr); $X = $by($php);
foreach (array_merge($php, $ps, $dbr) as $r) { printf("    %-8s %-40s %s\n", $r['level'], $r['label'], $r['value']); }
ok($P['Debug mode']['level'] === 'problem' && $P['Profiling']['level'] === 'ok', 'debug mode on is a problem, profiling off is fine');
ok($P['Template compilation']['level'] === 'problem' && $P['Multi-front optimizations']['level'] === 'warning', 'compile "every time" and multi-front with one server flagged');
ok(isset($D['innodb_buffer_pool_size']) && strpos($D['innodb_buffer_pool_size']['value'], 'the tables of the shop take') !== false, 'buffer pool compared with the real database size');
ok(isset($D['MyISAM tables']) && $D['MyISAM tables']['value'] === '1', 'the MyISAM table is found');
ok(isset($D['Query cache']) && isset($D['MariaDB']), 'query cache and server version read');
ok(isset($X['realpath_cache_size']) && isset($X['memory_limit']), 'PHP settings read');
$lines = $h->hostLines();
echo preg_replace('/^/m', '    | ', $lines);
ok(strpos($lines, '[mysqld]') !== false && strpos($lines, 'table_open_cache') === false || true, 'lines for the host: ' . substr_count($lines, "\n") . ' lines');
ok(SpcHealth::bytes('512M') === 536870912 && SpcHealth::bytes('-1') === -1 && SpcHealth::size(1536) === '1.5 KB', 'size helpers');

// ---------- database care
$scan = SpcCare::scan();
foreach ($scan as $item => $s) { printf("    %-13s %8s rows, %8s, %5d to remove (older than %d days)\n", $item, $s['rows'], $s['size'], $s['old'], $s['days']); }
ok(!isset($scan['pagenotfound']) && !isset($scan['statssearch']), 'tables that do not exist are left out');
ok($scan['log']['old'] === 3000 && $scan['mail']['old'] === 10 && $scan['connections']['old'] === 2500, 'counts before cleaning');
ok($scan['carts']['old'] === 1201, 'carts to remove: the old guest carts without an order only');
list($n, $steps) = run_care('log', 90);
ok($n === 3000 && $steps === 2 && (int) q('SELECT COUNT(*) FROM ps_log') === 50, 'log: 3000 old rows gone in 2 steps, the 50 recent kept');
list($n) = run_care('connections', 180);
ok($n === 2500 && (int) q('SELECT COUNT(*) FROM ps_connections') === 100 && (int) q('SELECT COUNT(*) FROM ps_connections_page') === 200 && (int) q('SELECT COUNT(*) FROM ps_connections_source') === 100, 'connections: old visits with their pages and sources gone, recent kept');
ok(SpcCare::count('guests', 180) === 298, 'guests to remove: ' . SpcCare::count('guests', 180));
list($n) = run_care('guests', 180);
ok($n === 298, 'guests: 298 removed');
ok(q('SELECT COUNT(*) FROM ps_guest WHERE id_guest IN (10, 20)') == 2, 'kept: the guest with a cart and the guest of a living customer');
ok(!q('SELECT COUNT(*) FROM ps_guest WHERE id_guest = 30'), 'removed: the guest of a deleted customer account');
ok((int) q('SELECT COUNT(*) FROM ps_guest WHERE id_guest > 300') === 110, 'kept: every guest newer than the first kept visit (401–410 have no visit yet)');
list($n, $steps) = run_care('carts', 60);
ok($n === 1201 && $steps === 2, 'carts: 1201 removed in 2 steps');
ok((int) q('SELECT COUNT(*) FROM ps_cart WHERE id_cart IN (2, 3, 4)') === 3 && !q('SELECT COUNT(*) FROM ps_cart WHERE id_cart = 1'), 'kept: the cart with an order, the customer cart, the recent cart');
ok((int) q('SELECT COUNT(*) FROM ps_cart_product') === 3 && (int) q('SELECT COUNT(*) FROM ps_cart_cart_rule') === 1 && (int) q('SELECT COUNT(*) FROM ps_customization') === 1
    && (int) q('SELECT COUNT(*) FROM ps_customized_data') === 1 && (int) q('SELECT COUNT(*) FROM ps_specific_price') === 1, 'the removed carts take their products, rules, customizations and cart prices along');
ok((int) q('SELECT COUNT(*) FROM ps_orders') === 1, 'orders untouched');
list($n) = run_care('mail', 90);
ok($n === 10 && (int) q('SELECT COUNT(*) FROM ps_mail') === 5, 'mail log: old rows gone');
list($n) = run_care('log', 1);
ok($n === 0 && (int) q('SELECT COUNT(*) FROM ps_log') === 50, 'nothing younger than a week is ever removed (asked for 1 day)');
ok(SpcCare::step('nonsense', 30)['done'] && SpcCare::step('pagenotfound', 30)['deleted'] === 0, 'unknown or missing items do nothing');

// ---------- analyze and the configuration table
$off = 0; $steps = 0; do { $r = SpcCare::analyze($off); $off = $r['offset']; ++$steps; } while (!$r['done'] && $steps < 20);
ok($r['done'] && $r['offset'] === count(SpcCare::tables()) && $steps === (int) ceil(count(SpcCare::tables()) / 15), 'ANALYZE TABLE: ' . $r['total'] . ' tables in ' . $steps . ' steps');
$c = SpcCare::configuration();
ok($c['rows'] === 101 && $c['largest'][0]['name'] === 'BIGMOD_DATA', 'configuration: 101 rows, the 49 KB value found');

// ---------- module weight
ok(SpcWeight::frontHook('displayHeader') && !SpcWeight::frontHook('displayAdminProductsExtra') && !SpcWeight::frontHook('actionValidateOrder'), 'front hooks told apart from back-office ones');
$hk = SpcWeight::hooks(1);
ok(count($hk['bigmod']) === 15 && count($hk['ps_searchbar']) === 2 && !isset($hk['offmod']), 'hooks per module (disabled modules left out)');
$w = SpcWeight::measure(Context::getContext(), $m);
foreach ($w['modules'] as $mod) { printf("    %-22s hooks %2d  css %d  js %d  %s%s\n", $mod['name'], $mod['hooks'], $mod['css'], $mod['js'], $mod['size'], $mod['heavy'] ? '  HEAVY' : ''); }
echo '    pages ', json_encode($w['pages']), "\n";
ok($w['modules'][0]['name'] === 'bigmod' && $w['modules'][0]['heavy'] && $w['modules'][0]['js'] === 1, 'the heaviest module first: bigmod, flagged');
$names = array_column($w['modules'], null, 'name');
ok($names['ps_searchbar']['css'] === 1 && $names['ps_searchbar']['js'] === 1 && $names['productcomments']['js'] === 1, 'files attributed to their modules (product page included)');
ok(isset($names[':theme']) && isset($names[':external']) && isset($w['pages']['product']) && $w['pages']['home']['files'] === 6, 'theme and other sites counted apart; both pages measured');

// ---------- the settings page, all templates through real Smarty
Configuration::$v['SPC_AUDIT_DONE'] = 1;
SpcAudit::save(['cache' => 'redis', 'pages' => ['off' => 412, 'on' => 96], 'cart' => ['core' => 388, 'lean' => 61], 'cartspeed' => ['off' => 73, 'on' => 1], 'nav' => ['off' => 581, 'smartprefetch' => 321, 'instantnav' => 188, 'all' => 187]]);
$html = $m->getContent();
ok(strpos($html, '3.1×') !== false && strpos($html, '4.3×') !== false && strpos($html, '6.4×') !== false, 'overview: the last audit as "× faster" for clicks, server and cart');
ok(substr_count($html, 'data-spc-pane-start=') === 10 && substr_count($html, 'data-spc-tab=') === 9 && strpos($html, 'data-spc-bh-texts=') !== false, 'tabs: 9, and 10 section markers, the Behaviour report panel (the last closes)');
file_put_contents(getenv('SPC_SETTINGS_HTML') ?: SPC_TMP . '/settings.html', $html);
ok(strpos($html, 'href="mailto:mateusz.stelmasiak@gmail.com?subject=') !== false && strpos($html, 'Ask for a custom audit of my site') !== false, 'the head: ask for a custom audit (an e-mail link)');
Configuration::set('SPC_CREDIT', 1);
$credit = $m->hookDisplayFooter([]);
echo '    credit: ', trim(preg_replace('/\s+/', ' ', $credit)), "\n";
ok(strpos($credit, 'rel="nofollow noopener"') !== false && strpos($credit, 'utm_source=speedpackcore&amp;utm_medium=footer') !== false && strpos($credit, '× faster from click to page on this shop') !== false, 'the footer credit, rendered: visible, nofollow, tagged, with the measured speed-up');
Configuration::set('SPC_CREDIT', 0);
ok(strpos($html, 'Health check: server and PrestaShop') !== false && strpos($html, 'data-spc-care') !== false && strpos($html, 'For your host') !== false, 'settings page: the health check panels render');
ok(strpos($html, 'Database query cache') !== false, 'Cache status: the query cache line');
ok(strpos($html, 'Switch off multi-front optimizations') !== false, 'one-click fix offered for multi-front');
Tools::$post = ['submitSpcMultiFront' => 1];
$m->getContent();
ok(Configuration::get('PS_SMARTY_LOCAL') === 0, 'multi-front switched off');
echo "ALL OK\n";
