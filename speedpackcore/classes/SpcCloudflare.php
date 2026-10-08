<?php
/**
 * SpeedPack Core - Cloudflare kept in step with the page cache: when pages are cleared here
 * (a product, its price or stock, a category changed), the same addresses are purged at Cloudflare;
 * when everything is emptied ("Clear cache", the page cache emptied), Cloudflare is purged whole.
 * Needs the zone ID and an API token with the "Cache Purge" permission.
 *
 * Purges are sent when the visitor's (or the admin's) page has gone out where PHP can do that
 * (fastcgi_finish_request), and never hold anything up for more than a few seconds otherwise.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcCloudflare
{
    public const K_ZONE = 'SPC_CF_ZONE';
    public const K_TOKEN = 'SPC_CF_TOKEN';
    public const K_LAST = 'SPC_CF_LAST';

    /** Cloudflare takes at most 30 addresses a call */
    public const PER_CALL = 30;

    /** the API (tests point it at a mock) */
    public static $api = 'https://api.cloudflare.com/client/v4';

    /** @var array addresses waiting for the end of the request; true = everything */
    protected static $pending = [];
    protected static $all = false;
    protected static $armed = false;

    public static function configured()
    {
        return preg_match('/^[a-f0-9]{32}$/i', (string) Configuration::get(self::K_ZONE)) && (string) Configuration::get(self::K_TOKEN) !== '';
    }

    /** These addresses purged once this request ends. */
    public static function purge(array $urls)
    {
        if (!self::configured() || !$urls) {
            return;
        }
        self::$pending = array_values(array_unique(array_merge(self::$pending, $urls)));
        self::arm();
    }

    /** Everything purged once this request ends. */
    public static function purgeAll()
    {
        if (!self::configured()) {
            return;
        }
        self::$all = true;
        self::arm();
    }

    protected static function arm()
    {
        if (self::$armed) {
            return;
        }
        self::$armed = true;
        register_shutdown_function([__CLASS__, 'flushPending']);
    }

    /** What is pending, sent now (called at the end of the request). */
    public static function flushPending()
    {
        if (!self::$all && !self::$pending) {
            return [];
        }
        if (function_exists('fastcgi_finish_request') && PHP_SAPI !== 'cli') {
            @fastcgi_finish_request();
        }
        $answers = [];
        if (self::$all) {
            $answers[] = self::call('POST', '/purge_cache', ['purge_everything' => true]);
        } else {
            foreach (array_chunk(self::$pending, self::PER_CALL) as $chunk) {
                $answers[] = self::call('POST', '/purge_cache', ['files' => $chunk]);
            }
        }
        self::$pending = [];
        self::$all = false;
        $ok = !in_array(false, array_map(function ($a) { return !empty($a['success']); }, $answers), true);
        Configuration::updateGlobalValue(self::K_LAST, json_encode(['at' => date('Y-m-d H:i'), 'ok' => $ok, 'calls' => count($answers),
            'error' => $ok ? '' : self::firstError($answers), ]));

        return $answers;
    }

    /** Whether the zone and token work: the zone read back with its name. */
    public static function test()
    {
        $a = self::call('GET', '', null);

        return !empty($a['success']) ? ['ok' => true, 'name' => isset($a['result']['name']) ? (string) $a['result']['name'] : ''] : ['ok' => false, 'error' => self::firstError([$a])];
    }

    protected static function firstError(array $answers)
    {
        foreach ($answers as $a) {
            if (!empty($a['errors'][0]['message'])) {
                return (string) $a['errors'][0]['message'];
            }
            if (!empty($a['error'])) {
                return (string) $a['error'];
            }
        }

        return 'no answer';
    }

    protected static function call($method, $path, $body)
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'the server has no cURL'];
        }
        $ch = curl_init(self::$api . '/zones/' . rawurlencode((string) Configuration::get(self::K_ZONE)) . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . Configuration::get(self::K_TOKEN), 'Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $a = json_decode((string) $raw, true);

        return is_array($a) ? $a : ['success' => false, 'error' => $err ?: 'unexpected answer'];
    }
}
