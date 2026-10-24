<?php
require __DIR__ . '/bootstrap.php';
// The Cloudflare tab's workings: visitors' own addresses, the DNS look, the advice, and the API
// (through tests/php/mock/cloudflare-api.php on the port given).
define('_PS_VERSION_', '1.7.8.0');
class Configuration { static $v = []; static function get($k) { return isset(self::$v[$k]) ? self::$v[$k] : false; } static function updateValue($k, $x) { self::$v[$k] = $x; return true; } static function updateGlobalValue($k, $x) { return self::updateValue($k, $x); } }
class SpcWarm { static function letVisitorGo() {} }
require SPC_MODULE . '/classes/SpcCloudflare.php';
$port = isset($argv[1]) ? (int) $argv[1] : 0;

// --- the visitor's own address
ok(SpcCloudflare::inRanges('172.70.1.2') && SpcCloudflare::inRanges('104.16.0.1') && SpcCloudflare::inRanges('2606:4700:10::6816:1'), 'Cloudflare addresses (IPv4 and IPv6) are recognised');
ok(!SpcCloudflare::inRanges('172.80.0.1') && !SpcCloudflare::inRanges('8.8.8.8') && !SpcCloudflare::inRanges('2001:db8::1') && !SpcCloudflare::inRanges('nonsense'), 'others are not (172.80 is outside 172.64.0.0/13)');
$srv = ['REMOTE_ADDR' => '162.158.10.20', 'HTTP_CF_CONNECTING_IP' => '83.21.4.5'];
ok(SpcCloudflare::realIp($srv) && $srv['REMOTE_ADDR'] === '83.21.4.5' && $srv['SPC_CF_EDGE'] === '162.158.10.20', 'from Cloudflare: the visitor\'s own address replaces Cloudflare\'s server');
$srv = ['REMOTE_ADDR' => '91.200.1.1', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'];
ok(!SpcCloudflare::realIp($srv) && $srv['REMOTE_ADDR'] === '91.200.1.1', 'anyone else sending CF-Connecting-IP is ignored (no faking an address)');
$srv = ['REMOTE_ADDR' => '162.158.10.20', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4, 5.6.7.8'];
ok(!SpcCloudflare::realIp($srv), 'a header that is not one address is ignored');
Configuration::$v[SpcCloudflare::K_REALIP] = '0';
$srv = ['REMOTE_ADDR' => '162.158.10.20', 'HTTP_CF_CONNECTING_IP' => '83.21.4.5'];
ok(!SpcCloudflare::realIp($srv) && $srv['REMOTE_ADDR'] === '162.158.10.20', 'switched off: left as it is');
unset(Configuration::$v[SpcCloudflare::K_REALIP]);

// --- the DNS look
$f = SpcCloudflare::dnsFindings([['type' => 'A', 'name' => 'shop.test', 'content' => '1.2.3.4', 'proxied' => true], ['type' => 'MX', 'name' => 'shop.test', 'content' => 'shop.test', 'proxied' => false]], 'shop.test');
ok(count($f) === 1 && $f[0][1] === 'mx_proxied', 'mail sent to a proxied name is an error (mail would stop)');
$f = SpcCloudflare::dnsFindings([['type' => 'A', 'name' => 'shop.test', 'content' => '1.2.3.4', 'proxied' => false], ['type' => 'A', 'name' => 'mail.shop.test', 'content' => '1.2.3.4', 'proxied' => false], ['type' => 'MX', 'name' => 'shop.test', 'content' => 'mail.shop.test', 'proxied' => false]], 'shop.test');
ok(count($f) === 1 && $f[0][1] === 'shop_grey', 'the shop "DNS only" is a warning; MX to a grey record is fine');
ok(SpcCloudflare::dnsFindings([], 'shop.test')[0][1] === 'no_shop', 'no record for the shop: said so');

// --- the advice
ok(SpcCloudflare::recommended('ssl', true) === 'strict' && SpcCloudflare::recommended('ssl', false) === 'full' && SpcCloudflare::recommended('ssl', null) === 'full', 'SSL "strict" only with a certificate seen to be valid (else 526 takes the shop down)');
ok(SpcCloudflare::recommended('rocket_loader', null) === 'off' && SpcCloudflare::recommended('nope', null) === null, 'Rocket Loader off; unknown settings have no advice');

if (!$port) { echo "ok  (API part skipped: no mock port)\n"; exit; }
// --- the API, through the stand-in
SpcCloudflare::$api = 'http://127.0.0.1:' . $port;
Configuration::$v[SpcCloudflare::K_ZONE] = '0123456789abcdef0123456789abcdef';
Configuration::$v[SpcCloudflare::K_TOKEN] = 'badtoken';
$st = SpcCloudflare::state(true);
ok($st['zone'] === null && isset($st['errors']['zone']) && strpos($st['errors']['zone'], 'Authentication') !== false, 'a wrong token: each question says Cloudflare refused it');
Configuration::$v[SpcCloudflare::K_TOKEN] = 'goodtoken';
$st = SpcCloudflare::state(true);
ok($st['zone']['status'] === 'active' && $st['zone']['name_servers'][0] === 'ana.ns.cloudflare.com' && !$st['errors'], 'the zone, its status and name servers');
ok($st['settings']['ssl']['value'] === 'flexible' && $st['settings']['rocket_loader']['value'] === 'on' && !isset($st['settings']['polish']), 'the settings the tab looks at, and only those');
ok($st['bots']['fight_mode'] === false && count($st['dns']) === 3, 'Bot Fight Mode and the DNS records');
ok(SpcCloudflare::state()['at'] === $st['at'], 'kept a while: the settings page does not wait for Cloudflare each time');
$r = SpcCloudflare::setSetting('ssl', 'full');
ok($r['ok'] && Configuration::get(SpcCloudflare::K_STATE) === '' && SpcCloudflare::state()['settings']['ssl']['value'] === 'full', 'a setting changed at Cloudflare, then asked again');
ok(SpcCloudflare::botFight(true)['ok'] && SpcCloudflare::state()['bots']['fight_mode'] === true, 'Bot Fight Mode switched on');
ok(SpcCloudflare::purgeNow()['ok'] && json_decode(Configuration::get(SpcCloudflare::K_LAST), true)['ok'], 'a purge now, noted as the last one');
