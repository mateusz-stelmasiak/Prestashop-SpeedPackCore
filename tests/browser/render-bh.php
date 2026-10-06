<?php
/**
 * The Behaviour tab as the back office shows it: the module's behaviour.tpl rendered with real
 * Smarty and the texts the module hands it (written by tests/php/unit.php to SPC_BH_ADMIN_VARS).
 * The report it asks for is answered by shop.py from tests/php/behaviour.php's real report.
 *
 *   php render-bh.php VARS_JSON > behaviour.html
 */
require getenv('SPC_SMARTY') ?: __DIR__ . '/../vendor/autoload.php';
$module = (getenv('SPC_ROOT') ?: dirname(__DIR__, 2)) . '/speedpackcore';
$vars = json_decode(file_get_contents($argv[1]), true);
$vars['url'] = '/__bh?token=x&spc_ajax=behaviour';
$sm = new Smarty();
$sm->setCompileDir(sys_get_temp_dir() . '/spc-render-' . getmypid());
$sm->registerPlugin('function', 'l', function ($p) { return htmlspecialchars($p['s'], ENT_QUOTES, 'UTF-8'); });
$sm->assign('spc_bh', $vars);
// a little of the back office's own look, so screenshots read like the real page
$bo = 'body{margin:0;padding:20px;background:#eff1f2;font:13px/1.45 "Open Sans",Arial,sans-serif;color:#363a41}'
    . '.panel{padding:16px 18px;border:1px solid #dbe6e9;border-radius:5px;background:#fff}.panel h3{margin:-4px 0 14px;font-size:14px;text-transform:uppercase;color:#555}'
    . '.form-control{height:31px;padding:4px 8px;border:1px solid #bbcdd2;border-radius:3px;background:#fff;font:inherit}.help-block{color:#6c868e}h5{margin:14px 0 4px;font-size:12px;color:#6c868e}'
    . '.close{float:right;border:0;background:none;font-size:22px;cursor:pointer}';
echo '<!doctype html><html><head><meta charset="utf-8"><title>Behaviour</title><style>' . $bo . '</style>'
    . '<link rel="stylesheet" href="/modules/speedpackcore/views/css/behaviour.css"></head><body>'
    . $sm->fetch($module . '/views/templates/admin/behaviour.tpl')
    . '<script src="/modules/speedpackcore/views/js/behaviour-admin.js"></script></body></html>';
