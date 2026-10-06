<?php
/**
 * SpeedPack Core - the server and database check: PrestaShop's own tuning guide
 * (devdocs: Scale > Optimizations), read from the running shop instead of copied by hand.
 *
 * Every check answers ok / warning / problem, with the value found, the value wanted and what to
 * do. Nothing here changes the server: php.ini and my.cnf belong to the host, so the failing
 * checks are also written out as the lines to send them (hostLines()).
 *
 * Where the guide has gone stale it is not followed: magic_quotes_gpc and opcache.fast_shutdown
 * no longer exist, MySQL 8 has no query cache, and PrestaShop 9 has no Smarty "caching type".
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcHealth
{
    public const OK = 'ok';
    public const WARN = 'warning';
    public const BAD = 'problem';
    public const INFO = 'info';

    /** @var Module */
    protected $module;

    /** @var array php.ini / my.cnf lines for the checks that failed */
    protected $lines = ['php' => [], 'mysql' => []];

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    protected function l($s)
    {
        return $this->module->l($s, 'spchealth');
    }

    protected function row($label, $value, $want, $level, $fix = '')
    {
        return ['label' => $label, 'value' => (string) $value, 'want' => (string) $want, 'level' => $level, 'fix' => $fix];
    }

    /** "512M" => bytes; -1 stays -1. */
    public static function bytes($v)
    {
        $v = trim((string) $v);
        if ($v === '' || $v === '-1') {
            return $v === '-1' ? -1 : 0;
        }
        $n = (float) $v;
        switch (Tools::strtolower(Tools::substr($v, -1))) {
            case 'g':
                return (int) ($n * 1073741824);
            case 'm':
                return (int) ($n * 1048576);
            case 'k':
                return (int) ($n * 1024);
        }

        return (int) $n;
    }

    public static function size($bytes)
    {
        if ($bytes < 0) {
            return '∞';
        }
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $f) {
            if ($bytes >= $f) {
                return round($bytes / $f, $bytes >= 10 * $f ? 0 : 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }

    /* ------------------------------------------------------------------ *
     *  PHP and PrestaShop
     * ------------------------------------------------------------------ */

    public function php()
    {
        $rows = [];
        $v = PHP_VERSION;
        $rows[] = $this->row('PHP', $v, '8.1+', version_compare($v, '8.1', '>=') ? self::OK : (version_compare($v, '7.4', '>=') ? self::WARN : self::BAD),
            $this->l('Older PHP versions are slower and no longer get security fixes. Ask the host for the newest version your PrestaShop supports.'));

        $sapi = PHP_SAPI;
        $fpm = in_array($sapi, ['fpm-fcgi', 'cgi-fcgi', 'litespeed'], true);
        $rows[] = $this->row($this->l('PHP runs as'), $sapi, 'PHP-FPM', $fpm ? self::OK : self::INFO,
            $fpm ? '' : $this->l('PHP inside Apache (mod_php) holds a whole PHP process for every image and stylesheet too. PHP-FPM with Apache mpm_event (or nginx) serves more visitors with the same memory.'));

        $mem = self::bytes(ini_get('memory_limit'));
        $rows[] = $this->iniRow('memory_limit', ini_get('memory_limit'), '512M', $mem === -1 || $mem >= 536870912 ? self::OK : ($mem >= 268435456 ? self::WARN : self::BAD),
            $this->l('Too little memory stops imports, module installs and big carts half way.'));

        $rows[] = $this->iniRow('max_execution_time', ini_get('max_execution_time'), '300', ((int) ini_get('max_execution_time') === 0 || (int) ini_get('max_execution_time') >= 300) ? self::OK : self::WARN,
            $this->l('Imports, the search index and module upgrades need time; visitors never wait this long anyway.'));

        $vars = (int) ini_get('max_input_vars');
        $rows[] = $this->iniRow('max_input_vars', $vars, '20000', $vars >= 20000 ? self::OK : self::WARN,
            $this->l('A product with many combinations sends thousands of fields when saved; past this limit PHP silently drops the rest.'));

        $up = min(self::bytes(ini_get('upload_max_filesize')), self::bytes(ini_get('post_max_size')));
        $rows[] = $this->row('upload_max_filesize / post_max_size', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'), '20M / 22M', $up >= 20971520 ? self::OK : self::WARN,
            $this->l('Product photos and module zips larger than this cannot be uploaded.'));
        if ($up < 20971520) {
            $this->lines['php'][] = 'upload_max_filesize = 20M';
            $this->lines['php'][] = 'post_max_size = 22M';
        }

        // realpath cache: PHP reports how full it really is, which says more than the guide's fixed size
        $rpSize = self::bytes(ini_get('realpath_cache_size'));
        $rpUsed = function_exists('realpath_cache_size') ? realpath_cache_size() : 0;
        $full = $rpSize > 0 && $rpUsed / $rpSize > 0.9;
        $rows[] = $this->iniRow('realpath_cache_size', ini_get('realpath_cache_size') . ($rpUsed ? ' (' . sprintf($this->l('%s used'), self::size($rpUsed)) . ')' : ''), '4096K',
            $rpSize >= 4194304 && !$full ? self::OK : self::WARN,
            $full ? $this->l('The cache of file paths is full, so PHP asks the disk again where each of the thousands of PrestaShop files is.') : $this->l('PHP remembers where files are, instead of asking the disk on every include.'), '4096K');
        $rows[] = $this->iniRow('realpath_cache_ttl', ini_get('realpath_cache_ttl'), '600', (int) ini_get('realpath_cache_ttl') >= 600 ? self::OK : self::WARN, '');

        $rows[] = $this->iniRow('display_errors', ini_get('display_errors') ?: 'Off', 'Off', $this->on(ini_get('display_errors')) ? self::BAD : self::OK,
            $this->l('Errors shown to visitors reveal file paths, and every notice costs time.'), 'Off');
        $rows[] = $this->iniRow('session.auto_start', ini_get('session.auto_start') ?: 'Off', 'Off', $this->on(ini_get('session.auto_start')) ? self::BAD : self::OK,
            $this->l('A session started on every request makes every page uncacheable and locks parallel requests.'), 'Off');

        return array_merge($rows, $this->opcache());
    }

    protected function on($v)
    {
        return in_array(Tools::strtolower((string) $v), ['1', 'on', 'yes', 'true', 'stderr', 'stdout'], true);
    }

    /** A php.ini row: a failing one also goes into the lines for the host. */
    protected function iniRow($key, $value, $want, $level, $fix, $line = null)
    {
        if ($level === self::WARN || $level === self::BAD) {
            $this->lines['php'][] = $key . ' = ' . ($line !== null ? $line : $want);
        }

        return $this->row($key, $value, $want, $level, $fix);
    }

    /** The two OPcache settings the guide adds to what the Cache section already shows. */
    protected function opcache()
    {
        if (!SpcOpcache::loaded() || !ini_get('opcache.enable')) {
            return [];
        }
        $rows = [];
        $buffer = (int) ini_get('opcache.interned_strings_buffer');
        $usage = null;
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (is_array($status) && isset($status['interned_strings_usage']['buffer_size']) && $status['interned_strings_usage']['buffer_size'] > 0) {
            $usage = $status['interned_strings_usage']['used_memory'] / $status['interned_strings_usage']['buffer_size'];
        }
        $rows[] = $this->iniRow('opcache.interned_strings_buffer', $buffer . ' MB' . ($usage !== null ? ' (' . round($usage * 100) . '%)' : ''), '32',
            $buffer >= 32 && ($usage === null || $usage < 0.9) ? self::OK : self::WARN,
            $this->l('Where OPcache keeps the class and method names; PrestaShop and Symfony have a lot of them. When it is full, PHP stops sharing them between requests.'));
        $validate = $this->on(ini_get('opcache.validate_timestamps'));
        $freq = (int) ini_get('opcache.revalidate_freq');
        $rows[] = $this->iniRow('opcache.revalidate_freq', $validate ? $freq . ' s' : $this->l('never (validate_timestamps=0)'), '10',
            !$validate || $freq >= 10 ? self::OK : self::WARN,
            $this->l('How often PHP checks whether a file changed. At 0 it checks every file on every request.'));

        return $rows;
    }

    /** A switch of config/defines.inc.php, read as the running shop has it. */
    protected static function flag($name)
    {
        return defined($name) && (bool) constant($name);
    }

    public function prestashop()
    {
        $rows = [];
        $dev = self::flag('_PS_MODE_DEV_');
        $rows[] = $this->row($this->l('Debug mode'), $dev ? $this->l('on') : $this->l('off'), $this->l('off'), $dev ? self::BAD : self::OK,
            $this->l('Debug mode recompiles templates and Symfony containers and shows errors to visitors. Switch it off under Advanced Parameters > Performance (or _PS_MODE_DEV_ in config/defines.inc.php).'));
        $prof = self::flag('_PS_DEBUG_PROFILING_');
        $rows[] = $this->row($this->l('Profiling'), $prof ? $this->l('on') : $this->l('off'), $this->l('off'), $prof ? self::BAD : self::OK,
            $this->l('The profiler times every hook and query of every page. Set _PS_DEBUG_PROFILING_ to false in config/defines.inc.php.'));
        $compile = (int) Configuration::get('PS_SMARTY_FORCE_COMPILE');
        $rows[] = $this->row($this->l('Template compilation'), [0 => $this->l('never'), 1 => $this->l('when changed'), 2 => $this->l('every time')][$compile] ?? $compile,
            $this->l('never / when changed'), $compile === 2 ? self::BAD : self::OK, $this->l('"Every time" compiles all templates on each request. Set in the Cache section below.'));
        $rows[] = $this->row($this->l('Template cache'), Configuration::get('PS_SMARTY_CACHE') ? $this->l('on') : $this->l('off'), $this->l('on'),
            Configuration::get('PS_SMARTY_CACHE') ? self::OK : self::WARN, $this->l('Switch it on in the Cache section below.'));
        $multi = (bool) Configuration::get('PS_SMARTY_LOCAL');
        $rows[] = $this->row($this->l('Multi-front optimizations'), $multi ? $this->l('on') : $this->l('off'), $this->l('off, with one front server'), $multi ? self::WARN : self::OK,
            $this->l('Only for several front servers that do not share the template cache. With one server it only adds work. Advanced Parameters > Performance.'));
        $media = trim((string) Configuration::get('PS_MEDIA_SERVER_1'));
        $rows[] = $this->row($this->l('Media server / CDN'), $media !== '' ? $media : $this->l('none'), $this->l('optional'), self::INFO,
            $this->l('A CDN (Cloudflare has a free plan) serves pictures, CSS and scripts from near the visitor and takes those requests off your server. Set under Advanced Parameters > Performance > Media servers.'));
        $real = _PS_ROOT_DIR_ . '/vendor/composer/autoload_real.php';
        $map = _PS_ROOT_DIR_ . '/vendor/composer/autoload_classmap.php';
        if (is_file($real)) {
            $authoritative = strpos((string) @file_get_contents($real), 'setClassMapAuthoritative(true)') !== false;
            $mapSize = is_file($map) ? (int) @filesize($map) : 0;
            $rows[] = $this->row($this->l('Class autoloader'), $authoritative ? $this->l('class map only') : ($mapSize > 500000 ? $this->l('optimized') : $this->l('scans folders')), $this->l('optimized'),
                $authoritative || $mapSize > 500000 ? self::OK : self::INFO,
                $this->l('Ask the host to run "composer dump-autoload --optimize --no-dev" after each PrestaShop update, so PHP finds classes in one list instead of searching folders.'));
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ *
     *  The database
     * ------------------------------------------------------------------ */

    public static function variables()
    {
        $out = [];
        foreach ((array) Db::getInstance()->executeS('SHOW VARIABLES') as $r) {
            if (isset($r['Variable_name'], $r['Value'])) {
                $out[Tools::strtolower($r['Variable_name'])] = $r['Value'];
            }
        }

        return $out;
    }

    public static function status()
    {
        $out = [];
        foreach ((array) Db::getInstance()->executeS('SHOW GLOBAL STATUS') as $r) {
            if (isset($r['Variable_name'], $r['Value'])) {
                $out[Tools::strtolower($r['Variable_name'])] = $r['Value'];
            }
        }

        return $out;
    }

    /** The shop's tables: total size, and how many are still MyISAM. */
    public static function databaseSize()
    {
        $r = Db::getInstance()->getRow('SELECT SUM(data_length + index_length) AS size, COUNT(*) AS tables, SUM(engine = \'MyISAM\') AS myisam
            FROM information_schema.tables WHERE table_schema = DATABASE() AND LEFT(table_name, ' . (int) strlen(_DB_PREFIX_) . ') = \'' . Db::getInstance()->escape(_DB_PREFIX_) . '\'');

        if (!is_array($r)) {
            return ['size' => 0, 'tables' => 0, 'myisam' => 0];
        }

        return ['size' => (int) $r['size'], 'tables' => (int) $r['tables'], 'myisam' => (int) $r['myisam']];
    }

    public function database()
    {
        $v = self::variables();
        $st = self::status();
        $db = self::databaseSize();
        $rows = [];
        $version = isset($v['version']) ? $v['version'] : '?';
        $maria = stripos($version . (isset($v['version_comment']) ? $v['version_comment'] : ''), 'mariadb') !== false;
        $num = preg_replace('/[^0-9.].*$/', '', $version);
        $rows[] = $this->row($maria ? 'MariaDB' : 'MySQL', $version, $maria ? '10.6+' : '8.0+',
            version_compare($num, $maria ? '10.6' : '8.0', '>=') ? self::OK : self::WARN, $this->l('Newer versions plan the big product and order queries of PrestaShop much better.'));

        $pool = isset($v['innodb_buffer_pool_size']) ? (int) $v['innodb_buffer_pool_size'] : 0;
        $want = max(134217728, (int) (ceil($db['size'] * 1.3 / 134217728) * 134217728));
        $rows[] = $this->dbRow('innodb_buffer_pool_size', self::size($pool) . ' – ' . sprintf($this->l('the tables of the shop take %s'), self::size($db['size'])),
            self::size($want), $pool >= $db['size'] ? self::OK : ($pool * 2 >= $db['size'] ? self::WARN : self::BAD),
            $this->l('The memory MySQL keeps the tables in. Larger than the database means every query is answered from memory, never from disk – the most important setting of the guide.'),
            (int) round($want / 1048576) . 'M');

        $tmp = min(isset($v['tmp_table_size']) ? (int) $v['tmp_table_size'] : 0, isset($v['max_heap_table_size']) ? (int) $v['max_heap_table_size'] : 0);
        $created = isset($st['created_tmp_tables']) ? (int) $st['created_tmp_tables'] : 0;
        $disk = isset($st['created_tmp_disk_tables']) ? (int) $st['created_tmp_disk_tables'] : 0;
        $diskShare = $created > 0 ? $disk / $created : 0;
        $rows[] = $this->dbRow('tmp_table_size / max_heap_table_size', self::size($tmp) . ($created ? ' – ' . sprintf($this->l('%d%% of temporary tables went to disk'), round($diskShare * 100)) : ''),
            '32M', $tmp >= 33554432 && $diskShare < 0.25 ? self::OK : self::WARN,
            $this->l('Sorting and grouping (category pages, filters, stats) happen in memory up to this size, on disk above it.'), '32M');
        if ($tmp < 33554432) {
            $this->lines['mysql'][] = 'tmp_table_size = 32M';
            $this->lines['mysql'][] = 'max_heap_table_size = 32M';
        }

        $open = isset($v['table_open_cache']) ? (int) $v['table_open_cache'] : 0;
        $rows[] = $this->dbRow('table_open_cache', $open, '4000', $open >= 2000 ? self::OK : self::WARN,
            $this->l('PrestaShop has some 300 tables per shop, and every connection opens its own.'), '4000');

        $ps = isset($v['performance_schema']) ? Tools::strtoupper($v['performance_schema']) : 'OFF';
        $rows[] = $this->dbRow('performance_schema', $ps, 'OFF', $ps === 'ON' ? self::INFO : self::OK,
            $this->l('The monitoring built into MySQL. Useful while tuning; it costs memory and some speed the rest of the time.'), 'OFF');

        $qc = isset($v['query_cache_type']) ? Tools::strtoupper($v['query_cache_type']) : null;
        $qcSize = isset($v['query_cache_size']) ? (int) $v['query_cache_size'] : 0;
        $qcOn = $qc !== null && $qc !== 'OFF' && $qc !== '0' && $qcSize > 0;
        $rows[] = $this->row($this->l('Query cache'), $qc === null ? $this->l('not in this version') : ($qcOn ? $this->l('on') . ', ' . self::size($qcSize) : $this->l('off')), $this->l('either'), self::INFO,
            $qcOn ? $this->l('With the MySQL query cache on, a data cache (Redis, APCu) gains less: the speed audit measures how much on this shop.')
                : $this->l('Without the MySQL query cache, repeated queries reach the tables every time – this is where the data cache in the Cache section helps most.'));

        if ($db['myisam'] > 0) {
            $rows[] = $this->row($this->l('MyISAM tables'), $db['myisam'], '0', self::WARN,
                $this->l('MyISAM locks a whole table for every write, so one order blocks every page reading it. ALTER TABLE … ENGINE=InnoDB (back up first).'));
        }

        return $rows;
    }

    protected function dbRow($key, $value, $want, $level, $fix, $line = null)
    {
        if (($level === self::WARN || $level === self::BAD) && $line !== null && strpos($key, ' / ') === false) {
            $this->lines['mysql'][] = $key . ' = ' . $line;
        }

        return $this->row($key, $value, $want, $level, $fix);
    }

    /** What to send the host: the php.ini and my.cnf lines for every failing check. */
    public function hostLines()
    {
        $out = '';
        if ($this->lines['php']) {
            $out .= "; php.ini (or the PHP settings of the hosting panel)\n" . implode("\n", array_unique($this->lines['php'])) . "\n";
        }
        if ($this->lines['mysql']) {
            $out .= ($out ? "\n" : '') . "# my.cnf, [mysqld] section – then restart MySQL\n" . implode("\n", array_unique($this->lines['mysql'])) . "\n";
        }

        return $out;
    }

    /** @return array ok / warning / problem counts */
    public static function tally(array $rows)
    {
        $t = [self::OK => 0, self::WARN => 0, self::BAD => 0, self::INFO => 0];
        foreach ($rows as $r) {
            ++$t[$r['level']];
        }

        return $t;
    }
}
