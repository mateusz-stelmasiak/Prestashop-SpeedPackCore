<?php
require __DIR__ . '/bootstrap.php';
// AsyncCart: install, where its script loads, standing aside for SpeedPack Core, settings, and the quantity endpoint (with Undo).
define('_PS_VERSION_', '1.7.8.0'); define('_PS_ROOT_DIR_', SPC_FAKEPS); define('_PS_OVERRIDE_DIR_', SPC_FAKEPS . '/override/');
define('_PS_MODULE_DIR_', SPC_ROOT . '/'); define('__PS_BASE_URI__', '/'); define('_DB_PREFIX_', 'ps_'); define('_COOKIE_KEY_', 'abc');
function pSQL($s){ return addslashes((string) $s); }
class Configuration { static $v = []; static function get($k){ return self::$v[$k] ?? false; } static function updateValue($k,$x){ self::$v[$k]=$x; return true; } static function deleteByName($k){ unset(self::$v[$k]); return true; } static function isCatalogMode(){ return false; } static function updateGlobalValue($k,$x){ return true; } }
class Shop { static function isFeatureActive(){ return false; } }
class Tools { static $post=[]; static $log=[]; static function strtolower($s){return strtolower($s);} static function substr($a,$b,$c=null){return substr($a,$b,$c);} static function strlen($s){return strlen($s);} static function strpos($a,$b){return strpos($a,$b);} static function strrpos($a,$b){return strrpos($a,$b);}
  static function isSubmit($k){ return isset(self::$post[$k]); } static function getValue($k,$d=false){ return self::$post[$k] ?? $d; } static function getAdminTokenLite($x){ return 'adm'; } static function passwdGen($n=8){ return substr(str_repeat(md5(mt_rand()),4),0,$n); } static function getToken($x){ return 't'; }
  static function clearSf2Cache(){ self::$log[]='sf2'; } static function clearSmartyCache(){ self::$log[]='smarty'; } static function generateHtaccess(){ self::$log[]='htaccess'; return true; } static function safeOutput($s){ return htmlspecialchars($s); } static function file_get_contents($u){ return false; } }
class Media { static $defs=[]; static function addJsDef($a){ self::$defs=array_merge(self::$defs,$a);} static function clearCache(){ Tools::$log[]='media'; } }
class Smarty { public $vars=[]; function assign($a, $v = null){ $this->vars = array_merge($this->vars, is_array($a) ? $a : [$a => $v]); } }
class Ctl { public $php_self='cart'; public $js=[]; function addJS($p){ $this->js[]=$p; } function addCSS($p){ $this->js[]=$p; } function registerJavascript($i,$p,$o=[]){ $this->js[$i]=$p; } function registerStylesheet($i,$p,$o=[]){} }
class Link { function getModuleLink($m,$c,$p=[],$s=null){ return "https://shop.test/module/$m/$c"; } function getPageLink($p,$s=null,$l=null,$q=null){ return "http://127.0.0.1:8765/$p"; } function getCategoryLink($id,$a=null,$l=null){ return "http://127.0.0.1:8765/c$id"; } function getProductLink($id){ return "http://127.0.0.1:8765/p$id"; } function getCMSLink($i){ return "https://shop.test/pl/content/$i-x"; } }
class FakeLocale { function formatPrice($a,$iso){ return number_format($a,2,',',' ').' zł'; } }
class Translator { function trans($s,$p=[],$d=''){ return strtr($s,$p); } }
class Context { public $link,$controller,$language,$smarty,$shop,$cart,$currency,$cookie; static $c; static function getContext(){ if(!self::$c){ $c=new Context; $c->link=new Link; $c->controller=new Ctl; $c->language=(object)['id'=>1]; $c->shop=(object)['id'=>1]; $c->smarty=new Smarty; $c->currency=(object)['iso_code'=>'PLN']; self::$c=$c; } return self::$c; }
  function getCurrentLocale(){ return new FakeLocale; } function getTranslator(){ return new Translator; } }
class CMS { static function getCMSPages($l,$a=null,$b=true){ return []; } }
class Db { static function getInstance(){ return new Db; } function escape($s){ return addslashes((string) $s); } function getRow($q){ return false; } function executeS($q){ if(strpos($q,'category')!==false && strpos($q,'id_category FROM')!==false) return [['id_category'=>3],['id_category'=>4]]; if(strpos($q,'id_product FROM')!==false) return [['id_product'=>7]]; return []; } function getValue($q){ $c = Cart::$current; if (!$c) return 0; if (preg_match('/id_product = (\d+)\s+AND id_product_attribute = (\d+)\s+AND id_customization = (\d+)/', $q, $m)) { $k = "$m[1]-$m[2]-$m[3]"; return isset($c->lines[$k]) ? $c->lines[$k]['quantity'] : 0; } return array_sum(array_column($c->lines,'quantity')); } }
class HelperForm { public $module,$name_controller,$token,$currentIndex,$submit_action,$default_form_language,$fields_value=[],$title,$show_toolbar;
  function generateForm($f){ if(isset($f['form'])) $f=[$f]; foreach($f as $x) foreach($x['form']['input'] as $i) if(!array_key_exists($i['name'],$this->fields_value)) throw new Exception('no value for '.$i['name']); return "<form:{$this->submit_action}>"; } }
