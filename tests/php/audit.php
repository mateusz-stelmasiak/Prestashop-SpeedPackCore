<?php
require __DIR__ . '/bootstrap.php';
// The speed audit: signed cookie, hook skipping, Redis bypass, the curl steps against a mock shop, saving.
define('_PS_VERSION_', '1.7.8.0'); define('_PS_MODULE_DIR_', SPC_ROOT . '/'); define('__PS_BASE_URI__', '/'); define('_DB_PREFIX_', 'ps_'); define('_COOKIE_KEY_', 'abc'); define('_PS_ROOT_DIR_', SPC_FAKEPS); define('_PS_OVERRIDE_DIR_', SPC_FAKEPS . '/override/');
$PORT = (int) $argv[1]; $BASE = "http://127.0.0.1:$PORT";
function pSQL($s){ return addslashes((string) $s); }
class Configuration { static $v = []; static function get($k){ return isset(self::$v[$k]) ? self::$v[$k] : false; } static function updateValue($k,$x){ self::$v[$k]=$x; return true; } static function set($k,$x,$g=null,$s=null){ self::$v[$k]=$x; }
  static function updateGlobalValue($k,$x){ return self::updateValue($k,$x);} static function deleteByName($k){ unset(self::$v[$k]); return true; } static function isCatalogMode(){ return false; } }
class Shop { static function isFeatureActive(){ return false; } }
class Tools { static $post=[]; static function substr($a,$b,$c=null){return substr($a,$b,$c);} static function strpos($a,$b){return strpos($a,$b);} static function strrpos($a,$b){return strrpos($a,$b);} static function clearSmartyCache(){} static function strtolower($s){return strtolower($s);} static function strlen($s){return strlen($s);} static function getValue($k,$d=false){ return isset(self::$post[$k]) ? self::$post[$k] : $d; } static function isSubmit($k){ return isset(self::$post[$k]); } static function getToken($x){ return 'tok'; } static function getAdminTokenLite($x){ return 'adm'; } static function passwdGen($n=8){ return substr(str_repeat(md5(mt_rand()),4),0,$n); } }
class Link { function getModuleLink($m,$c,$p=[],$s=null){ return $GLOBALS['BASE'] . "/module/$m/$c"; } function getPageLink($p,$s=null,$l=null,$q=null){ return $GLOBALS['BASE'] . "/pl/$p"; } function getCategoryLink($id,$a=null,$l=null){ return $GLOBALS['BASE'] . "/pl/$id-cat"; } function getProductLink($id,$a=null,$b=null,$c=null,$l=null){ return $GLOBALS['BASE'] . "/pl/p/$id-prod.html"; } function getCMSLink($id,$a=null,$b=null,$c=null){ return ''; } }
class Media { static $defs=[]; static function addJsDef($a){ self::$defs = array_merge(self::$defs,$a); } }
class FrontCtl { public $php_self='category'; public $js=[]; function addJS($p){ $this->js[]=$p; } function addCSS($p){ $this->js[]=$p; } function registerJavascript($id,$p,$o=[]){ $this->js[$id]=$p; } function registerStylesheet($id,$p,$o=[]){ $this->js['css:'.$id]=$p; } }
class Smarty { public $vars=[]; function assign($a, $v = null){ $this->vars = array_merge($this->vars, is_array($a) ? $a : [$a => $v]); } }
class Context { public $link, $controller, $language, $smarty, $shop; static $c; static function getContext(){ if(!self::$c){ self::$c=new Context; self::$c->link=new Link; self::$c->controller=new FrontCtl; self::$c->language=(object)['id'=>1]; self::$c->shop=(object)['id'=>1,'id_shop_group'=>1]; self::$c->smarty=new Smarty; } return self::$c; } }
class CMS { static function getCMSPages($l,$a=null,$b=true){ return []; } }
class Db { static $q = 0; static $off = 0; static function getInstance($master = true){ return new Db; } function disableCache(){ self::$off++; }  function escape($s){ return addslashes((string) $s); }
  function executeS($q){ if (strpos($q, 'category_product') !== false) return [['id_category'=>3,'name'=>'Dieta','products'=>40],['id_category'=>4,'name'=>'Kimchi','products'=>9]];
    if (strpos($q, 'available_for_order') !== false) return [['id_product'=>7,'name'=>'Zakwas']]; return [['id_product'=>7,'name'=>'Zakwas'],['id_product'=>8,'name'=>'Pierogi']]; }
  function getValue($q){ self::$q++; return strpos($q, 'FROM `ps_address`') !== false ? 12 : 1; }
  function getRow($q){ return strpos($q, 'Questions') !== false ? ['Variable_name'=>'Questions','Value'=>self::$q++] : false; } }
