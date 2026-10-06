<?php
/**
 * The audit panel as the back office shows it: the module's audit.tpl rendered with real Smarty
 * and the texts the module hands it (written by tests/php/audit.php to SPC_ADMIN_VARS).
 *
 *   php render.php VARS_JSON > admin.html
 */
require getenv('SPC_SMARTY') ?: __DIR__ . '/../vendor/autoload.php';
$module = (getenv('SPC_ROOT') ?: dirname(__DIR__, 2)) . '/speedpackcore';
$vars = json_decode(file_get_contents($argv[1]), true);
$vars['url'] = '/__audit';
$vars['home'] = '/pl/';
$vars['history'] = '[]';
$vars['first'] = true;
$vars['auto'] = false; // shop.py serves /admin-auto with it on
$sm = new Smarty();
$sm->setCompileDir(sys_get_temp_dir() . '/spc-render-' . getmypid());
$sm->registerPlugin('function', 'l', function ($p) { return htmlspecialchars($p['s'], ENT_QUOTES, 'UTF-8'); });
$sm->assign('spc_audit', $vars);
echo '<!doctype html><html><head><meta charset="utf-8"><title>SpeedPack Core</title><link rel="stylesheet" href="/modules/speedpackcore/views/css/audit.css"></head><body>'
    . $sm->fetch($module . '/views/templates/admin/audit.tpl')
    . '<script src="/modules/speedpackcore/views/js/audit.js"></script></body></html>';