class AdminController { static $currentIndex = 'index.php?controller=AdminModules'; }
class Module { static $on = []; public $name,$version,$author,$tab,$need_instance,$bootstrap,$displayName,$description,$confirmUninstall,$ps_versions_compliancy,$module_key,$_errors=[]; static $hooks=[]; protected $context;
  function __construct(){ $this->context=Context::getContext(); } function l($s,$x=false){ return $s; } function install(){ return true; } function uninstall(){ return true; } function registerHook($h){ self::$hooks[$h]=1; return true; } function isRegisteredInHook($h){ return isset(self::$hooks[$h]); }
  function displayConfirmation($s){ return "[ok:$s]"; } function displayError($s){ return "[err:$s]"; } function displayWarning($s){ return "[warn:$s]"; } function getPathUri(){ return '/modules/speedpackcore/'; }
  function display($file,$tpl){ if(!is_file(dirname($file).'/'.$tpl)) throw new Exception('no template '.$tpl); return '<tpl:' . $tpl . ' ' . json_encode($this->context->smarty->vars['asynccart']['rows'] ?? [], JSON_UNESCAPED_UNICODE) . '>'; }
  static function isEnabled($m){ return !empty(self::$on[$m]); } static function isInstalled($m){ return !empty(self::$on[$m]); } }
abstract class Cache { abstract protected function _set($k,$v,$t=0); abstract protected function _get($k); abstract protected function _exists($k); abstract protected function _delete($k); abstract protected function _deleteMulti(array $k); abstract protected function _writeKeys(); abstract public function flush(); }
class CacheMemcached { static function getMemcachedServers(){ return []; } }
class PrestaShopAutoload { static function getInstance(){ return new self; } function generateIndex(){ $f=_PS_OVERRIDE_DIR_.'classes/cache/CacheRedis.php'; if(is_file($f) && !class_exists('CacheRedis', false)) require_once $f; } }
// --- the cart, for the quantity endpoint
class Cart { const ONLY_PRODUCTS=1; const ONLY_DISCOUNTS=2; const ONLY_SHIPPING=5; const BOTH=3; static $current; public $id=9, $id_customer=0, $lines=[]; public $stock=10, $min=1;
  function containsProduct($p,$a=0,$c=0){ $k="$p-$a-$c"; return isset($this->lines[$k]) ? ['quantity'=>$this->lines[$k]['quantity']] : false; }
  function updateQty($q,$p,$a=null,$c=false,$op='up'){ $k="$p-".(int)$a.'-'.(int)$c; $now=isset($this->lines[$k])?$this->lines[$k]['quantity']:0; $n=$now + ($op==='up'?$q:-$q); if($n<$this->min) return -1; if($n>$this->stock) return false; if(!isset($this->lines[$k])) $this->lines[$k]=['price'=>$this->restorePrice]; $this->lines[$k]['quantity']=$n; return true; }
  public $restorePrice = 18; function deleteProduct($p,$a=0,$c=0){ $k="$p-".(int)$a.'-'.(int)$c; if(!isset($this->lines[$k])) return false; unset($this->lines[$k]); return true; }
  function getCartRules(){ return []; } function getOrderTotal($t,$w){ $s=0; foreach($this->lines as $l) $s+=$l['quantity']*$l['price']; return $w===self::ONLY_SHIPPING?0:($w===self::ONLY_DISCOUNTS?0:$s); }
  function getProducts(){ $r=[]; foreach($this->lines as $k=>$l){ [$p,$a,$c]=explode('-',$k); $r[]=['id_product'=>$p,'id_product_attribute'=>$a,'id_customization'=>$c,'total'=>$l['quantity']*$l['price'],'total_wt'=>$l['quantity']*$l['price']]; } return $r; } }
class Validate { static function isLoadedObject($o){ return $o && $o->id; } }
class Product { public $minimal_quantity=1; static function getTaxCalculationMethod($c){ return 0; } }
class CartRule { static function autoRemoveFromCart($c){} static function autoAddToCart($c){} }
class ModuleFrontController { public $module, $context; function __construct(){ $this->context=Context::getContext(); $this->module=new AsyncCart; } function isTokenValid(){ return true; } }

require SPC_ROOT . '/asynccart/asynccart.php';
$m = new AsyncCart();
ok($m->install() && Configuration::get('ASYNCCART_QTY') == 1 && (int) Configuration::get('ASYNCCART_DELAY') === 400 && isset(Module::$hooks['actionFrontControllerSetMedia']), 'install: settings written, hook registered');

// --- the cart page gets the script; other pages do not
$ctl = Context::getContext()->controller;
$ctl->php_self = 'category'; $ctl->js = []; Media::$defs = [];
$m->hookActionFrontControllerSetMedia();
ok(!isset(Media::$defs['asyncCart']) && !$ctl->js, 'not on a category page');
$ctl->php_self = 'cart';
$m->hookActionFrontControllerSetMedia();
ok(isset(Media::$defs['asyncCart']['qtyUrl'], $ctl->js['asynccart']) && Media::$defs['asyncCart']['delay'] === 400 && Media::$defs['asyncCart']['t']['undo'] === 'Undo', 'on the cart page: script and settings');

