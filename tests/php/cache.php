<?php
require __DIR__ . '/bootstrap.php';
// a Redis server for the test: REDIS_PORT (6390) and REDIS_PASS (s3cret)
define('REDIS_PORT', (int) (getenv('REDIS_PORT') ?: 6390));
define('REDIS_PASS', getenv('REDIS_PASS') ?: 's3cret');
// Cache section and quantity endpoint against a fake PrestaShop folder and a real Redis.
define('_PS_VERSION_', '1.7.8.0'); define('_PS_ROOT_DIR_', SPC_FAKEPS); define('_PS_OVERRIDE_DIR_', SPC_FAKEPS . '/override/');
define('_PS_MODULE_DIR_', SPC_ROOT . '/'); define('__PS_BASE_URI__', '/'); define('_DB_PREFIX_', 'ps_'); define('_COOKIE_KEY_', 'abc');
function pSQL($s){ return addslashes((string) $s); }
class Configuration { static $v = []; static function get($k){ return self::$v[$k] ?? false; } static function updateValue($k,$x){ self::$v[$k]=$x; return true; } static function deleteByName($k){ unset(self::$v[$k]); return true; } static function isCatalogMode(){ return false; } static function updateGlobalValue($k,$x){ return true; } }
class Shop { static function isFeatureActive(){ return false; } }
class Tools { static function getHttpHost($a=false,$b=false,$c=false){ return 'shop.test'; } static $post=[]; static $log=[]; static function strtolower($s){return strtolower($s);} static function substr($a,$b,$c=null){return substr($a,$b,$c);} static function strlen($s){return strlen($s);} static function strpos($a,$b){return strpos($a,$b);} static function strrpos($a,$b){return strrpos($a,$b);}
  static function isSubmit($k){ return isset(self::$post[$k]); } static function getValue($k,$d=false){ return self::$post[$k] ?? $d; } static function getAdminTokenLite($x){ return 'adm'; } static function passwdGen($n=8){ return substr(str_repeat(md5(mt_rand()),4),0,$n); } static function getToken($x){ return 't'; }
  static function clearSf2Cache(){ self::$log[]='sf2'; } static function clearSmartyCache(){ self::$log[]='smarty'; } static function generateHtaccess(){ self::$log[]='htaccess'; return true; } static function safeOutput($s){ return htmlspecialchars($s); } static function file_get_contents($u){ return false; } }
class Media { static $defs=[]; static function addJsDef($a){ self::$defs=array_merge(self::$defs,$a);} static function clearCache(){ Tools::$log[]='media'; } }
class Smarty { public $vars=[]; function assign($a, $v = null){ $this->vars = array_merge($this->vars, is_array($a) ? $a : [$a => $v]); } }
class Ctl { public $php_self='cart'; public $js=[]; function addJS($p){ $this->js[]=$p; } function addCSS($p){ $this->js[]=$p; } function registerJavascript($i,$p,$o=[]){ $this->js[$i]=$p; } function registerStylesheet($i,$p,$o=[]){} }
class Link { function getModuleLink($m,$c,$p=[],$s=null){ return "https://shop.test/module/$m/$c"; } function getPageLink($p,$s=null,$l=null,$q=null){ return "http://127.0.0.1:8765/$p"; } function getCategoryLink($id,$a=null,$l=null){ return "http://127.0.0.1:8765/c$id"; } function getProductLink($id){ return "http://127.0.0.1:8765/p$id"; } function getCMSLink($i){ return "https://shop.test/pl/content/$i-x"; } }
class FakeLocale { function formatPrice($a,$iso){ return number_format($a,2,',',' ').' zł'; } }
class Translator { function trans($s,$p=[],$d=''){ return strtr($s,$p); } }
class Context { public $link,$controller,$language,$smarty,$shop,$cart,$currency,$cookie; static $c; static function getContext(){ if(!self::$c){ $c=new Context; $c->link=new Link; $c->controller=new Ctl; $c->language=(object)['id'=>1]; $c->shop=new SpcShopStub; $c->smarty=new Smarty; $c->currency=(object)['iso_code'=>'PLN']; self::$c=$c; } return self::$c; }
  function getCurrentLocale(){ return new FakeLocale; } function getTranslator(){ return new Translator; } }
