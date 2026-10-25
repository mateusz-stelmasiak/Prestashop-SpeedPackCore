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
    public const UA = 'Mozilla/5.0 (compatible; SpeedPackCore/1.13; agent readiness check)';
    /** a browser's, for a server that turns bots away */
    public const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /** answers of this run: total, refused (403/429/406), the title of a refusal page */
    public static $seen = ['total' => 0, 'refused' => 0, 'title' => ''];

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
        $r = self::fetch($method, $url, $headers, $body, self::UA);
        if (in_array($r[0], [403, 406, 429], true)) {
            // a firewall that turns bots away: once more as a browser
            $again = self::fetch($method, $url, array_merge($headers, ['Accept-Language: pl,en;q=0.8']), $body, self::BROWSER);
            if (!in_array($again[0], [403, 406, 429], true)) {
                $r = $again;
            }
        }
        ++self::$seen['total'];
        if (in_array($r[0], [403, 406, 429], true)) {
            ++self::$seen['refused'];
            if (self::$seen['title'] === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $r[2], $m)) {
                self::$seen['title'] = Tools::substr(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')), 0, 120);
            }
        }

        return $r;
    }

    protected static function fetch($method, $url, array $headers, $body, $ua)
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
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
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
        self::$seen = ['total' => 0, 'refused' => 0, 'title' => ''];
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
            $state = $r[0] ? 'pass' : ($meta[1] ? 'optional' : 'fail');
            // refused from inside the server, but set up on this server: "set up" (the outside check is the scanner's)
            if ($state === 'fail' && preg_match('/HTTP (403|406|429)\b|HTTP 0\b/', $r[1]) && self::local($id)) {
                $state = 'local';
                $r[1] .= ' – set up here; this server refuses to be asked by itself';
            }
            $out[$id] = [$state, $r[1]];
        }

        return $out;
    }

    /** the part is in place on this server (files, settings, routes), whatever HTTP says */
    public static function local($id)
    {
        $root = rtrim(_PS_ROOT_DIR_, '/') . '/';
        $wk = $root . '.well-known/';
        $robots = is_file($root . 'robots.txt') ? (string) @file_get_contents($root . 'robots.txt') : '';
        $routes = SpcAgentSettings::active();
        $on = function ($k) { return SpcAgentSettings::on($k); };
        switch ($id) {
            case 'robots': return $robots !== '';
            case 'sitemap': return stripos($robots, 'Sitemap:') !== false;
            case 'linkHeaders': return $on(SpcAgentSettings::K_LINKS);
            case 'llms': return is_file($root . 'llms.txt');
            case 'markdown': return $on(SpcAgentSettings::K_MARKDOWN);
            case 'contentSignals': return stripos($robots, 'Content-Signal:') !== false;
            case 'webBotAuth': return $routes && class_exists('SpcBotAuth') && SpcBotAuth::ready();
            case 'apiCatalog': return is_file($wk . 'api-catalog');
            case 'openapi': return is_file($root . 'openapi.json');
            case 'mcp': return $routes && is_file($wk . 'mcp/server-card.json');
            case 'a2a': return $routes && is_file($wk . 'agent-card.json');
            case 'skills': return is_file($wk . 'agent-skills/index.json');
            case 'webmcp': return $on(SpcAgentSettings::K_WEBMCP);
            case 'authMd': return is_file($root . 'auth.md');
            case 'ucp': return $routes && is_file($wk . 'ucp') && $on(SpcAgentSettings::K_UCP);
            case 'acp': return $routes && is_file($wk . 'acp.json');
        }

        return false;
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
            $ok += in_array($r[0], ['pass', 'local'], true) ? 1 : 0;
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

                return [false, $r[0] === 200 ? 'robots.txt names no sitemap' : 'robots.txt: HTTP ' . $r[0]];
            case 'linkHeaders':
                $h = $loadHome();
                $link = isset($h[1]['link']) ? $h[1]['link'] : '';

                return [strpos($link, 'rel="api-catalog"') !== false || strpos($link, 'rel="service-desc"') !== false, $link !== '' ? 'Link: ' . Tools::substr($link, 0, 120) : 'no Link header on the home page (HTTP ' . $h[0] . ')'];
            case 'llms':
                $r = self::get('GET', $base . '/llms.txt');

                return [$r[0] === 200 && strpos($r[2], '# ') !== false, '/llms.txt: HTTP ' . $r[0] . ($r[0] === 200 ? ', ' . strlen($r[2]) . ' bytes' : '')];
            case 'markdown':
                $r = self::get('GET', self::$home ?: $base . '/', ['Accept: text/markdown']);
                $ct = isset($r[1]['content-type']) ? $r[1]['content-type'] : '';

                return [$r[0] === 200 && stripos($ct, 'text/markdown') !== false, 'Accept: text/markdown -> HTTP ' . $r[0] . ($ct ? ', ' . $ct : '')];
            case 'contentSignals':
                $r = $loadRobots();

                return [preg_match('/^Content-Signal:\s*(.+)$/mi', $r[2], $m) === 1, isset($m[1]) ? 'Content-Signal: ' . trim($m[1]) : ($r[0] === 200 ? 'no Content-Signal line' : 'robots.txt: HTTP ' . $r[0])];
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

                return [strpos($h[2], 'webmcp') !== false && strpos($h[2], 'spcWebMcp') !== false, strpos($h[2], 'spcWebMcp') !== false ? 'WebMCP tools on the pages' : 'no WebMCP tools on the home page (HTTP ' . $h[0] . ')'];
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