// --- SpeedPack Core's InstantCart on the cart page: AsyncCart stands aside
Module::$on = ['speedpackcore' => 1]; Configuration::$v['SPC_IC_ENABLED'] = 1; Configuration::$v['SPC_IC_QTY'] = 1;
$ctl->js = []; Media::$defs = [];
$m->hookActionFrontControllerSetMedia();
ok(!isset(Media::$defs['asyncCart']) && !$ctl->js, 'SpeedPack Core handles the cart: AsyncCart stays off');
$page = $m->getContent();
ok(strpos($page, '[warn:SpeedPack Core already makes the cart page instant') !== false && strpos($page, 'no, SpeedPack Core handles it') !== false, 'the settings page says why');
Configuration::$v['SPC_IC_QTY'] = 0; Configuration::$v['SPC_IC_REMOVE'] = 0;
$m->hookActionFrontControllerSetMedia();
ok(isset(Media::$defs['asyncCart']), 'SpeedPack Core without its cart options: AsyncCart works again');
Module::$on = [];

// --- settings
Tools::$post = ['submitAsyncCart' => 1, 'ASYNCCART_QTY' => 1, 'ASYNCCART_REMOVE' => 0, 'ASYNCCART_NOTIFY' => 1, 'ASYNCCART_DELAY' => 50];
$page = $m->getContent();
ok(strpos($page, '[err:') !== false && (int) Configuration::get('ASYNCCART_DELAY') === 400, 'a wait below 100 ms is refused, nothing saved');
Tools::$post = ['submitAsyncCart' => 1, 'ASYNCCART_QTY' => 1, 'ASYNCCART_REMOVE' => 0, 'ASYNCCART_NOTIFY' => 1, 'ASYNCCART_DELAY' => 600];
$page = $m->getContent();
ok((int) Configuration::get('ASYNCCART_DELAY') === 600 && (int) Configuration::get('ASYNCCART_REMOVE') === 0 && strpos($page, '<form:submitAsyncCart') !== false, 'settings saved');
$ctl->js = []; Media::$defs = [];
$m->hookActionFrontControllerSetMedia();
ok(Media::$defs['asyncCart']['removeUrl'] === '' && Media::$defs['asyncCart']['qtyUrl'] !== '', 'remove switched off: no remove endpoint handed to the page');

// --- the quantity endpoint
require SPC_ROOT . '/asynccart/controllers/front/qty.php';
$cart = new Cart; $cart->lines = ['5-0-0' => ['quantity' => 2, 'price' => 18], '6-3-0' => ['quantity' => 1, 'price' => 24]]; Cart::$current = $cart; Context::getContext()->cart = $cart;
function qty($p, $a, $n, $extra = []) { Tools::$post = ['p' => $p, 'a' => $a, 'c' => 0, 'qty' => $n] + $extra; $c = new AsynccartQtyModuleFrontController; $r = new ReflectionMethod($c, 'setQuantity'); $r->setAccessible(true); return $r->invoke($c); }
$r = qty(5, 0, 7);
echo '    ', json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
ok($r['ok'] && $r['quantity'] === 7 && $r['count'] === 8 && $r['line'] === '126,00 zł' && $r['totals']['total'] === '150,00 zł', '2 → 7: one request, line and totals back');
$r = qty(5, 0, 3); ok($r['ok'] && $r['quantity'] === 3, '7 → 3 goes down');
$r = qty(5, 0, 30); ok(!$r['ok'] && $r['quantity'] === 3 && strpos($r['error'], 'not that many') !== false, 'more than in stock: refused, stays 3');
$cart->min = 2; $r = qty(5, 0, 1); $cart->min = 1;
ok(!$r['ok'] && strpos($r['error'], 'minimum') !== false, 'below the minimum: refused with the minimum');
ok(qty(99, 0, 2) === ['fallback' => true], 'a line not in the cart falls back to the shop');
ok(qty(5, 0, 0) === ['fallback' => true] && qty(5, 0, 100001) === ['fallback' => true], 'nonsense quantities fall back');
unset($cart->lines['6-3-0']);
$r = qty(6, 3, 1, ['restore' => 1]);
ok($r['ok'] && $r['quantity'] === 1 && isset($cart->lines['6-3-0']), 'Undo: a removed line comes back with restore=1');
Tools::$post = ['p' => 7, 'a' => 0, 'c' => 4, 'qty' => 1, 'restore' => 1]; $c = new AsynccartQtyModuleFrontController; $rm = new ReflectionMethod($c, 'setQuantity'); $rm->setAccessible(true);
ok($rm->invoke($c) === ['fallback' => true], 'Undo of a customised line is not attempted (it cannot be rebuilt from here)');

ok($m->uninstall() && Configuration::get('ASYNCCART_QTY') === false, 'uninstall removes the settings');
echo "ALL OK\n";