class CMS { static function getCMSPages($l,$a=null,$b=true){ return []; } }
class Db { static function getInstance(){ return new Db; } function execute($q){ return true; } function Insert_ID(){ return 0; } function escape($s){ return addslashes((string) $s); } function getRow($q){ return false; } function executeS($q){ if(strpos($q,'category')!==false && strpos($q,'id_category FROM')!==false) return [['id_category'=>3],['id_category'=>4]]; if(strpos($q,'id_product FROM')!==false) return [['id_product'=>7]]; return []; } function getValue($q){ $c = Cart::$current; if (!$c) return 0; if (preg_match('/id_product = (\d+)\s+AND id_product_attribute = (\d+)\s+AND id_customization = (\d+)/', $q, $m)) { $k = "$m[1]-$m[2]-$m[3]"; return isset($c->lines[$k]) ? $c->lines[$k]['quantity'] : 0; } return array_sum(array_column($c->lines,'quantity')); } }
class HelperForm { public $module,$name_controller,$token,$currentIndex,$submit_action,$default_form_language,$fields_value=[],$title,$show_toolbar;
  function generateForm($f){ if(isset($f['form'])) $f=[$f]; foreach($f as $x) foreach($x['form']['input'] as $i) if(!array_key_exists($i['name'],$this->fields_value)) throw new Exception('no value for '.$i['name']); return "<form:{$this->submit_action}>"; } }
class AdminController { static $currentIndex = 'index.php?controller=AdminModules'; }
class Module { public $name,$version,$author,$tab,$need_instance,$bootstrap,$displayName,$description,$confirmUninstall,$ps_versions_compliancy,$module_key,$_errors=[]; static $hooks=[]; protected $context;
  function __construct(){ $this->context=Context::getContext(); } function l($s,$x=false){ return $s; } function install(){ return true; } function uninstall(){ return true; } function registerHook($h){ self::$hooks[$h]=1; return true; } function isRegisteredInHook($h){ return isset(self::$hooks[$h]); }
  function displayConfirmation($s){ return "[ok:$s]"; } function displayError($s){ return "[err:$s]"; } function displayWarning($s){ return "[warn:$s]"; } function getPathUri(){ return '/modules/speedpackcore/'; }
  function display($file,$tpl){ $v=$this->context->smarty->vars; if($tpl==='views/templates/admin/status.tpl') return '<status '.json_encode($v['spc_status']['rows'], JSON_UNESCAPED_UNICODE).' notes='.count($v['spc_status']['notes'] ?? []).'>'; if($tpl==='views/templates/admin/cache-actions.tpl') return '<actions autostart='.(int)$v['spc_actions']['autostart'].'>'; return "<tpl:$tpl>"; }
  static function isEnabled($m){ return $m==='speedpackcore'; } static function isInstalled($m){ return false; } }
abstract class Cache { abstract protected function _set($k,$v,$t=0); abstract protected function _get($k); abstract protected function _exists($k); abstract protected function _delete($k); abstract protected function _deleteMulti(array $k); abstract protected function _writeKeys(); abstract public function flush(); }
class CacheMemcached { static function getMemcachedServers(){ return []; } }
class PrestaShopAutoload { static function getInstance(){ return new self; } function generateIndex(){ $f=_PS_OVERRIDE_DIR_.'classes/cache/CacheRedis.php'; if(is_file($f) && !class_exists('CacheRedis', false)) require_once $f; } }
// --- the cart, for the quantity endpoint
class Cart { const ONLY_PRODUCTS=1; const ONLY_DISCOUNTS=2; const ONLY_SHIPPING=5; const BOTH=3; static $current; public $id=9, $id_customer=0, $lines=[]; public $stock=10, $min=1;
  function containsProduct($p,$a=0,$c=0){ $k="$p-$a-$c"; return isset($this->lines[$k]) ? ['quantity'=>$this->lines[$k]['quantity']] : false; }
  function updateQty($q,$p,$a=null,$c=false,$op='up'){ $k="$p-".(int)$a.'-'.(int)$c; $n=$this->lines[$k]['quantity'] + ($op==='up'?$q:-$q); if($n<$this->min) return -1; if($n>$this->stock) return false; $this->lines[$k]['quantity']=$n; return true; }
  function getCartRules(){ return []; } function getOrderTotal($t,$w){ $s=0; foreach($this->lines as $l) $s+=$l['quantity']*$l['price']; return $w===self::ONLY_SHIPPING?0:($w===self::ONLY_DISCOUNTS?0:$s); }
  function getProducts(){ $r=[]; foreach($this->lines as $k=>$l){ [$p,$a,$c]=explode('-',$k); $r[]=['id_product'=>$p,'id_product_attribute'=>$a,'id_customization'=>$c,'total'=>$l['quantity']*$l['price'],'total_wt'=>$l['quantity']*$l['price']]; } return $r; } }
