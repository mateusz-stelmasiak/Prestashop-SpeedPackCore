<?php
/**
 * Optimize's back-office panel (optimize.tpl, real Smarty) and two shop pages: one as
 * PrestaShop builds it, one after Optimize's steps (classes/SpcHtml.php). For optimize.e2e.js.
 *
 *   php render-opt.php OUT_DIR
 */
require getenv('SPC_SMARTY') ?: __DIR__ . '/../vendor/autoload.php';
define('_PS_VERSION_', '9.0.0');
$module = (getenv('SPC_ROOT') ?: dirname(__DIR__, 2)) . '/speedpackcore';
require $module . '/classes/SpcHtml.php';
$out = rtrim($argv[1], '/');

$sm = new Smarty();
$sm->setCompileDir(sys_get_temp_dir() . '/spc-render-opt-' . getmypid());
$sm->registerPlugin('function', 'l', function ($p) { return htmlspecialchars($p['s'], ENT_QUOTES, 'UTF-8'); });
$names = ['index' => 'Home page', 'category' => 'Category', 'product' => 'Product', 'cms' => 'CMS page'];
$sm->assign('spc_opt', [
    'url' => '/__opt', 'webp' => true, 'avif' => false, 'server' => 'apache', 'headers' => false, 'nginx' => "gzip on;\n",
    'critical' => array_map(function ($p) use ($names) { return ['page' => $p, 'name' => $names[$p], 'kb' => 0, 'at' => '']; }, array_keys($names)),
    'texts' => json_encode([
        'converting' => 'Converting: %1$d of %2$d images', 'converted' => 'Done: %1$d copies made, %2$s smaller.', 'none' => 'Nothing to convert',
        'generating' => 'Reading %s...', 'generated' => 'Critical CSS made for %d kinds of page.', 'blocked' => 'blocked',
        'tooLarge' => 'too large %1$s %2$s', 'failed' => 'Stopped: %s', 'names' => $names,
    ]),
]);
file_put_contents($out . '/admin-opt.html', '<!doctype html><html><head><meta charset="utf-8"><title>Optimize</title><link rel="stylesheet" href="/modules/speedpackcore/views/css/optimize.css"></head><body>'
    . $sm->fetch($module . '/views/templates/admin/optimize.tpl')
    . '<script src="/modules/speedpackcore/views/js/optimize.js"></script></body></html>');

// a shop page: the first screen (header, hero), a phone-only rule, a rule far below, hover, a font
$page = <<<'HTML'
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><title>Sklep</title>
<link rel="stylesheet" href="/shop-css/theme.css" media="all">
<link rel="stylesheet" href="/shop-css/print.css" media="print">
<script>window.order = [];</script>
</head>
<body>
<header id="header"><a class="btn-top" href="/">Alhambra</a></header>
<section id="wrapper"><div class="hero">Kiszonki</div><div class="gone">ukryte</div>
<div class="far">daleko w dole</div>
<img src="/a.png" alt=""><img src="/b.png" alt=""><img src="/c.png" alt="">
</section>
<script src="/shop-js/one.js"></script>
<script>window.order.push('inline-after-one');</script>
<script src="/shop-js/two.js"></script>
<script type="application/ld+json">{"@type":"Thing"}</script>
<script>window.order.push('inline-last'); window.domReadyAtInline = document.readyState;</script>
</body></html>
HTML;
file_put_contents($out . '/shop-page.html', $page);
$opt = SpcHtml::lazy(SpcHtml::defer($page));
file_put_contents($out . '/shop-page-opt.html', SpcHtml::minify($opt));

// php render-opt.php OUT_DIR critical SAVED_JSON: the shop page with the critical CSS the panel saved
if (isset($argv[2]) && $argv[2] === 'critical') {
    $saved = json_decode(file_get_contents($argv[3]), true);
    file_put_contents($out . '/shop-page-crit.html', SpcHtml::critical($page, $saved['css'], SpcHtml::fingerprint($saved['hrefs'])));
}
