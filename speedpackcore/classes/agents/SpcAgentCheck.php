<?php
/**
 * SpeedPack Core - AI agents: the shop checked from the outside, the way an agent-readiness
 * scanner (isitagentready) does it: each address asked over HTTP, each answer read. Nothing is
 * taken on trust from the settings: a check passes only when the shop really answers.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcAgentCheck
{
    public const UA = 'SpeedPackCore/1.13 (+agent readiness check)';

    /** id => [group, optional (not in the score)] in the order shown */
    public const CHECKS = [
        'robots' => ['discoverability', false], 'sitemap' => ['discoverability', false], 'linkHeaders' => ['discoverability', false],
        'llms' => ['content', false], 'markdown' => ['content', false],
        'contentSignals' => ['bots', false], 'webBotAuth' => ['bots', false],
        'apiCatalog' => ['discovery', false], 'openapi' => ['discovery', false], 'mcp' => ['discovery', false], 'a2a' => ['discovery', false],
        'skills' => ['discovery', false], 'webmcp' => ['discovery', false], 'authMd' => ['discovery', false],
        'ucp' => ['commerce', false], 'acp' => ['commerce', false], 'dnsAid' => ['commerce', true],
    ];

    /** test hook: a fake fetcher function($method, $url, $headers, $body) => [status, headers, body] */
    public static $fetch = null;

    /** the home page's address for this run */
    protected static $home = '';

    public static function base(Context $context)
    {
        return rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/');
    }

    /** @return array [status, headers (lowercase name => value), body] */
    public static function get($method, $url, array $headers = [], $body = null)
    {
        if (self::$fetch) {
            return call_user_func(self::$fetch, $method, $url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            return [0, [], ''];
        }
        $h = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => self::UA, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) {
                if (strpos($line, ':') !== false) {
                    list($k, $v) = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    $h[$k] = isset($h[$k]) ? $h[$k] . ', ' . trim($v) : trim($v);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $b = curl_exec($ch);
        $s = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$s, $h, $b === false ? '' : (string) $b];
    }

    protected static function json($body)
    {
        $j = json_decode((string) $body, true);

        return is_array($j) ? $j : null;
    }

    /**
     * Every check, or the ones named.
     *
     * @return array id => [state pass|fail|optional, detail]
     */
    public static function run(Context $context, array $only = [])
    {
        $base = self::base($context);
        // the home page as visitors reach it (a shop in several languages redirects / to /pl/)
        self::$home = $context->link->getPageLink('index', true);
        $out = [];
        $home = null;
        $robots = null;
        foreach (self::CHECKS as $id => $meta) {
            if ($only && !in_array($id, $only, true)) {
                continue;
            }
            try {
                $r = self::one($id, $base, $context, $home, $robots);
            } catch (Exception $e) {
                $r = [false, $e->getMessage()];
            }
            $out[$id] = [$r[0] ? 'pass' : ($meta[1] ? 'optional' : 'fail'), $r[1]];
        }

        return $out;
    }

    /** passed / counted, from a run() result */
    public static function score(array $res)
    {
        $n = 0;
        $ok = 0;
        foreach ($res as $id => $r) {
            if (!empty(self::CHECKS[$id][1])) {
                continue;
            }
            ++$n;
            $ok += $r[0] === 'pass' ? 1 : 0;
        }

        return [$ok, $n];
    }

    /** @return array [passed, what was seen] */
    protected static function one($id, $base, Context $context, &$home, &$robots)
    {
        $loadHome = function () use (&$home, $base) {
            if ($home === null) {
                $home = self::get('GET', self::$home ?: $base . '/', ['Accept: text/html']);
            }

            return $home;
        };
        $loadRobots = function () use (&$robots, $base) {
            if ($robots === null) {
                $robots = self::get('GET', $base . '/robots.txt');
            }

            return $robots;
        };
        switch ($id) {
            case 'robots':
                $r = $loadRobots();

                return [$r[0] === 200 && stripos($r[2], 'user-agent') !== false, 'robots.txt: HTTP ' . $r[0]];
            case 'sitemap':
                $r = $loadRobots();
                if (preg_match('/^Sitemap:\s*(\S+)/mi', $r[2], $m)) {
                    $s = self::get('GET', $m[1]);

                    return [$s[0] === 200, 'Sitemap in robots.txt: ' . $m[1] . ' (HTTP ' . $s[0] . ')'];
                }

                return [false, 'robots.txt names no sitemap'];
            case 'linkHeaders':
                $h = $loadHome();
                $link = isset($h[1]['link']) ? $h[1]['link'] : '';

                return [strpos($link, 'rel="api-catalog"') !== false || strpos($link, 'rel="service-desc"') !== false, $link !== '' ? 'Link: ' . Tools::substr($link, 0, 120) : 'no Link header on the home page'];
            case 'llms':
                $r = self::get('GET', $base . '/llms.txt');

                return [$r[0] === 200 && strpos($r[2], '# ') !== false, '/llms.txt: HTTP ' . $r[0] . ($r[0] === 200 ? ', ' . strlen($r[2]) . ' bytes' : '')];
            case 'markdown':
                $r = self::get('GET', self::$home ?: $base . '/', ['Accept: text/markdown']);
                $ct = isset($r[1]['content-type']) ? $r[1]['content-type'] : '';

                return [$r[0] === 200 && stripos($ct, 'text/markdown') !== false, 'Accept: text/markdown -> ' . ($ct ?: 'HTTP ' . $r[0])];
            case 'contentSignals':
                $r = $loadRobots();

                return [preg_match('/^Content-Signal:\s*(.+)$/mi', $r[2], $m) === 1, isset($m[1]) ? 'Content-Signal: ' . trim($m[1]) : 'no Content-Signal line'];
            case 'webBotAuth':
                $r = self::get('GET', $base . '/.well-known/http-message-signatures-directory');
                $ct = isset($r[1]['content-type']) ? $r[1]['content-type'] : '';
                $j = self::json($r[2]);

                return [$r[0] === 200 && stripos($ct, 'http-message-signatures-directory') !== false && !empty($j['keys']) && !empty($r[1]['signature']), 'key directory: HTTP ' . $r[0] . ($ct ? ', ' . $ct : '')];
            case 'apiCatalog':
                $r = self::get('GET', $base . '/.well-known/api-catalog');
                $j = self::json($r[2]);

                return [$r[0] === 200 && !empty($j['linkset']), '/.well-known/api-catalog: HTTP ' . $r[0] . (isset($r[1]['content-type']) ? ', ' . $r[1]['content-type'] : '')];
            case 'openapi':
                $r = self::get('GET', $base . '/openapi.json');
                $j = self::json($r[2]);

                return [$r[0] === 200 && isset($j['openapi']), '/openapi.json: HTTP ' . $r[0]];
            case 'mcp':
                $card = self::get('GET', $base . '/.well-known/mcp/server-card.json');
                $c = self::json($card[2]);
                $endpoint = isset($c['endpoint']) ? $c['endpoint'] : $base . '/mcp';
                $r = self::get('POST', $endpoint, ['Content-Type: application/json', 'Accept: application/json, text/event-stream'],
                    json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
                $j = self::json($r[2]);

                return [$card[0] === 200 && !empty($j['result']['tools']), 'server card: HTTP ' . $card[0] . ', tools/list: ' . (isset($j['result']['tools']) ? count($j['result']['tools']) . ' tools' : 'HTTP ' . $r[0])];
            case 'a2a':
                $card = self::get('GET', $base . '/.well-known/agent-card.json');
                $c = self::json($card[2]);
                if (empty($c['url'])) {
                    return [false, 'agent card: HTTP ' . $card[0]];
                }
                $r = self::get('POST', $c['url'], ['Content-Type: application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'message/send',
                    'params' => ['message' => ['role' => 'user', 'messageId' => 'check', 'parts' => [['kind' => 'text', 'text' => 'hello']]]], ]));
                $j = self::json($r[2]);

                return [!empty($j['result']['parts']), 'agent card: ' . $c['name'] . ', message/send: ' . (!empty($j['result']) ? 'answered' : 'HTTP ' . $r[0])];
            case 'skills':
                $r = self::get('GET', $base . '/.well-known/agent-skills/index.json');
                $j = self::json($r[2]);

                return [$r[0] === 200 && !empty($j['skills']), 'skills index: HTTP ' . $r[0] . (isset($j['skills']) ? ', ' . count($j['skills']) . ' skill' : '')];
            case 'webmcp':
                $h = $loadHome();

                return [strpos($h[2], 'webmcp') !== false && strpos($h[2], 'spcWebMcp') !== false, strpos($h[2], 'spcWebMcp') !== false ? 'WebMCP tools on the pages' : 'no WebMCP tools on the home page'];
            case 'authMd':
                $r = self::get('GET', $base . '/auth.md');

                return [$r[0] === 200 && strpos($r[2], '# ') === 0, '/auth.md: HTTP ' . $r[0]];
            case 'ucp':
                $r = self::get('GET', $base . '/.well-known/ucp');
                $j = self::json($r[2]);
                $buy = self::get('GET', $base . '/buy');

                return [$r[0] === 200 && isset($j['ucp']['capabilities']) && in_array($buy[0], [302, 303], true), '/.well-known/ucp: HTTP ' . $r[0] . ', /buy: HTTP ' . $buy[0]];
            case 'acp':
                $r = self::get('GET', $base . '/.well-known/acp.json');
                $j = self::json($r[2]);
                if (empty($j['api_base_url'])) {
                    return [false, '/.well-known/acp.json: HTTP ' . $r[0]];
                }
                $carts = self::get('POST', $j['api_base_url'] . '/carts', ['Content-Type: application/json'], '{}');

                return [in_array($carts[0], [400, 401], true), 'acp.json: ' . implode(', ', isset($j['capabilities']['services']) ? $j['capabilities']['services'] : []) . ', carts without a key: HTTP ' . $carts[0]];
            case 'dnsAid':
                $host = (string) parse_url($base, PHP_URL_HOST);
                $r = self::get('GET', 'https://cloudflare-dns.com/dns-query?name=' . rawurlencode('_mcp._agents.' . $host) . '&type=SVCB', ['Accept: application/dns-json']);
                $j = self::json($r[2]);

                return [!empty($j['Answer']), !empty($j['Answer']) ? 'SVCB at _mcp._agents.' . $host : 'no SVCB record at _mcp._agents.' . $host . ' (Cloudflare tab can write it)'];
        }

        return [false, 'unknown check'];
    }
}
