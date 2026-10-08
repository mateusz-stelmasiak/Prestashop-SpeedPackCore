<?php
require __DIR__ . '/bootstrap.php';
// Optimize's page transforms on a page shaped like PrestaShop's Classic theme: pictures to WebP,
// lazy loading, deferred scripts, critical CSS and minifying, and that each leaves alone what it
// must not touch.
define('_PS_VERSION_', '9.0.0');
require SPC_MODULE . '/classes/SpcHtml.php';

$page = <<<'HTML'
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <!-- theme head -->
  <link rel="stylesheet" href="https://shop.test/themes/classic/assets/cache/theme-1a2b3c.css" type="text/css" media="all">
  <link rel="stylesheet" href="/modules/ps_searchbar/ps_searchbar.css?v=2" media="all">
  <link rel="stylesheet" href="/print.css" media="print">
  <script type="text/javascript">var prestashop = {"page":{"page_name":"product"}};</script>
  <script type="application/ld+json">{"@type":"Product","image":"https://shop.test/12-large_default/kimchi.jpg"}</script>
</head>
<body id="product" class="product-id-7">
  <header id="header">
    <img class="logo img-fluid" src="https://shop.test/img/logo.png" alt="Alhambra">
    <img src="https://shop.test/img/flag.png" alt="pl">
  </header>
  <section id="wrapper">
    <div class="product-cover">
      <img class="js-qv-product-cover img-fluid" src="https://shop.test/12-large_default/kimchi.jpg" alt="Kimchi">
    </div>
    <ul class="product-images">
      <li><img class="thumb" data-image-large-src="https://shop.test/12-large_default/kimchi.jpg" src="https://shop.test/12-small_default/kimchi.jpg" alt=""></li>
      <li><img class="thumb" src="https://shop.test/13-small_default/kimchi.jpg" srcset="https://shop.test/13-small_default/kimchi.jpg 1x, https://shop.test/13-medium_default/kimchi.jpg 2x" alt=""></li>
      <li><img class="thumb" src="https://cdn.other.test/14-small_default/x.jpg" alt=""></li>
      <li><img class="thumb" src="/img/c/3-category_default.jpg" alt="" loading="eager"></li>
    </ul>
    <iframe src="https://www.youtube.com/embed/x"></iframe>
    <pre>  keep   these   spaces  </pre>
    <textarea name="m">  and   these  </textarea>
    <p>A   text   with   spaces</p>
    <!-- a comment -->
    <!--[if lt IE 9]><p>old</p><![endif]-->
    <script type="text/template"><img src="https://shop.test/12-small_default/kimchi.jpg"></script>
    <script>window.order = (window.order || []).concat('in-content');</script>
  </section>
  <script type="text/javascript" src="/themes/classic/assets/cache/bottom-1.js"></script>
  <script type="text/javascript">window.order = (window.order || []).concat('inline-after');</script>
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>
  <script src="/modules/speedpackcore/views/js/instantnav.min.js" defer></script>
  <script type="application/ld+json">{"@type":"BreadcrumbList"}</script>
</body>
</html>
HTML;

// ---------- pictures
$exists = ['12-large_default', '12-small_default', '13-small_default', '13-medium_default', 'c/3-category_default'];
$resolve = function ($url) use ($exists) {
    if (preg_match('#^https://shop\.test/(\d+)(-[a-z_]+)/[^/]+\.jpg$#', $url, $m) && in_array($m[1] . $m[2], $exists, true)) {
        return 'https://shop.test/img/p/' . implode('/', str_split($m[1])) . '/' . $m[1] . $m[2] . '.webp';
    }
    if (preg_match('#^/img/c/(\d+-[a-z_]+)\.jpg$#', $url, $m) && in_array('c/' . $m[1], $exists, true)) {
        return '/img/c/' . $m[1] . '.webp';
    }
    return null;
};
$img = SpcHtml::images($page, $resolve);
ok(strpos($img, 'class="js-qv-product-cover img-fluid" src="https://shop.test/img/p/1/2/12-large_default.webp"') !== false, 'the product picture as WebP (its real file, which every PrestaShop version serves)');
ok(strpos($img, 'data-image-large-src="https://shop.test/img/p/1/2/12-large_default.webp" src="https://shop.test/img/p/1/2/12-small_default.webp"') !== false, 'thumbnails and the large picture they open');
ok(strpos($img, 'srcset="https://shop.test/img/p/1/3/13-small_default.webp 1x, https://shop.test/img/p/1/3/13-medium_default.webp 2x"') !== false, 'srcset, each address with its size');
ok(strpos($img, 'src="https://cdn.other.test/14-small_default/x.jpg"') !== false && strpos($img, 'src="/img/c/3-category_default.webp"') !== false, 'another site left alone; a category picture by its path');
ok(strpos($img, 'src="https://shop.test/img/logo.png"') !== false, 'pictures with no WebP copy left alone');
ok(strpos($img, '"image":"https://shop.test/12-large_default/kimchi.jpg"') !== false && strpos($img, '<script type="text/template"><img src="https://shop.test/12-small_default/kimchi.jpg">') !== false, 'nothing inside scripts (structured data, templates) or the head');