class Validate { static function isLoadedObject($o){ return $o && $o->id; } }
class Product { public $minimal_quantity=1; static function getTaxCalculationMethod($c){ return 0; } }
class CartRule { static function autoRemoveFromCart($c){} static function autoAddToCart($c){} }
class ModuleFrontController { public $module, $context; function __construct(){ $this->context=Context::getContext(); $this->module=new SpeedPackCore; } function isTokenValid(){ return true; } }

require SPC_MODULE . '/speedpackcore.php';
$m = new SpeedPackCore();
ok($m->version === spc_version() && $m->install(), 'install ' . spc_version() . ' (the version of config.xml)');
ok(Configuration::get('SPC_CACHE_REDIS_PREFIX') && Configuration::get('SPC_IC_QTY') == 1, 'cache defaults and quantity switch written');
$page = $m->getContent();
echo '    ', preg_replace('/<status .*?>/', '<status>', $page), "\n"; ok(substr_count($page, '<form:') === 12 && strpos($page, '<form:submitSpcPageCache>') !== false && strpos($page, '<form:submitSpcOptimize>') !== false && strpos($page, '<actions') !== false, 'settings page: 12 forms incl. data cache, PrestaShop settings, Page cache, Optimize, Reorder, Behaviour and sharing, plus actions');
ok(strpos($page, '"Data cache in use":"Off"') !== false, 'status says the data cache is off');
ok(in_array('/modules/speedpackcore/views/js/admin.js', Context::getContext()->controller->js, true), 'warm-up script added to the page');

$redis = ['SPC_CACHE_BACKEND'=>'redis','SPC_CACHE_REDIS_HOST'=>'127.0.0.1','SPC_CACHE_REDIS_PORT'=>REDIS_PORT,'SPC_CACHE_REDIS_DB'=>2,'SPC_CACHE_REDIS_PREFIX'=>'shopA:','SPC_CACHE_MEMCACHED_HOST'=>'127.0.0.1','SPC_CACHE_MEMCACHED_PORT'=>11211];
Tools::$post = ['submitSpcCache'=>1] + $redis + ['SPC_CACHE_REDIS_PASSWORD'=>'wrong'];
$page = $m->getContent(); preg_match('/\[err:[^\]]*\]/', $page, $e); echo '    ', $e[0] ?? '-', "\n";
$p = include _PS_ROOT_DIR_ . '/app/config/parameters.php';
ok(isset($e[0]) && $p['parameters']['ps_cache_enable'] === false && !is_file(_PS_OVERRIDE_DIR_ . 'classes/cache/CacheRedis.php'), 'wrong password: refused, nothing written');

Tools::$post = ['submitSpcCache'=>1] + $redis + ['SPC_CACHE_REDIS_PASSWORD'=>REDIS_PASS];
$page = $m->getContent(); preg_match('/\[(ok|err):[^\]]*\]/', $page, $e); echo '    ', $e[0], "\n";
$p = include _PS_ROOT_DIR_ . '/app/config/parameters.php';
ok($p['parameters']['ps_cache_enable'] === true && $p['parameters']['ps_caching'] === 'CacheRedis' && $p['parameters']['database_host'] === '127.0.0.1', 'right password: parameters.php points at CacheRedis, other entries kept');
ok(is_file(_PS_ROOT_DIR_ . '/app/config/parameters.php.speedpackcore.bak'), 'original parameters.php kept as .speedpackcore.bak');
ok(CacheRedis::settings()['password'] === REDIS_PASS && CacheRedis::settings()['database'] === 2, 'Redis class written with the settings baked in');

