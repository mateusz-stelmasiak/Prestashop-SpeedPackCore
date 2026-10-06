<?php
/**
 * Shared set-up for the PHP tests: where the module is, a throw-away PrestaShop root (only what
 * the module touches: app/config/parameters.php, override/, var/cache/), Smarty, and ok().
 *
 * Environment:
 *   SPC_SMARTY   path to Smarty's autoload.php (default: tests/vendor/autoload.php)
 *   SPC_TMP      folder for temporary files (default: the system temp folder)
 */
define('SPC_ROOT', getenv('SPC_ROOT') ?: dirname(__DIR__, 2));
define('SPC_MODULE', SPC_ROOT . '/speedpackcore');
define('SPC_TMP', rtrim(getenv('SPC_TMP') ?: sys_get_temp_dir(), '/') . '/spc-tests-' . getmypid());
define('SPC_FAKEPS', SPC_TMP . '/ps');

foreach (['/app/config', '/override/classes/cache', '/var/cache/prod', '/smarty'] as $d) {
    if (!is_dir(SPC_FAKEPS . $d) && !is_dir(SPC_TMP . $d)) {
        @mkdir($d === '/smarty' ? SPC_TMP . $d : SPC_FAKEPS . $d, 0777, true);
    }
}
spc_reset_fakeps();
register_shutdown_function(function () {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SPC_TMP, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir(SPC_TMP);
});

/** A fresh parameters.php, no Redis class, no backup: a shop before the module touched it. */
function spc_reset_fakeps()
{
    file_put_contents(SPC_FAKEPS . '/app/config/parameters.php', "<?php return array('parameters' => array('database_host' => '127.0.0.1', 'ps_caching' => 'CacheMemcache', 'ps_cache_enable' => false, 'cookie_key' => 'x'));\n");
    @unlink(SPC_FAKEPS . '/app/config/parameters.php.speedpackcore.bak');
    @unlink(SPC_FAKEPS . '/override/classes/cache/CacheRedis.php');
}

function spc_smarty_autoload()
{
    foreach ([getenv('SPC_SMARTY'), __DIR__ . '/../vendor/autoload.php'] as $f) {
        if ($f && is_file($f)) {
            return $f;
        }
    }
    fwrite(STDERR, "Smarty not found: run composer install in tests/, or set SPC_SMARTY to its autoload.php\n");
    exit(2);
}

function ok($c, $what)
{
    if (!$c) {
        echo "FAIL: $what\n";
        exit(1);
    }
    echo "ok  $what\n";
}

/** The module's version as config.xml declares it. */
function spc_version()
{
    return preg_match('/<version><!\[CDATA\[([^\]]+)\]\]>/', (string) file_get_contents(SPC_MODULE . '/config.xml'), $m) ? $m[1] : '?';
}

/** PrestaShop's Shop, as much as the module asks of it. */
class SpcShopStub
{
    public $id = 1;
    public $id_shop_group = 1;
    public $theme_name = 'classic';

    public function getBaseURL($ssl = true)
    {
        return 'https://shop.test/';
    }
}
