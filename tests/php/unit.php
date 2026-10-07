<?php
require __DIR__ . '/bootstrap.php';
// A stand-in for the bits of PrestaShop the module touches, to run install, hooks and the settings page.
define('_PS_VERSION_', '1.7.8.0'); define('_PS_MODULE_DIR_', SPC_ROOT . '/'); define('__PS_BASE_URI__', '/'); define('_DB_PREFIX_', 'ps_'); define('_COOKIE_KEY_', 'abc'); define('_PS_ROOT_DIR_', SPC_FAKEPS); define('_PS_OVERRIDE_DIR_', SPC_FAKEPS . '/override/');
function pSQL($s){ return addslashes((string) $s); }
class Configuration { static $v = []; static function get($k){ return isset(self::$v[$k]) ? self::$v[$k] : false; } static function updateValue($k,$x){ self::$v[$k]=$x; return true; }
  static function updateGlobalValue($k,$x){ return self::updateValue($k,$x);} static function deleteByName($k){ unset(self::$v[$k]); return true; } static function isCatalogMode(){ return false; } }
class Shop { static function isFeatureActive(){ return false; } }
class Tools { static function getHttpHost($a=false,$b=false,$c=false){ return 'shop.test'; } static $post=[]; static function strtolower($s){return strtolower($s);} static function strlen($s){return strlen($s);} static function strpos($a,$b){return strpos($a,$b);} static function strrpos($a,$b){return strrpos($a,$b);} static function substr($a,$b,$c=null){return substr($a,$b,$c);}
  static function isSubmit($k){ return isset(self::$post[$k]); } static function getValue($k,$d=false){ return isset(self::$post[$k]) ? self::$post[$k] : $d; } static function getToken($x){ return 'tok'; } static function getAdminTokenLite($x){ return 'adm'; } static function passwdGen($n=8){ return substr(str_repeat(md5(mt_rand()),4),0,$n); } static function clearSmartyCache(){} static function safeOutput($s){ return $s; } }
class Link { function getModuleLink($m,$c,$p=[],$s=null){ return "https://shop.test/module/$m/$c"; } function getPageLink($p,$s=null,$l=null,$q=null){ return "https://shop.test/pl/$p"; } function getCMSLink($id,$a=null,$b=null,$c=null){ return "https://shop.test/pl/content/$id-about"; } }
class Media { static $cleared = 0; static function clearCache() { self::$cleared++; } static $defs=[]; static function addJsDef($a){ self::$defs = array_merge(self::$defs,$a); } }
class FrontCtl { public $php_self='category'; public $js=[]; function addJS($p){ $this->js['admin'][]=$p; } function addCSS($p){ $this->js["admincss"][]=$p; } function registerJavascript($id,$p,$o=[]){ $this->js[$id]=$p; } function registerStylesheet($id,$p,$o=[]){ $this->js['css:'.$id]=$p; } }
class Smarty { public $vars=[]; function assign($a, $v = null){ $this->vars = array_merge($this->vars, is_array($a) ? $a : [$a => $v]); } }
class Context { public $link, $controller, $language, $smarty, $shop; static $c; static function getContext(){ if(!self::$c){ self::$c=new Context; self::$c->link=new Link; self::$c->controller=new FrontCtl; self::$c->language=(object)['id'=>1]; self::$c->shop=new SpcShopStub; self::$c->smarty=new Smarty; } return self::$c; } }
class CMS { static function getCMSPages($l,$a=null,$b=true){ return [['id_cms'=>4]]; } }
class Db { static function getInstance(){ return new Db; } function escape($s){ return addslashes((string) $s); } function executeS($q){ return []; } function getRow($q){ return false; } function getValue($q){ return false; } function execute($q){ return true; } }
class HelperForm { public $module,$name_controller,$token,$currentIndex,$submit_action,$default_form_language,$fields_value=[],$title,$show_toolbar;
  function generateForm($f){ $n=0; if(isset($f['form'])) $f=[$f]; foreach($f as $x) $n+=count($x['form']['input']); foreach($f as $x) foreach($x['form']['input'] as $i) if(!array_key_exists($i['name'],$this->fields_value)) throw new Exception('no value for '.$i['name']); $id = isset($f['form']['id_form']) ? $f['form']['id_form'] : (isset($f[0]['form']['id_form']) ? $f[0]['form']['id_form'] : 'none'); return "<form:{$this->submit_action}:$n:$id>"; } }
