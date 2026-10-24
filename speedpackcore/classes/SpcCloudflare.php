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
    public const K_REALIP = 'SPC_CF_REALIP';
    public const K_STATE = 'SPC_CF_STATE';

    /** how long what Cloudflare said is shown before it is asked again */
    public const STATE_TTL = 300;

    /**
     * Cloudflare's addresses (cloudflare.com/ips): only a request from one of them may say who
     * the visitor is (CF-Connecting-IP); anyone else could write that header.
     */
    public const RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * The zone settings the module recommends for a PrestaShop shop. SSL: "strict" when the
     * server's own certificate is valid for the shop, else "full" (see recommended()).
     */
    public const RECOMMENDED = [
        'ssl' => 'strict',
        'always_use_https' => 'on',
        'automatic_https_rewrites' => 'on',
        'min_tls_version' => '1.2',
        'brotli' => 'on',
        'http3' => 'on',
        'early_hints' => 'on',
        // rewrites every script into its own loader: breaks PrestaShop themes, payment modules and
        // the module's own deferred scripts
        'rocket_loader' => 'off',
        'development_mode' => 'off',
    ];

    /** Cloudflare takes at most 30 addresses a call */
    public const PER_CALL = 30;

    /** the API (tests point it at a mock) */
    public static $api = 'https://api.cloudflare.com/client/v4';

    /** @var array addresses waiting for the end of the request; true = everything */
    protected static $pending = [];
    protected static $all = false;
    protected static $armed = false;

    /* ------------------------------------------------------------------ *
     *  The visitor's own address
     * ------------------------------------------------------------------ */

    /** Whether an address is in one of the ranges (IPv4 and IPv6, CIDR). */
    public static function inRanges($ip, array $ranges = self::RANGES)
    {
        $bin = @inet_pton((string) $ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $range) {
            list($net, $bits) = explode('/', $range);
            $n = @inet_pton($net);
            if ($n === false || strlen($n) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($n, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($n[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Behind Cloudflare every request comes from one of its servers, so PrestaShop sees their
     * addresses: visit statistics, the maintenance-mode allow list and the back office's "check
     * the cookie's IP" (which then signs employees out when Cloudflare changes server) all go
     * wrong. A request from Cloudflare's own ranges gets the visitor's address back from
     * CF-Connecting-IP. @return bool whether it was replaced
     */
    public static function realIp(array &$server = null)
    {
        if ($server === null) {
            $server = &$_SERVER;
        }
        if (Configuration::get(self::K_REALIP) === '0' || empty($server['HTTP_CF_CONNECTING_IP']) || empty($server['REMOTE_ADDR'])) {
            return false;
        }
        $visitor = trim((string) $server['HTTP_CF_CONNECTING_IP']);
        if (!filter_var($visitor, FILTER_VALIDATE_IP) || !self::inRanges($server['REMOTE_ADDR'])) {
            return false;
        }
        $server['SPC_CF_EDGE'] = $server['REMOTE_ADDR'];
        $server['REMOTE_ADDR'] = $visitor;

        return true;
    }

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
        SpcWarm::letVisitorGo();
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

    /* ------------------------------------------------------------------ *
     *  The zone, as Cloudflare sees it (the Cloudflare tab)
     * ------------------------------------------------------------------ */

    /**
     * What Cloudflare says about the zone, kept STATE_TTL seconds (the settings page shows every
     * tab at once; it should not wait for Cloudflare each time).
     *
     * @return array|null at, zone, settings, bots, dns, errors (per question), behind
     */
    public static function state($refresh = false, $base = null)
    {
        if (!self::configured()) {
            return null;
        }
        $kept = json_decode((string) Configuration::get(self::K_STATE), true);
        if (!$refresh && is_array($kept) && isset($kept['at']) && $kept['at'] > time() - self::STATE_TTL) {
            return $kept;
        }
        $errors = [];
        $zone = self::call('GET', '', null);
        if (empty($zone['success'])) {
            $errors['zone'] = self::firstError([$zone]);
        }
        $settings = [];
        $answer = self::call('GET', '/settings', null);
        if (!empty($answer['success']) && is_array($answer['result'])) {
            foreach ($answer['result'] as $row) {
                if (isset($row['id']) && array_key_exists($row['id'], self::RECOMMENDED + ['security_level' => 1])) {
                    $settings[$row['id']] = ['value' => is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value']), 'editable' => !empty($row['editable'])];
                }
            }
        } else {
            $errors['settings'] = self::firstError([$answer]);
        }
        $bots = self::call('GET', '/bot_management', null);
        if (empty($bots['success'])) {
            $errors['bots'] = self::firstError([$bots]);
        }
        $dns = self::call('GET', '/dns_records?per_page=200', null);
        $records = [];
        if (!empty($dns['success']) && is_array($dns['result'])) {
            foreach ($dns['result'] as $r) {
                $records[] = ['type' => (string) $r['type'], 'name' => (string) $r['name'], 'content' => (string) $r['content'], 'proxied' => !empty($r['proxied'])];
            }
        } else {
            $errors['dns'] = self::firstError([$dns]);
        }
        $z = isset($zone['result']) && is_array($zone['result']) ? $zone['result'] : [];
        $state = [
            'at' => time(),
            'zone' => $z ? ['name' => (string) $z['name'], 'status' => (string) $z['status'], 'plan' => isset($z['plan']['name']) ? (string) $z['plan']['name'] : '',
                'name_servers' => isset($z['name_servers']) ? array_values((array) $z['name_servers']) : [],
                'original_name_servers' => isset($z['original_name_servers']) ? array_values((array) $z['original_name_servers']) : [], ] : null,
            'settings' => $settings,
            'bots' => !empty($bots['success']) ? ['fight_mode' => !empty($bots['result']['fight_mode'])] : null,
            'dns' => !empty($dns['success']) ? $records : null,
            'errors' => $errors,
            'behind' => $base ? self::behind($base) : null,
        ];
        Configuration::updateGlobalValue(self::K_STATE, json_encode($state));

        return $state;
    }

    /** Forget what Cloudflare said, so the next look asks again. */
    public static function forget()
    {
        Configuration::updateGlobalValue(self::K_STATE, '');
    }

    /**
     * Whether the shop's own address answers through Cloudflare (a CF-Ray header): the zone may
     * be set up while the domain still points elsewhere (name servers not changed).
     *
     * @return bool|null null when the shop could not be reached from the server
     */
    public static function behind($url)
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
        $head = curl_exec($ch);
        curl_close($ch);
        if ($head === false || $head === '') {
            return null;
        }

        return (bool) preg_match('/^(cf-ray:|server:\s*cloudflare)/mi', (string) $head);
    }

    /**
     * Whether the server's own certificate is valid for the shop's host: asked of the server
     * itself, past Cloudflare. "Full (strict)" needs it; without it Cloudflare answers 526.
     *
     * @return bool|null null when it cannot be told from here
     */
    public static function originCertificateValid($host, $serverIp)
    {
        if (!function_exists('curl_init') || !$host || !filter_var($serverIp, FILTER_VALIDATE_IP) || filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        $ch = curl_init('https://' . $host . '/');
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RESOLVE => [$host . ':443:' . (strpos($serverIp, ':') !== false ? '[' . $serverIp . ']' : $serverIp)], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok !== false) {
            return true;
        }
        // 51, 60, 58...: a certificate problem; a refused connection or a time-out says nothing
        return in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) ? false : null;
    }

    /**
     * The recommended value of a setting. SSL "strict" only when the server's certificate was
     * seen to be valid: with an invalid one Cloudflare answers 526 and the shop is gone, so when
     * it cannot be told, "full" (encrypted, never breaks) and the choice of strict is left to
     * the merchant.
     */
    public static function recommended($id, $certificate)
    {
        if ($id === 'ssl') {
            return $certificate === true ? 'strict' : 'full';
        }

        return isset(self::RECOMMENDED[$id]) ? self::RECOMMENDED[$id] : null;
    }

    /** Changes one zone setting. @return array ok, error */
    public static function setSetting($id, $value)
    {
        $a = self::call('PATCH', '/settings/' . rawurlencode($id), ['value' => $value]);
        self::forget();

        return ['ok' => !empty($a['success']), 'error' => empty($a['success']) ? self::firstError([$a]) : ''];
    }

    /** Bot Fight Mode on or off. */
    public static function botFight($on)
    {
        $a = self::call('PUT', '/bot_management', ['fight_mode' => (bool) $on]);
        self::forget();

        return ['ok' => !empty($a['success']), 'error' => empty($a['success']) ? self::firstError([$a]) : ''];
    }

    /** Everything purged now (the button), and noted as the last purge. */
    public static function purgeNow()
    {
        $a = self::call('POST', '/purge_cache', ['purge_everything' => true]);
        $ok = !empty($a['success']);
        Configuration::updateGlobalValue(self::K_LAST, json_encode(['at' => date('Y-m-d H:i'), 'ok' => $ok, 'calls' => 1, 'error' => $ok ? '' : self::firstError([$a])]));

        return ['ok' => $ok, 'error' => $ok ? '' : self::firstError([$a])];
    }

    /**
     * What is wrong in the zone's DNS for a shop: the shop's own name not going through
     * Cloudflare, and mail sent to a name that does (Cloudflare does not carry mail: the MX
     * target must be a grey-cloud record, or e-mail stops arriving).
     *
     * @return array of [level, code, record name, detail]
     */
    public static function dnsFindings(array $records, $host)
    {
        $out = [];
        $proxied = [];
        $shop = null;
        foreach ($records as $r) {
            if (in_array($r['type'], ['A', 'AAAA', 'CNAME'], true)) {
                if ($r['proxied']) {
                    $proxied[strtolower($r['name'])] = true;
                }
                if (strcasecmp($r['name'], $host) === 0) {
                    $shop = $shop === null ? $r : ($r['proxied'] ? $r : $shop);
                }
            }
        }
        if ($shop === null) {
            $out[] = ['warn', 'no_shop', $host, ''];
        } elseif (!$shop['proxied']) {
            $out[] = ['warn', 'shop_grey', $host, $shop['content']];
        }
        foreach ($records as $r) {
            if ($r['type'] === 'MX' && isset($proxied[strtolower(rtrim($r['content'], '.'))])) {
                $out[] = ['error', 'mx_proxied', $r['name'], rtrim($r['content'], '.')];
            }
        }

        return $out;
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
