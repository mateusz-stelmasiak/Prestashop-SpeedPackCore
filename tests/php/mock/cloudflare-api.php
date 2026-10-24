<?php
// A stand-in for api.cloudflare.com/client/v4 (one zone): settings changes are kept in a file.
$state = (getenv('CF_MOCK_STATE') ?: sys_get_temp_dir() . '/cf-mock.json');
$s = is_file($state) ? json_decode(file_get_contents($state), true) : [
    'settings' => ['ssl' => 'flexible', 'always_use_https' => 'off', 'automatic_https_rewrites' => 'on', 'min_tls_version' => '1.0', 'brotli' => 'on',
        'http3' => 'off', 'early_hints' => 'off', 'rocket_loader' => 'on', 'development_mode' => 'off', 'security_level' => 'medium', 'polish' => 'off'],
    'fight' => false, 'purges' => 0,
];
$save = function () use (&$s, $state) { file_put_contents($state, json_encode($s)); };
header('Content-Type: application/json');
$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$ok = function ($r) { echo json_encode(['success' => true, 'errors' => [], 'result' => $r]); exit; };
if ($auth !== 'Bearer goodtoken') { http_response_code(403); echo json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true);
if (!preg_match('#^/zones/([a-f0-9]{32})(/.*)?$#', $path, $mm)) { http_response_code(404); echo '{"success":false,"errors":[{"message":"no route"}]}'; exit; }
$rest = isset($mm[2]) ? $mm[2] : '';
if ($rest === '') $ok(['id' => $mm[1], 'name' => 'shop.test', 'status' => getenv('CF_MOCK_STATUS') ?: 'active', 'plan' => ['name' => 'Free Website'], 'name_servers' => ['ana.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'original_name_servers' => ['dns1.nazwa.pl']]);
if ($rest === '/settings' && $m === 'GET') { $out = []; foreach ($s['settings'] as $k => $v) $out[] = ['id' => $k, 'value' => $v, 'editable' => true]; $ok($out); }
if (preg_match('#^/settings/([a-z0-9_]+)$#', $rest, $x) && $m === 'PATCH') { $s['settings'][$x[1]] = $body['value']; $save(); $ok(['id' => $x[1], 'value' => $body['value']]); }
if ($rest === '/bot_management') { if ($m === 'PUT') { $s['fight'] = !empty($body['fight_mode']); $save(); } $ok(['fight_mode' => $s['fight']]); }
if ($rest === '/dns_records') $ok([
    ['type' => 'A', 'name' => 'shop.test', 'content' => '77.55.158.126', 'proxied' => true],
    ['type' => 'A', 'name' => 'www.shop.test', 'content' => '77.55.158.126', 'proxied' => true],
    ['type' => 'MX', 'name' => 'shop.test', 'content' => 'shop.test', 'proxied' => false],
]);
if ($rest === '/purge_cache' && $m === 'POST') { $s['purges']++; $save(); $ok(['id' => 'x']); }
http_response_code(404); echo '{"success":false,"errors":[{"message":"no route ' . $rest . '"}]}';