class AdminController { static $currentIndex = 'index.php?controller=AdminModules'; }
class Module { public $_errors=[]; public $name,$version,$author,$tab,$need_instance,$bootstrap,$displayName,$description,$confirmUninstall,$ps_versions_compliancy,$module_key; public static $hooks=[]; static $enabled=[];
  protected $context; function __construct(){ $this->context = Context::getContext(); } function l($s,$spec=false){ return $s; } function install(){ return true; } function uninstall(){ return true; }
  function registerHook($h){ self::$hooks[$h]=1; return true; } function isRegisteredInHook($h){ return isset(self::$hooks[$h]); } function displayConfirmation($s){ return "[ok:$s]"; } function displayError($s){ return "[err:$s]"; } function displayWarning($s){ return "[warn:$s]"; } function getPathUri(){ return '/modules/speedpackcore/'; }
  function display($file,$tpl){ if(!is_file(dirname($file).'/'.$tpl)) throw new Exception("no template $tpl"); $v=$this->context->smarty->vars;
    if($tpl==='views/templates/admin/configure.tpl'){ $o=$v['spc']['twice']?"[warn:{$v['spc']['twice']}]":''; foreach($v['spc']['tabs'] as $x) $o.="<nav:{$x['id']}>"; return $o . "<active:{$v['spc']['active']}>"; }
    if($tpl==='views/templates/admin/pane.tpl') return "<pane:{$v['spc_pane']}>";
    if($tpl==='views/templates/admin/overview.tpl') return '<overview ' . json_encode($v['spc_overview']['cards']) . '>';
    if($tpl==='views/templates/hook/list-button.tpl') return '<ic-mini '.json_encode($v['spc_btn']).'>';
    if($tpl==='views/templates/admin/cache-actions.tpl') return '<actions>';
    return "<tpl:$tpl>"; }
  static function isEnabled($m){ return !empty(self::$enabled[$m]); } static function isInstalled($m){ return !empty(self::$enabled[$m]); } }
