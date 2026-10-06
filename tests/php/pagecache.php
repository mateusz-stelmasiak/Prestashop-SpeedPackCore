<?php
require __DIR__ . '/bootstrap.php';
// The page cache against a real MariaDB (its index table) and real files: which requests may use
// it, what its key is made of, storing and reading a page, expiry, and clearing what a change
// makes stale (a product, its categories, the home page and the listings; everything for a
// category). Then Optimize's pictures and server headers on a throw-away shop folder.
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'pct_');
define('_PS_CACHE_DIR_', SPC_TMP . '/cache/');
define('_PS_ROOT_DIR_', SPC_TMP . '/root');

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
class Configuration { static $v = []; static function get($k) { return isset(self::$v[$k]) ? self::$v[$k] : false; } static function updateValue($k, $x) { self::$v[$k] = $x; return true; } static function deleteByName($k) { unset(self::$v[$k]); return true; } }
class Tools
{
    static $get = [];
    static function getValue($k, $d = false) { return isset(self::$get[$k]) ? self::$get[$k] : $d; }
    static function strtolower($s) { return mb_strtolower((string) $s); }
}
class Validate { static function isLoadedObject($o) { return is_object($o) && !empty($o->id); } }
class Module {}
class SpcAudit { static $parts = null; static function parts() { return self::$parts; } static function off($p) { return self::$parts !== null && !in_array($p, self::$parts, true); } }
class SpcCartAnswer { static function count($cart) { return $cart->n; } }
require SPC_MODULE . '/classes/SpcFeature.php';
require SPC_MODULE . '/classes/SpcImages.php';
require SPC_MODULE . '/classes/SpcHtml.php';
require SPC_MODULE . '/classes/SpcOptimize.php';
require SPC_MODULE . '/classes/SpcPageCache.php';

$db = Db::getInstance();
foreach (['spc_pagecache', 'category_product', 'product'] as $t) {
    $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $t . '`');
}
$db->execute('CREATE TABLE `' . _DB_PREFIX_ . 'category_product` (id_category INT, id_product INT)');
$db->execute('CREATE TABLE `' . _DB_PREFIX_ . 'product` (id_product INT, id_manufacturer INT)');
$db->execute('INSERT INTO `' . _DB_PREFIX_ . 'category_product` VALUES (3, 7), (4, 7), (5, 8)');
$db->execute('INSERT INTO `' . _DB_PREFIX_ . 'product` VALUES (7, 2), (8, 0)');
ok(SpcPageCache::installTable(), 'the index table is made');
Configuration::$v = [SpcPageCache::K_ENABLED => 1, SpcPageCache::K_TTL => 12, SpcPageCache::K_PAGES => implode(',', SpcPageCache::PAGES), SpcPageCache::K_MOBILE => 1, 'PS_SHOP_ENABLE' => 1];