$c = new CacheRedis();
ok($c->isConnected(), 'CacheRedis connects');
$c->set('objects_1', ['a' => 1]); $c->set('objects_2', 'x'); $c->set('lists_1', 'y');
ok($c->get('objects_1') === ['a' => 1] && $c->exists('lists_1'), 'set/get round trip (serialized)');
$c->delete('objects_*');
ok(!$c->exists('objects_1') && !$c->exists('objects_2') && $c->exists('lists_1'), 'wildcard delete removes only matching keys');
$raw = new Redis(); $raw->connect('127.0.0.1', REDIS_PORT); $raw->auth(REDIS_PASS); $raw->select(2); $raw->set('othershop:keep', '1');
$page = $m->getContent(); preg_match('/<status (.*?) notes/', $page, $s); echo '    status ', $s[1], "\n";
ok(strpos($s[1], '"Data cache in use":"Redis"') !== false && strpos($s[1], 'Data cache hit rate') !== false, 'status shows Redis with hit rate and memory');
Tools::$post = ['submitSpcFlush' => 1]; $page = $m->getContent();
ok($raw->get('othershop:keep') === '1' && !$raw->keys('shopA:*'), 'Empty the cache: this shop\'s keys gone, another prefix untouched');
ok(strpos($page, 'autostart=1') !== false, 'warm-up set to start on its own after emptying');

exec('cd ' . escapeshellarg(__DIR__) . ' && (python3 -m http.server 8765 >/dev/null 2>&1 & echo $! > /tmp/claude-0/-home-user-alhambrasklep-private/c6d440a8-09a5-54d3-a354-f38b583e3a2a/scratchpad/http.pid)'); usleep(600000);
ob_start(); $step = null; try { Tools::$post = ['spc_ajax' => 'warmup', 'offset' => 0]; $GLOBALS['exit_hook'] = true; } finally {}
$json = shell_exec('php -r ' . escapeshellarg('$_POST=1;') . ' 2>&1'); ob_end_clean();
$step = SpcWarmup::step(Context::getContext(), 0);
echo '    warm-up step ', json_encode($step), "\n";
ok($step['total'] === 4 && $step['offset'] === 4 && $step['finished'], 'warm-up visits home, 2 categories and 1 product');
exec('kill $(cat /tmp/claude-0/-home-user-alhambrasklep-private/c6d440a8-09a5-54d3-a354-f38b583e3a2a/scratchpad/http.pid)');

Tools::$post = ['submitSpcCache'=>1, 'SPC_CACHE_BACKEND' => 'off'] + $redis;
$m->getContent(); $p = include _PS_ROOT_DIR_ . '/app/config/parameters.php';
ok($p['parameters']['ps_cache_enable'] === false, 'switched off again');
Tools::$post = ['submitSpcCache'=>1] + $redis + ['SPC_CACHE_REDIS_PASSWORD'=>REDIS_PASS]; $m->getContent();
ok($m->uninstall() && !is_file(_PS_OVERRIDE_DIR_ . 'classes/cache/CacheRedis.php'), 'uninstall with Redis on: cache switched off and Redis class removed');
$p = include _PS_ROOT_DIR_ . '/app/config/parameters.php';
ok($p['parameters']['ps_cache_enable'] === false, 'parameters.php no longer names Redis');

// --- quantity endpoint
require SPC_MODULE . '/controllers/front/qty.php';
$cart = new Cart; $cart->lines = ['5-0-0' => ['quantity' => 2, 'price' => 18], '6-3-0' => ['quantity' => 1, 'price' => 24]]; Cart::$current = $cart; Context::getContext()->cart = $cart;
function qty($p, $a, $n) { Tools::$post = ['p' => $p, 'a' => $a, 'c' => 0, 'qty' => $n]; $c = new SpeedpackcoreQtyModuleFrontController; $r = new ReflectionMethod($c, 'setQuantity'); $r->setAccessible(true); return $r->invoke($c); }
$r = qty(5, 0, 7); echo '    ', json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
ok($r['ok'] && $r['quantity'] === 7 && $r['count'] === 8 && $r['line'] === '126,00 zł' && $r['totals']['total'] === '150,00 zł', '2 → 7: one request, line total and totals back');
$r = qty(5, 0, 3); ok($r['ok'] && $r['quantity'] === 3 && $r['count'] === 4, '7 → 3 goes down');
$r = qty(5, 0, 30); ok(!$r['ok'] && $r['quantity'] === 3 && strpos($r['error'], 'not that many') !== false, 'more than in stock: refused, quantity stays 3');
$cart->min = 2; $r = qty(5, 0, 1); $cart->min = 1;
ok(!$r['ok'] && $r['quantity'] === 3 && strpos($r['error'], 'minimum') !== false, 'below the minimum: refused with the minimum message');
ok(qty(99, 0, 2) === ['fallback' => true], 'a line not in the cart falls back to the shop');
echo "ALL OK\n";
