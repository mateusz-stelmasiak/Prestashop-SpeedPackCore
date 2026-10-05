<?php
/**
 * PHP's OPcache: whether it is on, how full it is, and a reset button.
 *
 * OPcache keeps PHP's compiled code in memory, so each page skips reading
 * and parsing thousands of files. Its settings live in php.ini and can only
 * be changed by the host, so this reads them and says in plain words what to
 * ask for when one is holding the shop back.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcOpcache
{
    /** PrestaShop 1.7/8 with its modules loads well over 10 000 PHP files. */
    public const SCRIPTS_WANTED = 20000;

    public static function loaded()
    {
        return extension_loaded('Zend OPcache') || extension_loaded('opcache');
    }

    /**
     * @return array|string the numbers, or why they are not available:
     *                      'missing', 'off' or 'restricted'
     */
    public static function status()
    {
        if (!self::loaded() || !function_exists('opcache_get_status')) {
            return 'missing';
        }

        /* Warns, and returns false, when opcache.restrict_api keeps this
         * script out, and false when OPcache is loaded but switched off. */
        $status = @opcache_get_status(false);
        if (!is_array($status)) {
            return self::restricted() ? 'restricted' : 'off';
        }
        if (empty($status['opcache_enabled'])) {
            return 'off';
        }

        $config = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : false;
        $directives = is_array($config) && isset($config['directives']) ? $config['directives'] : [];

        $memory = isset($status['memory_usage']) ? $status['memory_usage'] : [];
        $stats = isset($status['opcache_statistics']) ? $status['opcache_statistics'] : [];

        $used = (float) self::pick($memory, 'used_memory');
        $free = (float) self::pick($memory, 'free_memory');
        $wasted = (float) self::pick($memory, 'wasted_memory');

        return [
            'hits' => (int) self::pick($stats, 'hits'),
            'misses' => (int) self::pick($stats, 'misses'),
            'used' => $used + $wasted,
            'limit' => $used + $free + $wasted,
            'wasted' => $wasted,
            'scripts' => (int) self::pick($stats, 'num_cached_scripts'),
            'max_scripts' => (int) self::pick($stats, 'max_cached_keys'),
            'oom_restarts' => (int) self::pick($stats, 'oom_restarts'),
            'validate' => (bool) self::pick($directives, 'opcache.validate_timestamps'),
            'revalidate' => (int) self::pick($directives, 'opcache.revalidate_freq'),
            // bytes, as opcache_get_configuration() reports it
            'memory_setting' => (float) self::pick($directives, 'opcache.memory_consumption'),
        ];
    }

    /** opcache.restrict_api lets only scripts under one path read or reset OPcache. */
    protected static function restricted()
    {
        $path = (string) ini_get('opcache.restrict_api');
        if ($path === '') {
            return false;
        }

        $here = isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';

        return strpos($here, $path) !== 0;
    }

    /**
     * What to ask the host for, in order of how much it matters.
     *
     * @return array list of array('level' => 'warning'|'info', 'text' => ...)
     */
    public static function advice($status, Module $module)
    {
        if ($status === 'missing' || $status === 'off') {
            return [['level' => 'warning', 'text' => $module->l('OPcache is off. Ask the host to switch it on (opcache.enable=1). For PHP it is the biggest free speed-up there is.', 'spcopcache')]];
        }
        if ($status === 'restricted') {
            return [['level' => 'info', 'text' => $module->l('The server does not let the shop read OPcache (opcache.restrict_api), so its numbers cannot be shown here.', 'spcopcache')]];
        }

        $tips = [];

        if ($status['oom_restarts'] > 0 || ($status['limit'] > 0 && $status['used'] / $status['limit'] > 0.9)) {
            $tips[] = ['level' => 'warning', 'text' => sprintf(
                $module->l('OPcache is nearly full, so it keeps throwing compiled code away. Ask the host to raise opcache.memory_consumption from %d MB to at least 256 MB.', 'spcopcache'),
                round(($status['memory_setting'] ?: $status['limit']) / 1048576)
            )];
        }

        if ($status['max_scripts'] > 0 && ($status['max_scripts'] < self::SCRIPTS_WANTED || $status['scripts'] / $status['max_scripts'] > 0.9)) {
            $tips[] = ['level' => 'warning', 'text' => sprintf(
                $module->l('OPcache can hold %1$s files and already holds %2$s. PrestaShop needs room for about 20 000 - ask the host to set opcache.max_accelerated_files to 32531.', 'spcopcache'),
                number_format($status['max_scripts'], 0, '.', ' '),
                number_format($status['scripts'], 0, '.', ' ')
            )];
        }

        if (!$status['validate']) {
            $tips[] = ['level' => 'info', 'text' => $module->l('PHP does not check files for changes (opcache.validate_timestamps=0). That is the fastest setting, but after uploading a module or theme, press "Reset OPcache" or the old code keeps running.', 'spcopcache')];
        }

        return $tips;
    }

    /** @return bool */
    public static function reset()
    {
        if (!function_exists('opcache_reset') || self::restricted()) {
            return false;
        }

        return (bool) @opcache_reset();
    }

    protected static function pick($array, $key)
    {
        return (is_array($array) && isset($array[$key])) ? $array[$key] : 0;
    }
}