// --- which requests may use it
$req = function (array $over = []) {
    return array_replace_recursive([
        'method' => 'GET', 'scheme' => 'https', 'host' => 'Shop.test', 'uri' => '/pl/7-kimchi.html', 'ajax' => false,
        'cookies' => ['id_customer' => null, 'id_cart' => null, 'logged' => null, 'viewed' => null, 'id_lang' => 1, 'id_currency' => 1, 'iso_code_country' => 'PL'],
        'raw' => [], 'viewed' => false, 'shop' => 1, 'mobile' => 0, 'images' => '',
    ], $over);
};
$k = SpcPageCache::key('product', $req());
ok(is_string($k) && strlen($k) === 40, 'a product page for a visitor who is not signed in has a key');
$no = function ($controller, $r, $why) {
    return SpcPageCache::key($controller, $r) === null && SpcPageCache::$why === $why;
};
ok($no('order', $req(), 'page') && $no('cart', $req(), 'page') && $no('search', $req(), 'page'), 'the checkout, the cart and the search are never kept');
ok($no('product', $req(['method' => 'POST']), 'method'), 'a POST is never kept');
ok($no('product', $req(['ajax' => true]), 'ajax'), 'an AJAX request is never kept');
ok($no('product', $req(['cookies' => ['id_customer' => 5]]), 'visitor') && $no('product', $req(['cookies' => ['id_cart' => 9]]), 'visitor'), 'a signed-in customer or a cart: built live');
ok($no('product', $req(['cookies' => ['viewed' => '3,4'], 'viewed' => true]), 'viewed') && SpcPageCache::key('product', $req(['cookies' => ['viewed' => '3,4']])) === $k, 'viewed products only matter when that module is on');
ok($no('product', $req(['uri' => '/pl/7-kimchi.html?preview=1']), 'param') && $no('product', $req(['uri' => '/x?spc_nocache=1']), 'param'), 'a preview or "no cache" in the address: built live');
ok(SpcPageCache::key('product', $req(['uri' => '/pl/7-kimchi.html?utm_source=fb&gclid=9'])) === $k, 'campaign tags do not make another page');
ok(SpcPageCache::key('product', $req(['host' => 'shop.test'])) === $k, 'the host in any case is the same page');
ok(SpcPageCache::key('category', $req(['uri' => '/pl/3-kiszonki?page=2'])) !== SpcPageCache::key('category', $req(['uri' => '/pl/3-kiszonki'])), 'page 2 of a category is another page');
ok(SpcPageCache::key('category', $req(['uri' => '/pl/3-kiszonki?page=2&order=x'])) === SpcPageCache::key('category', $req(['uri' => '/pl/3-kiszonki?order=x&page=2'])), 'the order of parameters does not matter');
$differs = 0;
foreach ([['cookies' => ['id_lang' => 2]], ['cookies' => ['id_currency' => 2]], ['cookies' => ['iso_code_country' => 'DE']], ['mobile' => 1], ['images' => 'webp'], ['shop' => 2]] as $o) {
    $differs += SpcPageCache::key('product', $req($o)) !== $k ? 1 : 0;
}
ok($differs === 6, 'language, currency, country, phone, picture format and shop each make another page');
Configuration::$v[SpcPageCache::K_MOBILE] = 0;
ok(SpcPageCache::key('product', $req(['mobile' => 1])) === SpcPageCache::key('product', $req()), 'one page for phones and computers when the theme shows the same');
Configuration::$v[SpcPageCache::K_MOBILE] = 1;
Configuration::$v[SpcPageCache::K_PAGES] = 'index,category';
ok($no('product', $req(), 'page'), 'only the kinds of page switched on are kept');
Configuration::$v[SpcPageCache::K_PAGES] = implode(',', SpcPageCache::PAGES);

// --- storing, reading, expiry
$html = '<!doctype html><html><head><title>Kimchi</title></head><body>' . str_repeat('<p>kapusta</p>', 60) . '</body></html>';
$t0 = 1760000000;
ok(SpcPageCache::write($k, $html, ['controller' => 'product', 'id_object' => 7, 'shop' => 1, 'url' => '/pl/7-kimchi.html', 'created' => $t0, 'expires' => $t0 + 3600]), 'a page is written');
$page = SpcPageCache::read($k, $t0 + 10);
ok($page !== null && gzdecode($page[1]) === $html && $page[0]['created'] === $t0, 'and read back as it was (gzipped)');
ok(SpcPageCache::read($k, $t0 + 3601) === null, 'not after it expired');
$keys = [];
foreach ([['category', 3, '/pl/3-kiszonki'], ['category', 5, '/pl/5-soki'], ['index', 0, '/pl/'], ['cms', 4, '/pl/content/4-o-nas'], ['product', 8, '/pl/8-sok.html'], ['manufacturer', 2, '/pl/brand/2-alhambra']] as $p) {
    $kk = SpcPageCache::key($p[0], $req(['uri' => $p[2]]));
    SpcPageCache::write($kk, $html, ['controller' => $p[0], 'id_object' => $p[1], 'shop' => 1, 'url' => $p[2], 'created' => time(), 'expires' => time() + 3600]);
    $keys[$p[0] . $p[1]] = $kk;
}
$keys['product7'] = $k;
$s = SpcPageCache::stats(1, time());
ok($s['pages'] === 6 && $s['bytes'] > 0, 'the figures count the pages kept (' . $s['pages'] . ', the expired one not)');

// --- clearing
$n = SpcPageCache::productChanged(7);
$left = function ($id) use ($keys) { return is_file(SpcPageCache::file($keys[$id])); };
ok($n === 4 && !$left('product7') && !$left('category3') && !$left('index0') && !$left('manufacturer2'), 'a product changed: its page, its categories, its brand and the home page go (' . $n . ')');
ok($left('category5') && $left('cms4') && $left('product8'), 'other categories, products and pages stay');
ok(SpcPageCache::flush() === 3 && !$left('cms4') && !glob(SpcPageCache::folder() . '*/*.html.gz'), 'emptying it removes every page');
SpcPageCache::write($k, $html, ['controller' => 'product', 'id_object' => 7, 'shop' => 1, 'url' => '/x', 'created' => $t0, 'expires' => $t0 + 10]);
ok(SpcPageCache::purge($t0 + 20) === 1 && !is_file(SpcPageCache::file($k)), 'expired pages are cleared');