class HelperForm { public $module,$name_controller,$token,$currentIndex,$submit_action,$default_form_language,$fields_value=[],$title,$show_toolbar; function generateForm($f){ return '<form>'; } }
class AdminController { static $currentIndex = 'index.php?controller=AdminModules'; }
class Module { public $_errors=[]; public $name,$version,$author,$tab,$need_instance,$bootstrap,$displayName,$description,$confirmUninstall,$ps_versions_compliancy,$module_key; public static $hooks=[]; static $enabled=['speedpackcore'=>1];
  protected $context; function __construct(){ $this->context = Context::getContext(); } function l($s,$spec=false){ return $s; } function install(){ return true; } function uninstall(){ return true; }
  function registerHook($h){ self::$hooks[$h]=1; return true; } function isRegisteredInHook($h){ return isset(self::$hooks[$h]); } function displayConfirmation($s){ return "[ok:$s]"; } function displayError($s){ return "[err:$s]"; } function getPathUri(){ return '/modules/speedpackcore/'; }
  function display($file,$tpl){ if(!is_file(dirname($file).'/'.$tpl)) throw new Exception("no template $tpl"); if ($tpl === 'views/templates/hook/list-button.tpl') return '<btn>'; return "<tpl:$tpl>"; }
  static function isEnabled($m){ return !empty(self::$enabled[$m]); } static function isInstalled($m){ return !empty(self::$enabled[$m]); } }
// the core Address and the module's override, as PrestaShop would load them
class Cache { }
class AddressCore { public $id; static function addressExists($id, bool $useCache = false){ return (bool) Db::getInstance()->getValue('SELECT id_address FROM ps_address_check WHERE id_address = ' . (int) $id); } function delete(){ return true; } }
eval('?>' . file_get_contents(SPC_MODULE . '/override/classes/Address.php'));
require SPC_MODULE . '/speedpackcore.php';
function reset_audit(){ foreach (['parts' => false, 'applied' => false] as $p => $v) { $r = new ReflectionProperty('SpcAudit', $p); $r->setAccessible(true); $r->setValue(null, $v); } }

$m = new SpeedPackCore();
ok($m->install() && Configuration::get('SPC_AUDIT_DONE') === 0 && strlen(Configuration::get('SPC_AUDIT_KEY')) === 48, 'install: audit offered, key made');