// ---------- lazy
$lz = SpcHtml::lazy($page, 2);
ok(strpos($lz, '<img class="logo img-fluid" src') !== false && strpos($lz, '<img src="https://shop.test/img/flag.png"') !== false, 'the header pictures load at once');
ok(strpos($lz, '<img fetchpriority="high" class="js-qv-product-cover') !== false, 'the main product picture is asked for first');
$lzz = SpcHtml::lazy(str_replace('<img class="js-qv-product-cover', '<img loading="lazy" class="js-qv-product-cover', $page));
ok(preg_match('#<img fetchpriority="high"[^>]*loading="eager"[^>]*js-qv-product-cover|<img fetchpriority="high" loading="eager" class="js-qv-product-cover#', $lzz) && !preg_match('#loading="lazy"[^>]*js-qv-product-cover|js-qv-product-cover[^>]*loading="lazy"#', $lzz), 'a main picture the theme made lazy is loaded at once');
ok(strpos($lz, '<img class="thumb" data-image-large-src') !== false && strpos($lz, '<img class="thumb" src="https://shop.test/13-small') !== false, 'the first two pictures of the content load at once');
ok(strpos($lz, '<img loading="lazy" decoding="async" class="thumb" src="https://cdn.other.test') !== false, 'the ones below load as they come into view');
ok(strpos($lz, 'alt="" loading="eager">') !== false && substr_count($lz, 'loading="lazy"') === 2, 'a picture that says how to load is left as it is');
ok(strpos($lz, '<iframe loading="lazy" src="https://www.youtube.com') !== false, 'frames below the top load as they come into view');

// ---------- defer
$df = SpcHtml::defer($page);
ok(strpos($df, '<script defer type="text/javascript" src="/themes/classic/assets/cache/bottom-1.js">') !== false, 'the theme script at the end of the page waits for the page');
ok(strpos($df, '<script defer src="data:text/javascript;charset=utf-8;base64,' . base64_encode("window.order = (window.order || []).concat('inline-after');")) !== false, 'the inline script after it waits too, as a deferred script of its own, so it still runs after it');
ok(strpos($df, '<script>window.order = (window.order || []).concat(\'in-content\');</script>') !== false, 'an inline script before the first external one runs in place');
ok(strpos($df, '<script async src="https://www.googletagmanager.com') !== false && strpos($df, 'instantnav.min.js" defer></script>') !== false && substr_count($df, '<script type="application/ld+json">') === 2, 'async, deferred and data scripts are left alone');
ok(strpos($df, '<script type="text/javascript">var prestashop') !== false, 'the head is left alone');
ok(SpcHtml::defer($df) === $df, 'running it twice changes nothing');
$dw = str_replace("concat('inline-after');", "concat('inline-after'); document.write('x');", $page);
ok(SpcHtml::defer($dw) === $dw, 'a page with document.write after the scripts is left as it is');