// --- storing only what is the same for everyone
$ctx = (object) ['customer' => new class { function isLogged() { return false; } }, 'cart' => (object) ['id' => 0, 'n' => 0], 'controller' => (object) ['errors' => []], 'shop' => (object) ['id' => 1]];
$_SERVER['REQUEST_URI'] = '/pl/7-kimchi.html';
$_SERVER['HTTP_HOST'] = 'shop.test';
$rp = new ReflectionProperty('SpcPageCache', 'key');
$rp->setAccessible(true);
$rp->setValue(null, $k);
ok(SpcPageCache::store('product', $html, $ctx) && is_file(SpcPageCache::file($k)), 'a normal page for a visitor is kept');
SpcPageCache::flush();
$ctx->cart = (object) ['id' => 3, 'n' => 2];
ok(!SpcPageCache::store('product', $html, $ctx), 'not when the visitor has something in the cart');
$ctx->cart = (object) ['id' => 0, 'n' => 0];
$ctx->controller->errors = ['Brak'];
ok(!SpcPageCache::store('product', $html, $ctx), 'not with an error message on it');
$ctx->controller->errors = [];
ok(!SpcPageCache::store('product', '<html><body>short</body>', $ctx), 'not a cut-off page');
SpcAudit::$parts = ['cache'];
ok(!SpcPageCache::store('product', $html, $ctx), 'never a page built for the speed audit');
SpcAudit::$parts = null;

// --- Optimize: picture copies
@mkdir(SPC_TMP . '/root/img/p/1/2', 0777, true);
$jpg = SPC_TMP . '/root/img/p/1/2/12-home_default.jpg';
$im = imagecreatetruecolor(400, 300);
for ($x = 0; $x < 400; $x += 20) {
    imagefilledrectangle($im, $x, 0, $x + 19, 299, imagecolorallocate($im, $x % 255, 120, 200 - $x % 200));
}
imagejpeg($im, $jpg, 95);
imagedestroy($im);
ok(SpcImages::productFiles(12) === [$jpg], 'the sizes of a product picture are found');
$saved = SpcImages::convert($jpg, 'webp', 82);
ok($saved > 0 && is_file(SPC_TMP . '/root/img/p/1/2/12-home_default.webp') && filesize(SPC_TMP . '/root/img/p/1/2/12-home_default.webp') < filesize($jpg), 'a WebP copy, smaller (' . $saved . ' bytes saved)');
ok(SpcImages::convert($jpg, 'webp', 82) === 0, 'a picture with a fresh copy is skipped');
$base = 'https://shop.test/';
ok(SpcImages::resolve('https://shop.test/12-home_default/kimchi.jpg', ['webp'], $base, 'shop.test') === 'https://shop.test/img/p/1/2/12-home_default.webp', 'the friendly address of a product picture points at its copy');
ok(SpcImages::resolve('https://shop.test/img/p/1/2/12-home_default.jpg', ['avif', 'webp'], $base, 'shop.test') === 'https://shop.test/img/p/1/2/12-home_default.webp', 'AVIF first when there is one, else WebP');
ok(SpcImages::resolve('https://cdn.other/12-home_default/kimchi.jpg', ['webp'], $base, 'shop.test') === null
    && SpcImages::resolve('https://shop.test/12-large_default/kimchi.jpg', ['webp'], $base, 'shop.test') === null
    && SpcImages::resolve('https://shop.test/12-home_default/kimchi.jpg?x=1', ['webp'], $base, 'shop.test') === null, 'another site, a size with no copy, an address with a query: left alone');
touch($jpg, time() + 60);
clearstatcache();
ok(SpcImages::resolve('https://shop.test/12-home_default/kimchi.jpg', ['webp'], $base, 'shop.test') === null, 'a picture made again after its copy: the copy is not used until converted again');
SpcImages::forget(12);
ok(!is_file(SPC_TMP . '/root/img/p/1/2/12-home_default.webp'), 'a deleted product image takes its copies with it');

