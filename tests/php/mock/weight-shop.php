<?php
// A shop for the module weight test: two pages and their files.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$head = '<link rel="stylesheet" href="/themes/classic/assets/css/theme.css" type="text/css" media="all">'
    . '<link rel="stylesheet" href="/modules/ps_searchbar/ps_searchbar.css">'
    . '<link rel="preload" href="/themes/classic/assets/fonts/x.woff2" as="font">';
$foot = '<script type="text/javascript" src="/js/jquery/jquery-3.7.1.min.js"></script>'
    . '<script src="/modules/ps_searchbar/ps_searchbar.js?v=2" defer></script>'
    . "<script src='/modules/bigmod/big.js'></script>"
    . '<script src="https://cdn.example.invalid/tracker.js" async></script><script>var inline = 1;</script>';
if ($path === '/pl/' || $path === '/pl/3-kategoria') { echo "<html><head>$head</head><body>home$foot</body></html>"; exit; }
if ($path === '/pl/7-produkt.html') { echo "<html><head>$head</head><body>product$foot<script src=\"/modules/productcomments/pc.js\"></script></body></html>"; exit; }
$sizes = ['/themes/classic/assets/css/theme.css' => 90000, '/modules/ps_searchbar/ps_searchbar.css' => 1200, '/js/jquery/jquery-3.7.1.min.js' => 87000,
    '/modules/ps_searchbar/ps_searchbar.js' => 3400, '/modules/bigmod/big.js' => 240000, '/modules/productcomments/pc.js' => 18000];
if (isset($sizes[$path])) { echo str_repeat('a', $sizes[$path]); exit; }
http_response_code(404);
