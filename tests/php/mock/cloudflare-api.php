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
if ($auth !== 'Bearer goodtoken' && $auth !== 'Bearer readtoken') { http_response_code(403); echo json_encode(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true);
if (!preg_match('#^/zones/([a-f0-9]{32})(/.*)?$#', $path, $mm)) { http_response_code(404); echo '{"success":false,"errors":[{"message":"no route"}]}'; exit; }
$rest = isset($mm[2]) ? $mm[2] : '';
if ($rest === '') $ok(['id' => $mm[1], 'name' => 'shop.test', 'status' => getenv('CF_MOCK_STATUS') ?: 'active', 'plan' => ['name' => 'Free Website'], 'name_servers' => ['ana.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'original_name_servers' => ['dns1.nazwa.pl']]);
if ($rest === '/settings' && $m === 'GET') { $out = []; foreach ($s['settings'] as $k => $v) $out[] = ['id' => $k, 'value' => $v, 'editable' => true]; $ok($out); }
if (preg_match('#^/settings/([a-z0-9_]+)$#', $rest, $x) && $m === 'PATCH') { $s['settings'][$x[1]] = $body['value']; $save(); $ok(['id' => $x[1], 'value' => $body['value']]); }
if ($rest === '/bot_management') { if ($m === 'PUT') { $s['fight'] = !empty($body['fight_mode']); $save(); } $ok(['fight_mode' => $s['fight']]); }
if (!isset($s['dns'])) $s['dns'] = [
    ['id' => 'r1', 'type' => 'A', 'name' => 'shop.test', 'content' => '77.55.158.126', 'proxied' => true],
    ['id' => 'r2', 'type' => 'A', 'name' => 'www.shop.test', 'content' => '77.55.158.126', 'proxied' => true],
    ['id' => 'r3', 'type' => 'MX', 'name' => 'shop.test', 'content' => 'shop.test', 'proxied' => false],
];
// SVCB as Cloudflare shows it: "priority target value", numbers quoted
$content = function ($b) { return isset($b['data']) ? $b['data']['priority'] . ' ' . $b['data']['target'] . ' ' . preg_replace('/port=(\d+)/', 'port="$1"', $b['data']['value']) : $b['content']; };
if ($rest === '/dns_records' && $m === 'GET') $ok($s['dns']);
if ($rest === '/dns_records' && $m === 'POST') {
    if ($auth === 'Bearer readtoken') { // a token without DNS Edit
    http_response_code(403); echo '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}'; exit; }
    $r = ['id' => 'r' . (count($s['dns']) + 1), 'type' => $body['type'], 'name' => $body['name'], 'content' => $content($body), 'proxied' => false];
    $s['dns'][] = $r; $s['dns_writes'] = (isset($s['dns_writes']) ? $s['dns_writes'] : 0) + 1; $save(); $ok($r);
}
if (preg_match('#^/dns_records/(r\d+)$#', $rest, $x) && $m === 'PUT') {
    foreach ($s['dns'] as &$r) { if ($r['id'] === $x[1]) { $r['content'] = $content($body); } } unset($r);
    $s['dns_writes'] = (isset($s['dns_writes']) ? $s['dns_writes'] : 0) + 1; $save(); $ok(['id' => $x[1]]);
}
if ($rest === '/purge_cache' && $m === 'POST') { $s['purges']++; $save(); $ok(['id' => 'x']); }
http_response_code(404); echo '{"success":false,"errors":[{"message":"no route ' . $rest . '"}]}';
