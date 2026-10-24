<?php
require __DIR__ . '/bootstrap.php';
// Picture compression (Optimize): real GD on pictures made here, TinyPNG through a stand-in.
define('_PS_VERSION_', '1.7.8.0'); define('_DB_PREFIX_', 'ps_'); define('_PS_ROOT_DIR_', SPC_FAKEPS);
class Configuration { static $v = []; static function get($k) { return isset(self::$v[$k]) ? self::$v[$k] : false; } static function getGlobalValue($k) { return self::get($k); } static function updateValue($k, $x) { self::$v[$k] = $x; return true; } static function updateGlobalValue($k, $x) { return self::updateValue($k, $x); } }
class Db { static $images = 2; static function getInstance() { return new self(); } function getValue($q) { return self::$images; } function executeS($q) { preg_match('/LIMIT (\d+), (\d+)/', $q, $m); $out = []; for ($i = (int) $m[1] + 1; $i <= min(self::$images, $m[1] + $m[2]); $i++) { $out[] = ['id_image' => $i]; } return $out; } }
require SPC_MODULE . '/classes/SpcImages.php';
require SPC_MODULE . '/classes/SpcCompress.php';

/** a photo-like JPEG (noise and gradients, so there is something to compress), saved at quality 100 */
function photo($path, $w = 400, $h = 300)
{
    @mkdir(dirname($path), 0777, true);
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocate($im, ($x + mt_rand(0, 40)) % 256, ($y + mt_rand(0, 40)) % 256, ($x * $y + mt_rand(0, 40)) % 256));
        }
    }
    imagejpeg($im, $path, 100);
    imagedestroy($im);
    touch($path, time() - 3600);
}
mt_srand(7);
$root = SPC_FAKEPS . '/';
$a = $root . 'img/p/1/1-home_default.jpg';
$b = $root . 'img/p/1/1-large_default.jpg';
$c = $root . 'img/p/2/2-home_default.jpg';
$cat = $root . 'img/c/3-category_default.jpg';
foreach ([$a, $b, $c, $cat] as $f) { photo($f); }
file_put_contents(SpcImages::target($a, 'webp'), 'a copy');
touch(SpcImages::target($a, 'webp'), filemtime($a));
$mtime = filemtime($a);
$size = filesize($a);

// on this server
$r = SpcCompress::file($a, 'local', 80);
clearstatcache();
ok($r['after'] < $r['before'] && filesize($a) === $r['after'] && $r['before'] === $size, 'on this server: the picture is smaller (' . $r['before'] . ' → ' . $r['after'] . ' bytes)');
ok(is_file(SpcCompress::backup($a)) && filesize(SpcCompress::backup($a)) === $size, 'its original is kept in var/speedpack-originals, unchanged');
ok(filemtime($a) === $mtime && SpcImages::fresh($a, 'webp'), 'the picture keeps its date, so its WebP copy (made from the original) stays in use');
ok(SpcCompress::done($a), 'it counts as compressed');
ok(getimagesize($a)[2] === IMAGETYPE_JPEG && getimagesize($a)[0] === 400, 'it is still a JPEG of the same size');
$r = SpcCompress::file($a, 'local', 80);
ok($r['after'] === $r['before'], 'a picture compressed already is left alone (no second generation)');
touch($a, time());
clearstatcache();
ok(!SpcCompress::done($a), 'a picture PrestaShop made again (new date) counts as not compressed');
touch($a, $mtime);

// not worth it: already small
$small = $root . 'img/p/1/1-small_default.jpg';
@mkdir(dirname($small), 0777, true);
photo($small, 98, 98);
$im = imagecreatefromjpeg($small);
imagejpeg($im, $small, 60); // saved small already: at 90 it would only grow
$r = SpcCompress::file($small, 'local', 90);
ok($r['after'] === $r['before'] && !is_file(SpcCompress::backup($small)), 'a result not at least 3% smaller is not kept, and no original is copied');

// TinyPNG, through a stand-in
SpcCompress::$tinify = function ($file) { $im = imagecreatefromjpeg($file); ob_start(); imagejpeg($im, null, 30); return ob_get_clean(); };
$r = SpcCompress::file($b, 'tinypng');
ok($r['after'] < $r['before'] && SpcCompress::done($b), 'TinyPNG: its answer replaces the picture, original kept');
SpcCompress::$tinify = function ($file) { return ['error' => 'TinyPNG: Your monthly limit has been exceeded', 'stop' => true]; };
$before = filesize($c);
Configuration::$v[SpcCompress::K_ENGINE] = 'tinypng';
$s = SpcCompress::step(0, 5);
clearstatcache();
ok($s['done'] && strpos($s['error'], 'monthly limit') !== false && filesize($c) === $before && !is_file(SpcCompress::backup($c)), 'TinyPNG refusing (key, monthly limit): the run stops with its message, the picture untouched');

// the whole catalogue in steps, then everything back
Configuration::$v[SpcCompress::K_ENGINE] = 'local';
$n = 0;
$offset = 0;
do { $s = SpcCompress::step($offset, 1); $offset = $s['offset']; $n += $s['files']; } while (!$s['done']);
ok($n === 2 && SpcCompress::done($c) && SpcCompress::done($cat), 'in steps: product images, then category pictures (' . $n . ' more)');
$st = SpcCompress::stats();
ok($st['files'] === 2 && $st['after'] < $st['before'], 'the figures count what the runs compressed (2 pictures, ' . ($st['before'] - $st['after']) . ' bytes saved)');
touch($b, time()); // made again by PrestaShop since: that one stays as it is now
$kept = file_get_contents($b);
$r1 = SpcCompress::restore(2);
$r2 = SpcCompress::restore(100);
clearstatcache();
ok($r1['restored'] === 2 && $r1['left'] === 2 && $r2['left'] === 0, 'originals put back in steps');
ok(filesize($a) === $size && filemtime($a) === $mtime && file_get_contents($b) === $kept, 'each picture is its original again; one made again since is left as it is');
ok(!is_dir(SpcCompress::backupRoot()) && SpcCompress::stats()['files'] === 0, 'nothing left in var/speedpack-originals, the figures reset');