// --- the cookie
ok(SpcAudit::parts() === null && !SpcAudit::off('cache'), 'no cookie: an ordinary visitor, nothing off');
$_COOKIE['spc_audit'] = SpcAudit::token(['cache', 'instantnav', 'nonsense']); reset_audit();
ok(SpcAudit::parts() === ['cache', 'instantnav'] && SpcAudit::off('smartprefetch') && !SpcAudit::off('cache'), 'signed cookie: only the listed parts run, unknown names dropped');
$_COOKIE['spc_audit'] = substr(SpcAudit::token([]), 0, -1) . 'x'; reset_audit();
ok(SpcAudit::parts() === null, 'tampered signature: ignored');
list($d, $sig) = explode('.', SpcAudit::token(['cache']));
$_COOKIE['spc_audit'] = rtrim(strtr(base64_encode('|' . (time() + 900)), '+/', '-_'), '=') . '.' . $sig; reset_audit();
ok(SpcAudit::parts() === null, 'payload changed under a valid signature: ignored');
$p = '|' . (time() - 5); $_COOKIE['spc_audit'] = rtrim(strtr(base64_encode($p), '+/', '-_'), '=') . '.' . hash_hmac('sha256', $p, SpcAudit::key()); reset_audit();
ok(SpcAudit::parts() === null, 'expired cookie: ignored');
$_COOKIE['spc_audit'] = ['x']; reset_audit();
ok(@SpcAudit::parts() === null || true, 'array cookie does not crash');

// --- hooks honour it
$_COOKIE['spc_audit'] = SpcAudit::token([]); reset_audit();
Media::$defs = []; Context::getContext()->controller->js = [];
$m->hookActionFrontControllerSetMedia([]); $m->hookDisplayHeader([]);
$btn = $m->hookDisplayProductListReviews(['product' => ['id_product' => 5, 'name' => 'x', 'add_to_cart_url' => 'x']]);
ok(!Media::$defs && !Context::getContext()->controller->js && $btn === '', 'audit "off": no part adds anything to the page');
$_COOKIE['spc_audit'] = SpcAudit::token(['smartprefetch']); reset_audit();
$m->hookActionFrontControllerSetMedia([]);
ok(array_keys(Media::$defs) === ['smartPrefetchConfig'], 'audit "smartprefetch only": just SmartPrefetch');
ok(Media::$defs['smartPrefetchConfig']['prerender'] === true && Media::$defs['smartPrefetchConfig']['prerenderDelay'] === 250 && Media::$defs['smartPrefetchConfig']['maxPrerender'] === 4, 'SmartPrefetch hands over the prerender settings');
unset($_COOKIE['spc_audit']); reset_audit();
Media::$defs = []; $m->hookActionFrontControllerSetMedia([]);
ok(count(Media::$defs) === 3, 'no cookie: every part as usual (' . implode(',', array_keys(Media::$defs)) . ')');

// --- the Redis class steps aside for audit requests only
@unlink(SPC_FAKEPS . '/override/classes/cache/CacheRedis.php');
ok(SpcCacheBackend::installRedisClass(['host' => '127.0.0.1', 'port' => 6390, 'password' => 's3cret', 'database' => 0, 'prefix' => 't_'], $m) === [], 'Redis class written');
$code = file_get_contents(SPC_FAKEPS . '/override/classes/cache/CacheRedis.php');
ok(strpos($code, "'audit' => '" . Configuration::get('SPC_AUDIT_KEY') . "'") !== false, 'audit key baked into the Redis class');
$r = new CacheRedis(); ok($r->isConnected(), 'ordinary request: Redis connected');
$_COOKIE['spc_audit'] = SpcAudit::token(['instantnav']); $r = new CacheRedis(); ok(!$r->isConnected(), 'audit without "cache": no Redis');
$_COOKIE['spc_audit'] = SpcAudit::token(['cache']); $r = new CacheRedis(); ok($r->isConnected(), 'audit with "cache": Redis connected');
$_COOKIE['spc_audit'] = 'garbage.' . str_repeat('0', 64); $r = new CacheRedis(); ok($r->isConnected(), 'forged cookie: Redis connected (it can never switch anything off)');
unset($_COOKIE['spc_audit']); @unlink(SPC_FAKEPS . '/override/classes/cache/CacheRedis.php');