// --- Optimize: server headers in .htaccess
$ht = SPC_TMP . '/root/.htaccess';
file_put_contents($ht, "# ~~start~~ Do not remove this comment, Prestashop will keep automatically the code outside this comment when .htaccess will be generated again\nRewriteEngine on\n# ~~end~~\n");
$before = file_get_contents($ht);
ok(SpcOptimize::headers(true) && SpcOptimize::headersWritten() && strpos(file_get_contents($ht), 'ExpiresByType image/webp') !== false, 'the block is written to .htaccess');
ok(strpos(file_get_contents($ht), "RewriteEngine on") !== false && strpos(file_get_contents($ht), SpcOptimize::HTACCESS_START) === 0, 'before PrestaShop\'s own part, which stays');
ok(is_file($ht . '.speedpackcore.bak') && file_get_contents($ht . '.speedpackcore.bak') === $before, 'a copy of the file as it was is kept');
SpcOptimize::headers(true);
ok(substr_count(file_get_contents($ht), SpcOptimize::HTACCESS_START) === 1, 'written twice, it is there once');
ok(SpcOptimize::headers(false) && file_get_contents($ht) === $before && !SpcOptimize::headersWritten(), 'switched off, the file is back as it was');
ok(strpos(SpcOptimize::nginxSnippet(), 'gzip on;') !== false && strpos(SpcOptimize::nginxSnippet(), 'location ~*') !== false, 'the nginx lines for shops on nginx');

// --- Optimize: which formats a browser gets
Configuration::$v[SpcOptimize::K_ENABLED] = 1;
Configuration::$v[SpcOptimize::K_WEBP] = 1;
Configuration::$v[SpcOptimize::K_AVIF] = 1;
$avif = SpcImages::formats()['avif'];
ok(SpcOptimize::formatsFor('image/avif,image/webp,*/*') === ($avif ? ['avif', 'webp'] : ['webp']) && SpcOptimize::formatsFor('image/webp,*/*') === ['webp'] && SpcOptimize::formatsFor('*/*') === [], 'the browser\'s Accept decides the format');
Configuration::$v[SpcOptimize::K_ENABLED] = 0;
ok(SpcOptimize::formatsFor('image/webp') === [], 'nothing while Optimize is off');

// --- the whole way, through a web server: built, then kept, then sent ready (gzipped or not)
$db->execute('DROP TABLE IF EXISTS `pcs_spc_pagecache`');
$db->execute('CREATE TABLE `pcs_spc_pagecache` LIKE ' . SpcPageCache::table());
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
@mkdir(SPC_TMP . '/pcs', 0777, true);
$env = array_merge(getenv(), ['SPC_PC_TMP' => SPC_TMP . '/pcs', 'SPC_ROOT' => SPC_ROOT]);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/mock/pagecache-shop.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); ++$i) {
    usleep(100000);
}
$get = function ($path, array $headers = []) use ($port) {
    $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $ctx);
    $h = [];
    foreach ($http_response_header as $line) {
        if (strpos($line, ':')) {
            list($k, $v) = explode(':', $line, 2);
            $h[strtolower(trim($k))] = trim($v);
        }
    }

    return [$h, $body];
};
list($h1, $b1) = $get('/7-kimchi.html');
list($h2, $b2) = $get('/7-kimchi.html?utm_source=newsletter');
ok($h1['x-speedpack-cache'] === 'MISS' && $h2['x-speedpack-cache'] === 'HIT' && $b1 === $b2, 'the first visit builds the page, the next one gets it ready (the campaign tag changes nothing)');
list($h3, $b3) = $get('/7-kimchi.html', ['Accept-Encoding: gzip']);
ok($h3['x-speedpack-cache'] === 'HIT' && isset($h3['content-encoding']) && $h3['content-encoding'] === 'gzip' && gzdecode($b3) === $b1, 'a browser that takes gzip gets the stored gzip as it is');
list($h4, $b4) = $get('/7-kimchi.html', ['Cookie: ps_id_cart=5']);
ok($h4['x-speedpack-cache'] === 'BYPASS visitor' && $b4 !== $b1, 'a visitor with a cart gets the page built for them');
list($h5) = $get('/koszyk');
ok($h5['x-speedpack-cache'] === 'BYPASS page', 'the cart page is never kept');
list($h6, $b6) = $get('/7-kimchi.html', ['Cookie: spc_audit=x']);
ok(!isset($h6['x-speedpack-cache']) && $b6 !== $b1, 'a request of the speed audit is built every time');
proc_terminate($proc);
proc_close($proc);
$db->execute('DROP TABLE IF EXISTS `pcs_spc_pagecache`');

foreach (['spc_pagecache', 'category_product', 'product'] as $t) {
    $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $t . '`');
}
echo "ALL OK\n";