// ---------- critical CSS
$sheets = SpcHtml::stylesheets($page);
ok(array_column($sheets, 'href') === ['https://shop.test/themes/classic/assets/cache/theme-1a2b3c.css', '/modules/ps_searchbar/ps_searchbar.css?v=2'], 'the head stylesheets (print left out)');
$fp = SpcHtml::fingerprint(['/themes/classic/assets/cache/theme-1a2b3c.css', 'https://elsewhere.test/modules/ps_searchbar/ps_searchbar.css?v=2']);
ok($fp === SpcHtml::fingerprint(array_column($sheets, 'href')), 'the fingerprint ignores the address the CSS was made from (scheme, host)');
ok($fp === SpcHtml::fingerprint(['/themes/classic/assets/cache/theme-1a2b3c.css', '/modules/ps_searchbar/ps_searchbar.css?v=3']), 'and the version numbers (?v=), so a module update does not switch it off');
$old = SpcHtml::fingerprint(array_column($sheets, 'href'), true);
ok($old !== $fp && strpos(SpcHtml::critical($page, '#header{display:flex}', $old), 'spc-critical') !== false, 'CSS made before (fingerprint with the version numbers) still used');
$cr = SpcHtml::critical($page, '#header{display:flex}</style><script>x</script>', $fp);
ok(strpos($cr, '<style id="spc-critical">#header{display:flex}<\/style><script>x</script></style><link rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'" href="https://shop.test/themes/classic/assets/cache/theme-1a2b3c.css"') !== false, 'the critical CSS inline (unable to close its own tag), the stylesheet preloaded');
ok(substr_count($cr, '<noscript><link rel="stylesheet"') === 2 && strpos($cr, 'href="/print.css" media="print">') !== false, 'a <noscript> copy of each; print left alone');
ok(SpcHtml::critical($page, '#header{}', sha1('other')) === $page && SpcHtml::critical($page, '', $fp) === $page, 'CSS made for other stylesheets (the theme changed), or none: the page as it is');

// ---------- minify
$mn = SpcHtml::minify($page);
ok(strpos($mn, '<pre>  keep   these   spaces  </pre>') !== false && strpos($mn, '<textarea name="m">  and   these  </textarea>') !== false, 'pre and textarea keep their spaces');
ok(strpos($mn, 'A text with spaces') !== false && strpos($mn, 'a comment') === false && strpos($mn, '<!--[if lt IE 9]>') !== false, 'runs of spaces and comments out; conditional comments kept');
ok(strlen($mn) < strlen($page) && strpos($mn, "concat('inline-after');") !== false, 'smaller, scripts untouched');

// ---------- all together, on a page with nothing to do
$plain = '<html><head></head><body><p>x</p></body></html>';
ok(SpcHtml::lazy($plain) === $plain && SpcHtml::defer($plain) === $plain && SpcHtml::images($plain, $resolve) === $plain, 'a page with nothing to do comes back as it was');
// --- third-party scripts delayed until the visitor moves
$tp = '<html><head><title>t</title><script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
    . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("config","G-1");</script>'
    . '<script type="application/ld+json">{"x":"gtag("}</script></head><body><script src="/themes/core.js"></script><script>var prestashop={};</script>'
    . '<script src="https://connect.facebook.net/en_US/fbevents.js" id="fb" async></script><script src="https://js.stripe.com/v3/"></script></body></html>';
$dl = SpcHtml::delay($tp, ['googletagmanager.com', 'gtag(', 'connect.facebook.net'], 10);
ok(substr_count($dl, '<script type="spc/delay"') === 3 && strpos($dl, 'data-spc-src="https://www.googletagmanager.com/gtag/js?id=G-1"') !== false && strpos($dl, 'data-spc-src="https://connect.facebook.net/en_US/fbevents.js" id="fb"') !== false, 'trackers by address and by code wait, their other attributes kept');
ok(strpos($dl, '<script src="/themes/core.js"></script><script>var prestashop={};</script>') !== false && strpos($dl, '<script src="https://js.stripe.com/v3/"></script>') !== false && strpos($dl, '<script type="application/ld+json">{"x":"gtag("}</script>') !== false, 'the shop own scripts, payment and data blocks run as before');
ok(strpos($dl, '<head><script>window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};</script>') !== false, 'a stand-in gtag() first in the head (a cookie banner may call it before the visitor moves)');
ok(substr_count($dl, 'id="spc-delay"') === 1 && strpos($dl, 'setTimeout(run,10000)') !== false && strpos(SpcHtml::delay($tp, ['connect.facebook.net'], 0), 'setTimeout(run') === false, 'one loader at the end, with the time limit (none for 0)');
ok(SpcHtml::delay($tp, ['nothing-like-this'], 10) === $tp && SpcHtml::delay($tp, [], 10) === $tp, 'nothing matching: the page as it was');
$both = SpcHtml::defer($dl);
ok(substr_count($both, '<script type="spc/delay"') === 3 && strpos($both, '<script defer src="/themes/core.js">') !== false, 'deferring leaves the delayed scripts alone');
// the loader itself, run in a tiny DOM: delayed scripts recreated in their order
if (getenv('SPC_HTML_DELAY_OUT')) {
    file_put_contents(getenv('SPC_HTML_DELAY_OUT'), $dl);
}

// --- picture sizes, fonts, CDN
$pg = '<html><head><link rel="stylesheet" href="https://shop.test/themes/classic/assets/css/theme.css?v=1"><link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Manrope">'
    . '<style id="spc-critical">@font-face{font-family:M;src:url("https://shop.test/themes/c/m.woff2") format("woff2")}@font-face{font-family:N;font-display:block;src:url(/n.woff2)}#a > b{x:y}</style>'
    . '<link rel="canonical" href="https://shop.test/img/x.jpg"></head><body><img src="https://shop.test/12-home_default/kimchi.jpg" alt=""><img src="/img/logo.png" width="10"><img src="https://other.com/a.jpg">'
    . '<div style="background:url(/img/bg.jpg)"></div><a href="https://shop.test/img/p/1.jpg">x</a><script src="https://shop.test/themes/core.js"></script><script>var u="https://shop.test/modules/x/ajax.js";</script></body></html>';
$dim = SpcHtml::dimensions($pg, function ($u) { return strpos($u, 'home_default') ? [250, 250] : null; });
ok(strpos($dim, '<img width="250" height="250" data-spc-dim src="https://shop.test/12-home_default/kimchi.jpg"') !== false && strpos($dim, '<img src="/img/logo.png" width="10">') !== false && strpos($dim, '<img src="https://other.com/a.jpg">') !== false, 'picture sizes: added where known, a picture with a size of its own and unknown ones left alone');
ok(substr_count($dim, 'img[data-spc-dim]{height:auto}') === 1 && SpcHtml::dimensions('<html><head></head><body><img src="x.jpg"></body></html>', function () { return null; }) === '<html><head></head><body><img src="x.jpg"></body></html>', 'with the rule that keeps their shape; nothing known: the page as it was');
$fo = SpcHtml::fonts($pg);
ok(strpos($fo, 'family=Manrope&amp;display=swap') !== false && strpos($fo, '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>') !== false, 'Google Fonts: display=swap and an early connection');
ok(strpos($fo, '@font-face{font-display: swap; font-family:M;') !== false && strpos($fo, 'font-display:block') !== false && strpos($fo, '<link rel="preload" href="https://shop.test/themes/c/m.woff2" as="font" type="font/woff2" crossorigin>') !== false && strpos($fo, '#a > b{x:y}') !== false, 'critical CSS fonts: swap added (one that chose its own kept), the WOFF2 files preloaded, the rest untouched');
$cd = SpcHtml::cdn($pg, 'https://shop.test', 'https://cdn.test/');
ok(strpos($cd, 'href="https://cdn.test/themes/classic/assets/css/theme.css?v=1"') !== false && strpos($cd, 'src="https://cdn.test/12-home_default/kimchi.jpg"') !== false && strpos($cd, 'src="https://cdn.test/img/logo.png"') !== false && strpos($cd, 'url(https://cdn.test/img/bg.jpg)') !== false && strpos($cd, '<script src="https://cdn.test/themes/core.js">') !== false, 'CDN: stylesheets, pictures (friendly addresses too), inline styles and script files');
ok(strpos($cd, 'href="https://shop.test/img/x.jpg"') !== false && strpos($cd, '<a href="https://shop.test/img/p/1.jpg">') !== false && strpos($cd, 'var u="https://shop.test/modules/x/ajax.js"') !== false && strpos($cd, 'src="https://other.com/a.jpg"') !== false && strpos($cd, 'url("https://shop.test/themes/c/m.woff2")') !== false, 'never: page links, what scripts say, other sites, fonts');
ok(SpcHtml::cdn($pg, 'https://shop.test', '') === $pg && SpcHtml::cdn($pg, 'https://shop.test', 'ftp://x') === $pg, 'no CDN (or not a web address): the page as it was');

if (getenv('SPC_HTML_OUT')) {
    file_put_contents(getenv('SPC_HTML_OUT'), json_encode(['page' => $page, 'all' => SpcHtml::minify(SpcHtml::critical(SpcHtml::defer(SpcHtml::lazy(SpcHtml::images($page, $resolve))), '#header{background:#123456}', $fp))]));
}
echo "ALL OK\n";