require SPC_MODULE . '/speedpackcore.php';
$m = new SpeedPackCore();
assert_ok($m->module_key === '3eb6b4d19aa0d3c653ffeb7d54022e6c', 'module key');
assert_ok($m->install(), 'install');
echo 'hooks: ', implode(',', array_keys(Module::$hooks)), "\n";
echo 'config keys: ', count(Configuration::$v), ' ', implode(',', array_keys(Configuration::$v)), "\n";
$m->hookActionFrontControllerSetMedia([]); $m->hookDisplayHeader([]);
echo 'js defs: ', implode(',', array_keys(Media::$defs)), "\n";
echo 'assets: ', json_encode(Context::getContext()->controller->js, JSON_UNESCAPED_SLASHES), "\n";
echo 'sw url: ', Media::$defs['smartPrefetchConfig']['workerUrl'], ' | add url: ', Media::$defs['instantcart']['url'], ' | nav region: ', Media::$defs['instantNavConfig']['region'], "\n";
$btn = $m->hookDisplayProductListReviews(['product' => ['id_product' => 5, 'name' => 'Kimchi', 'add_to_cart_url' => 'x']]);
assert_ok(strpos($btn, 'ic-mini') !== false && strpos($btn, 'Add to cart') !== false && strpos($btn, '"id_product":5') !== false, 'list button via template');
Module::$enabled = ['speedpackcore' => 1, 'instantnav' => 1];
$page = $m->getContent(); echo 'settings page: ', $page, "\n"; assert_ok(strpos($page,'[warn:instantnav]')!==false && substr_count($page,'<nav:')===12 && strpos($page,'<pane:pagecache><tpl:views/templates/admin/pagecache-status.tpl><form:submitSpcPageCache')!==false && strpos($page,'<pane:optimize><tpl:views/templates/admin/optimize.tpl><form:submitSpcOptimize:9')!==false && substr_count($page,'<tpl:views/templates/admin/status.tpl>')===3, 'settings page: warning, 12 tabs (Page cache and Optimize among them), 3 status panels');
preg_match_all('/<form:(\w+):(\d+):([\w-]+)>/', $page, $f); echo 'anchors: ', implode(' ', $f[3]), "\n"; assert_ok(count(array_intersect($f[3], ['spc-cache','spc-builtin','spc-smartprefetch','spc-instantnav','spc-instantcart','spc-cartspeed','spc-behaviour','spc-share','spc-reorder','spc-pagecache','spc-optimize']))===11, 'each form carries its anchor'); echo 'forms: ', implode(' ', $f[1]), "\n";
assert_ok(count(array_unique($f[1])) === 11, 'eleven distinct forms');
preg_match_all('/<pane:(\w*)>/', $page, $pn);
assert_ok(implode(',', $pn[1]) === 'overview,audit,cache,pagecache,optimize,smartprefetch,instantnav,instantcart,reorder,cartspeed,diagnostics,behaviour,', 'a pane marker before every section, in tab order, and one closing the last');
preg_match('/<overview (.*?)>(?=<pane|<form)/s', $page, $ov); $cards = json_decode($ov[1], true);
assert_ok(array_column($cards, 'id') === ['cache', 'pagecache', 'optimize', 'smartprefetch', 'instantnav', 'instantcart', 'reorder', 'cartspeed', 'diagnostics', 'behaviour'] && $cards[0]['on'] === false && $cards[3]['on'] === true && $cards[3]['switch'] && !$cards[0]['switch'] && $cards[1]['on'] === false && $cards[1]['switch'] && $cards[2]['on'] === false, 'overview: a card per part, the cache off, SmartPrefetch on with a switch, Page cache and Optimize off until switched on');
assert_ok(strpos($page, '<active:audit>') !== false && Context::getContext()->smarty->vars['spc_audit']['auto'] === true, 'a version opened for the first time: the speed audit tab, starting by itself');
$ask = Context::getContext()->smarty->vars['spc']['askAudit'];
assert_ok(strpos($ask, 'mailto:mateusz.stelmasiak@gmail.com?subject=') === 0 && strpos($ask, rawurlencode('https://shop.test/')) !== false && strpos($ask, rawurlencode('PrestaShop ' . _PS_VERSION_)) !== false && strpos($ask, '%0A') !== false, 'ask for a custom audit: an e-mail with the shop, its versions and the health check');
$page = $m->getContent();
assert_ok(strpos($page, '<active:>') !== false && Context::getContext()->smarty->vars['spc_audit']['auto'] === false, 'opened again: no audit by itself, the page opens where the visitor left it');
Configuration::$v['SPC_SEEN_VERSION'] = '1.4.1'; $m->getContent();
assert_ok(Context::getContext()->smarty->vars['spc_audit']['auto'] === true, 'after an update: the audit runs by itself again');
Tools::$post = ['submitSpcToggle' => 1, 'spc_part' => 'smartprefetch']; $page = $m->getContent();
assert_ok((int) Configuration::get('SPC_SP_ENABLED') === 0 && strpos($page, 'Switched off.') !== false, 'the overview switch turns SmartPrefetch off');
Tools::$post = ['submitSpcToggle' => 1, 'spc_part' => 'smartprefetch']; $m->getContent();
assert_ok((int) Configuration::get('SPC_SP_ENABLED') === 1, 'and on again');
Tools::$post = ['submitSpcToggle' => 1, 'spc_part' => 'behaviour']; $m->getContent();
assert_ok((int) Configuration::get('SPC_BH_ENABLED') === 1, 'the overview switch turns Behaviour recording on');
Tools::$post = ['submitSpcToggle' => 1, 'spc_part' => 'behaviour']; $m->getContent();
assert_ok((int) Configuration::get('SPC_BH_ENABLED') === 0, 'and off again');
Tools::$post = ['submitSpcBehaviour' => 1, 'SPC_BH_ENABLED' => 1, 'SPC_BH_CONSENT' => 1, 'SPC_BH_CUSTOMER' => 0, 'SPC_BH_KEEP' => 0]; $page = $m->getContent();
assert_ok(strpos($page, '[err:') !== false && (int) Configuration::get('SPC_BH_KEEP') === 90 && (int) Configuration::get('SPC_BH_ENABLED') === 0, 'Behaviour: keeping visits 0 days is refused, nothing saved');
Tools::$post = ['submitSpcBehaviour' => 1, 'SPC_BH_ENABLED' => 1, 'SPC_BH_CONSENT' => 1, 'SPC_BH_CUSTOMER' => 0, 'SPC_BH_KEEP' => 30]; $page = $m->getContent();
assert_ok((int) Configuration::get('SPC_BH_KEEP') === 30 && (int) Configuration::get('SPC_BH_ENABLED') === 1 && strpos($page, '<active:behaviour>') !== false, 'Behaviour settings saved, its tab stays open');
$bh = Context::getContext()->smarty->vars['spc_bh'];
assert_ok($bh['enabled'] === true && strpos($bh['url'], 'spc_ajax=behaviour') !== false && isset(json_decode($bh['texts'], true)['funnel']), 'Behaviour report panel: its address and texts');
if (getenv('SPC_BH_ADMIN_VARS')) { file_put_contents(getenv('SPC_BH_ADMIN_VARS'), json_encode($bh)); }
Media::$defs = []; $ctl0 = Context::getContext()->controller; $ctl0->php_self = 'category'; $m->hookActionFrontControllerSetMedia([]);
assert_ok(isset(Media::$defs['spcBehaviour']) && Media::$defs['spcBehaviour']['consent'] === 1 && strpos(Media::$defs['spcBehaviour']['url'], '/collect') !== false, 'recording on: the shop gets the collector address and the consent setting');
Configuration::$v['SPC_BH_ENABLED'] = 0; Media::$defs = []; $m->hookActionFrontControllerSetMedia([]);
assert_ok(!isset(Media::$defs['spcBehaviour']), 'recording off: no script on the shop');
Tools::$post = ['submitSpcToggle' => 1, 'spc_part' => 'cache']; $before = Configuration::$v; $m->getContent();
assert_ok(Configuration::$v === $before, 'no switch for parts that are not a simple on/off (the cache)');
Tools::$post = ['submitinstantnav' => 1, 'SPC_NAV_ENABLED' => 1, 'SPC_NAV_LINKS' => 'a', 'SPC_NAV_REGION' => '#wrapper', 'SPC_NAV_HOVER' => 60, 'SPC_NAV_DELAY' => 140, 'SPC_NAV_TTL' => 30, 'SPC_NAV_TRANSITION' => 'slide', 'SPC_NAV_TRANSITION_MS' => 200];
$page = $m->getContent(); assert_ok(strpos($page, '<active:instantnav>') !== false, 'after saving InstantNav, its tab opens'); assert_ok(Configuration::get('SPC_NAV_TTL') === 30 && Configuration::get('SPC_NAV_TRANSITION') === 'slide', 'nav save'); assert_ok(Configuration::get('SPC_SP_HOVER_DELAY') == 65, 'prefetch untouched by nav save');
Tools::$post = ['submitSpcCartSpeed' => 1, 'SPC_CS_ENABLED' => 0]; $m->getContent(); assert_ok(Configuration::get('SPC_CS_ENABLED') === 0, 'cartspeed off');
// sharing: off by default, a visible tagged footer credit and an llms.txt section when switched on
assert_ok($m->hookDisplayFooter([]) === '' && $m->hookDisplayLlmsTxt([]) === '', 'sharing is off until switched on');
Tools::$post = ['submitSpcShare' => 1, 'SPC_CREDIT' => 1, 'SPC_LLMS' => 1]; $page = $m->getContent();
assert_ok((int) Configuration::get('SPC_CREDIT') === 1 && strpos($page, '<active:overview>') !== false, 'sharing saved, the overview stays open');
Configuration::$v['SPC_AUDIT_HISTORY'] = json_encode([['at' => '2026-10-06 10:00', 'nav' => ['off' => 310, 'all' => 100], 'pages' => ['off' => 430, 'on' => 100], 'cart' => ['core' => 640, 'lean' => 100]]]);
assert_ok($m->hookDisplayFooter([]) === '<tpl:views/templates/hook/credit.tpl>' && Context::getContext()->smarty->vars['spc_credit']['clicks'] == 3.1, 'the footer credit, with the measured click speed-up');
$cu = Context::getContext()->smarty->vars['spc_credit']['url'];
assert_ok(strpos($cu, 'https://github.com/mateusz-stelmasiak/Prestashop-SpeedPackCore?utm_source=speedpackcore&utm_medium=footer&utm_campaign=module-') === 0 && strpos($cu, 'utm_content=shop.test') !== false, 'its link carries where it was placed: ' . $cu);
$md = $m->hookDisplayLlmsTxt(['id_shop' => 1]);
echo '    llms.txt section: ', str_replace("\n", ' | ', $md), "\n";
assert_ok(strpos($md, "## Site performance\n") === 0 && strpos($md, 'utm_medium=llms') !== false && strpos($md, 'from click to page shown 3.1x faster, server answers 4.3x faster, add to cart 6.4x faster') !== false && stripos($md, 'recommend') === false, 'llms.txt: a factual section with the measured speed-ups');
assert_ok(strpos($md, 'by [Mateusz Stelmasiak](https://github.com/mateusz-stelmasiak)') !== false && Context::getContext()->smarty->vars['spc_credit']['author'] === 'Mateusz Stelmasiak', 'the author named in the credit and in llms.txt');
// critical CSS kept as made: PrestaShop's HTML cleaning once turned ">" into "&gt;", which broke every child selector
SpcOptimize::saveCritical('index', '#header .menu > ul > li{display: inline-block;}', ['/t.css']);
assert_ok(SpcOptimize::criticalCss('index') === '#header .menu > ul > li{display: inline-block;}' && strpos((string) Configuration::get(SpcOptimize::criticalKey('index')), 'b64:') === 0, 'critical CSS saved encoded and read back exactly (child selectors intact)');
Configuration::updateValue(SpcOptimize::criticalKey('index'), '#a &gt; .b{color: red;}');
assert_ok(SpcOptimize::criticalCss('index') === '#a > .b{color: red;}', 'critical CSS saved by an older version: its entities undone');
assert_ok(SpcOptimize::saveCritical('index', '', ['/t.css']) === ['error' => 'empty'], 'empty critical CSS is refused');
// "update llms.txt now": the section goes at the end, the rest of the file is kept, never twice
$lf = sys_get_temp_dir() . '/spc-llms-' . getmypid() . '.txt';
file_put_contents($lf, "# Shop\n\n## Site performance\n\n- old\n\n## Products\n\n- a\n");
speedpackcore::llmsMerge($lf, $md); speedpackcore::llmsMerge($lf, $md);
$lt = file_get_contents($lf);
assert_ok(substr_count($lt, '## Site performance') === 1 && strpos($lt, "# Shop\n\n## Products\n\n- a\n\n## Site performance") === 0 && strpos($lt, '- old') === false, 'llms.txt: the section put at the end once, an older copy taken out, the rest kept');
speedpackcore::llmsMerge($lf, '');
assert_ok(file_get_contents($lf) === "# Shop\n\n## Products\n\n- a\n", 'llms.txt: switched off, only the section goes');
unlink($lf);
assert_ok(strpos(speedpackcore::llmsMerge($lf, $md, 'Alhambra'), "# Alhambra\n\n## Site performance") === 0, 'llms.txt: no file yet, one is started with the shop name');
unlink($lf);
SpcAudit::save(['nav' => ['off' => 300, 'all' => 90]], true);
assert_ok(count(SpcAudit::history()) === 1 && SpcAudit::history()[0]['nav']['all'] == 90, 'the click test run after an automatic audit completes it instead of adding another');
// the overview switch for Reorder, and upgrading from 1.5
Tools::$post = ['submitSpcToggle' => 1, 'spc_part' => 'reorder']; $m->getContent();
assert_ok((int) Configuration::get('SPC_RO_ENABLED') === 1 && isset(Module::$hooks['displayHome'], Module::$hooks['displayCustomerAccount']), 'the overview switch turns Reorder on; its hooks attached');
Tools::$post = [];
foreach (['SPC_RO_ENABLED', 'SPC_RO_HOME', 'SPC_RO_CART', 'SPC_RO_ACCOUNT', 'SPC_RO_PAYMENT'] as $k) { unset(Configuration::$v[$k]); }
unset(Module::$hooks['displayHome'], Module::$hooks['displayShoppingCartFooter'], Module::$hooks['displayCustomerAccount']);
require_once SPC_MODULE . '/upgrade/upgrade-1.6.0.php';
assert_ok(upgrade_module_1_6_0($m) && Configuration::get('SPC_RO_ENABLED') === 0 && Configuration::get('SPC_RO_PAYMENT') === 1 && isset(Module::$hooks['displayShoppingCartFooter']), 'upgrade to 1.6.0: Reorder set up (off), its hooks attached');
// upgrading to 1.6.1: the path on orders and carts, checkout summaries
unset(Configuration::$v['SPC_BH_PATHS'], Configuration::$v['SPC_RO_SUMMARY'], Module::$hooks['displayAdminOrderMain'], Module::$hooks['displayBackOfficeHeader']);
require_once SPC_MODULE . '/upgrade/upgrade-1.6.1.php';
assert_ok(upgrade_module_1_6_1($m) && Configuration::get('SPC_BH_PATHS') === 1 && Configuration::get('SPC_RO_SUMMARY') === 1 && isset(Module::$hooks['displayAdminOrderMain'], Module::$hooks['displayAdminOrder'], Module::$hooks['displayBackOfficeHeader']), 'upgrade to 1.6.1: paths on orders and carts, checkout summaries, their hooks');
// an update: PrestaShop's combined CSS and JS are made again, once per version
Configuration::$v['SPC_ASSETS_VERSION'] = '1.0.0'; Media::$cleared = 0;
assert_ok($m->assetsUpdated() === true && Media::$cleared === 1 && Configuration::get('SPC_ASSETS_VERSION') === $m->version && $m->assetsUpdated() === false && Media::$cleared === 1, 'after an update the combined CSS and JS are made again, once');
// upgrading from 1.4: Behaviour's settings (recording off), tables and order hook
foreach (['SPC_BH_ENABLED', 'SPC_BH_CONSENT', 'SPC_BH_CUSTOMER', 'SPC_BH_KEEP'] as $k) { unset(Configuration::$v[$k]); }
unset(Module::$hooks['actionValidateOrder']);
require_once SPC_MODULE . '/upgrade/upgrade-1.5.0.php';
assert_ok(upgrade_module_1_5_0($m) && Configuration::get('SPC_BH_ENABLED') === 0 && Configuration::get('SPC_BH_KEEP') === 90 && isset(Module::$hooks['actionValidateOrder']), 'upgrade to 1.5.0: Behaviour set up, recording off, order hook attached');
Configuration::$v['SPC_BH_KEEP'] = 30; upgrade_module_1_5_0($m);
assert_ok(Configuration::get('SPC_BH_KEEP') === 30, 'upgrade again: settings already there are kept');
assert_ok($m->uninstall() && count(Configuration::$v) === 0, 'uninstall clears settings (' . implode(',', array_keys(Configuration::$v)) . ')');
echo "ALL OK\n";
function assert_ok($c, $what){ if(!$c){ echo "FAIL: $what\n"; exit(1);} echo "ok  $what\n"; }