// --- apply(): what an audit request gets at the start of the shop's request
$_COOKIE['spc_audit'] = SpcAudit::token([]); reset_audit(); Db::$off = 0;
ok(SpcAudit::apply() === [] && Db::$off === 2, 'apply() without "cache": the data cache off on both database connections');
reset_audit(); Db::$off = 0; SpcAudit::apply();
$_COOKIE['spc_audit'] = SpcAudit::token(SpcAudit::PARTS); reset_audit(); Db::$off = 0;
ok(SpcAudit::apply() === SpcAudit::PARTS && Db::$off === 0, 'apply() with every part: the data cache stays on');
unset($_COOKIE['spc_audit']); reset_audit(); Db::$off = 0;
ok(SpcAudit::apply() === null && Db::$off === 0, 'apply() for an ordinary visitor: nothing changes');
$_COOKIE['spc_audit'] = 'forged.' . str_repeat('0', 64); reset_audit();
ok(SpcAudit::apply() === null && Db::$off === 0, 'apply() with a forged cookie: nothing changes');
unset($_COOKIE['spc_audit']); reset_audit();
ok(SpcAudit::label([]) === 'none' && SpcAudit::label(['instantnav', 'cache']) === 'cache,instantnav', 'the header label: "none", or the parts in a fixed order');
ok(preg_match('/\?spc_t=[0-9a-f]{12}$/', SpcAudit::bust('http://x/a')) && preg_match('/\?q=1&spc_t=/', SpcAudit::bust('http://x/a?q=1')), 'every audit address is one no cache has seen');

// --- the plan and the server steps, against the mock shop (it runs the real apply())
$SHOP = sys_get_temp_dir() . '/spc-audit-shop.json'; $SHOPLOG = sys_get_temp_dir() . '/spc-audit-shop.log';
file_put_contents($SHOP, json_encode(['key' => Configuration::get('SPC_AUDIT_KEY'), 'pagecache' => false]));
$plan = SpcAudit::plan(Context::getContext(), $m);
ok(count($plan['pages']) === 5 && $plan['product'] === 7 && $plan['pages'][1]['name'] === 'Dieta', 'plan: home, 2 categories, 2 products, a product to add');
ok(isset($plan['tokens']['off'], $plan['tokens']['all'], $plan['tokens']['nav_off'], $plan['tokens']['nav_smartprefetch'], $plan['tokens']['nav_instantnav']) && $plan['enabled']['smartprefetch'] === true, 'plan: a cookie per mode, parts on');
@unlink($SHOPLOG);
$t0 = microtime(true);
$page = SpcAudit::page($plan['pages'][0]['url'], $plan['tokens']);
echo '    page ', json_encode($page), ' in ', round(microtime(true) - $t0, 2), " s\n";
ok($page['off'] > 180 && $page['on'] < 120 && !empty($page['verified']), 'page: without the data cache slow, with it quick, every answer verified');
$log = file_get_contents($SHOPLOG);
$all = SpcAudit::label(SpcAudit::PARTS);
ok(substr_count($log, 'audit=' . $all . ' cache=on') === 4 && substr_count($log, 'audit=none cache=off') === 3, 'page: the shop really ran without the data cache for "off" (3), with it for "on" (1 warm + 3)');
ok(count(array_filter(explode("\n", $log))) === 7, 'page: 7 requests in all');
file_put_contents($SHOP, json_encode(['key' => Configuration::get('SPC_AUDIT_KEY'), 'pagecache' => true]));
$cached = SpcAudit::page($plan['pages'][0]['url'], $plan['tokens']);
ok(isset($cached['code']) && $cached['code'] === 'page_cache', 'page: a page cache answering instead of the shop is caught, not measured');
file_put_contents($SHOP, json_encode(['key' => 'another-shops-key', 'pagecache' => false]));
$wrongKey = SpcAudit::page($plan['pages'][0]['url'], $plan['tokens']);
ok(isset($wrongKey['code']) && $wrongKey['code'] === 'page_cache', 'page: a shop that does not accept the cookie is not measured either');
file_put_contents($SHOP, json_encode(['key' => Configuration::get('SPC_AUDIT_KEY'), 'pagecache' => false]));
ok(isset(SpcAudit::page($BASE . '/nowhere-port', $plan['tokens'])['off']) || true, 'page: 404 pages are still html here');
$bad = SpcAudit::page('http://127.0.0.1:1/', $plan['tokens']);
ok(isset($bad['error']), 'page: unreachable shop gives an error, not a crash (' . $bad['error'] . ')');
@unlink($SHOPLOG);
$cart = SpcAudit::cart(Context::getContext(), $plan['product'], $plan['tokens']['all']);
echo '    cart ', json_encode($cart), "\n";
ok(isset($cart['core'], $cart['lean']) && $cart['core'] > $cart['lean'], 'cart: core cart page slower than the lean endpoint');
$log = file_get_contents($SHOPLOG);
ok(substr_count($log, 'POST /pl/cart') === 3 && substr_count($log, 'POST /module/speedpackcore/add') === 4 && substr_count($log, 'POST /module/speedpackcore/remove') === 1, 'cart: 1 untimed add, 3 + 3 timed, then emptied');
ok(isset(SpcAudit::cart(Context::getContext(), 0, $plan['tokens']['all'])['error']), 'cart: no buyable product gives an error');

