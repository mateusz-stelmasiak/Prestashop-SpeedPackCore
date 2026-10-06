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
if (getenv('SPC_HTML_OUT')) {
    file_put_contents(getenv('SPC_HTML_OUT'), json_encode(['page' => $page, 'all' => SpcHtml::minify(SpcHtml::critical(SpcHtml::defer(SpcHtml::lazy(SpcHtml::images($page, $resolve))), '#header{background:#123456}', $fp))]));
}
echo "ALL OK\n";