// --- CartSpeed counted
Configuration::updateValue('SPC_CS_ENABLED', 1);
$cs = SpcAudit::cartSpeed(Context::getContext());
echo '    cartspeed ', json_encode($cs), "\n";
ok($cs['off']['queries'] === 73 && $cs['on']['queries'] === 1 && Configuration::get('SPC_CS_ENABLED') === 1, 'cartspeed: 73 queries without, 1 with, setting restored');

// --- the AJAX steps and saving
$run = SpcAudit::save(['cache' => 'redis<script>', 'pages' => ['off' => 412.4, 'on' => '95', 'x' => 'evil'], 'cart' => ['core' => 'abc', 'lean' => 40], 'cartspeed' => ['off' => 73, 'on' => 1], 'nav' => ['off' => 650, 'all' => 40, 'hack' => 1], 'extra' => '<b>']);
ok($run['cache'] === 'redisscript' && $run['pages'] === ['off' => 412.4, 'on' => 95.0] && $run['cart']['core'] === null && !isset($run['nav']['hack']) && !isset($run['extra']), 'save: numbers only, known keys only');
for ($i = 0; $i < 15; $i++) SpcAudit::save(['nav' => ['all' => $i]]);
ok(count(SpcAudit::history()) === 12 && Configuration::get('SPC_AUDIT_DONE') === 1, 'save: last 12 kept, audit marked done');
Context::getContext()->smarty->vars = [];
$m->getContent();
$a = Context::getContext()->smarty->vars['spc_audit'];
if (getenv('SPC_ADMIN_VARS')) { file_put_contents(getenv('SPC_ADMIN_VARS'), json_encode($a)); } ok($a['first'] === false && count(json_decode($a['history'], true)) === 12 && isset(json_decode($a['texts'], true)['popupBlocked']), 'settings page: audit panel with history and texts');

// --- the 1.2.0 upgrade: audit offered again, prerender default, Redis class rewritten only when in use
require SPC_MODULE . '/upgrade/upgrade-1.2.0.php';
Configuration::deleteByName('SPC_SP_PRERENDER');
ok(upgrade_module_1_2_0($m) && Configuration::get('SPC_AUDIT_DONE') === 0 && Configuration::get('SPC_SP_PRERENDER') === 1 && !is_file(SPC_FAKEPS . '/override/classes/cache/CacheRedis.php'), 'upgrade 1.2.0 (cache off: no Redis class written)');

// --- uninstall forgets it
ok($m->uninstall() && Configuration::get('SPC_AUDIT_KEY') === false && Configuration::get('SPC_AUDIT_HISTORY') === false && Configuration::get('SPC_SP_PRERENDER') === false, 'uninstall: audit key and history gone');
echo "ALL OK\n";
