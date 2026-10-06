# A mock back office and shop for the browser tests, serving the module's own files.
#
#   python3 shop.py PORT MODULE_DIR ADMIN_HTML [--pagecache]
#
# /admin            the audit panel (ADMIN_HTML, rendered from the module's audit.tpl by render.php)
# /admin-go         the same, starting the audit by itself
# /admin-auto       the same as just after an install or update (data-spc-auto)
# /__audit          the audit's server steps, answered with fixed numbers (the PHP tests cover them)
# /pl/...html       shop pages that load SmartPrefetch and InstantNav as the spc_audit cookie says
#                   (as the module does); with --pagecache every page is the same whatever the
#                   cookie, like a shop behind a page cache
# /__log            what the shop served: one line per page, "start|nav|other cookie=..."
# /__state          what each start page found left in the shop window (service workers, Cache
#                   Storage, session storage) before it left some of its own
# /__collect        Behaviour's collector: keeps what behaviour.js sends; /__collected lists it
#                   (?clear=1 empties it). Pages take bh_consent=1, bh_bots=1, bh_idle=MS.
# /bh-admin         the Behaviour tab (BH_ADMIN, rendered by render-bh.php); /__bh answers its
#                   report and visit requests from BH_REPORT (tests/php/behaviour.php's real
#                   report), /__bhq lists the requests
import http.server, json, os, re, sys, time, urllib.parse

PORT, MOD, ADMIN = int(sys.argv[1]), sys.argv[2], sys.argv[3]
PAGECACHE = '--pagecache' in sys.argv
DELAY = 0.25
LOG, STATE, SAVED, COLLECT, BHQ = [], [], [], [], []
SP = {'enabled': True, 'hoverDelay': 65, 'maxTotal': 12, 'maxWarmup': 0, 'warmupSelector': '', 'viewport': False, 'viewportSelector': '', 'fetchFallback': True,
      'workerUrl': '/modules/speedpackcore/views/js/sw.js', 'scope': '/', 'debug': False, 'denyPrefixes': ['/pl/koszyk', '/pl/zamowienie'], 'prerender': True, 'prerenderDelay': 250, 'maxPrerender': 4}
NAV = {'enabled': True, 'links': '#header a[data-depth="0"]', 'region': '#wrapper', 'prefetch': True, 'hoverDelay': 60, 'skeleton': True, 'skeletonDelay': 140, 'bar': False,
       'transition': 'off', 'transitionMs': 0, 'ttl': 60, 'debug': False, 'shapes': [], 'denyPrefixes': []}
PLAN = {'pages': [{'name': n, 'url': '/x'} for n in ['Home page', 'Dieta', 'Kimchi', 'Zakwas', 'Pierogi']], 'home': '/pl/', 'product': 7, 'cache': 'redis',
        'links': '#header a[data-depth="0"]', 'enabled': {'cache': True, 'smartprefetch': True, 'instantnav': True, 'instantcart': True, 'cartspeed': True},
        'tokens': {k: k for k in ['off', 'all', 'nav_off', 'nav_smartprefetch', 'nav_instantnav']}, 'cookie': 'spc_audit', 'expires': 900}

# start pages leave a service worker, a Cache Storage entry and session storage behind, after
# reporting what they found: the audit must clear them before the next click
REPORT = """<script>(function () {
  if (location.search.indexOf('spc_start') === -1) return;
  var sw = navigator.serviceWorker ? navigator.serviceWorker.getRegistrations() : Promise.resolve([]);
  var cs = window.caches ? caches.keys() : Promise.resolve([]);
  Promise.all([sw, cs]).then(function (r) {
    var found = { sw: r[0].length, caches: r[1].length, session: sessionStorage.length };
    return fetch('/__state?' + new URLSearchParams(found)).then(function () {
      sessionStorage.setItem('left-behind', '1');
      if (window.caches) caches.open('left-behind').then(function (c) { return c.put('/left-behind', new Response('x')); });
      if (navigator.serviceWorker) navigator.serviceWorker.register('/left-behind-sw.js', { scope: '/pl/' });
    });
  });
})();</script>"""


# pages InstantNav must not swap in (active content), and one it must (inert microdata)
EVIL = {
    'evil-onerror': '<img src="/nope.png" onerror="parent.__pwned=1;window.__pwned=1">',
    'evil-jsurl': '<a id="x" href="java&#9;script:window.__pwned=1">x</a>',
    'evil-svg': '<svg><a><set attributeName="href" to="javascript:window.__pwned=1"/><text y="20">x</text></a></svg>',
    'evil-iframe': '<iframe srcdoc="<script>parent.__pwned=1</script>"></iframe>',
    'evil-script': '<script>window.__pwned=1</script>',
    'ok-meta': '<div itemscope><meta itemprop="sku" content="7"><script type="application/ld+json">{"a":1}</script></div>',
}


# The phone menu as PrestaShop's Classic theme builds it: a panel in the header with the same
# depth-0 links (the expand control sits inside the parent's link), and a menu button that hides
# the page itself while the panel is open -- Classic's toggleMobileMenu(), without jQuery.
MOBILE_MENU = ('<span id="menu-icon">&#9776;</span><div id="mobile_top_menu_wrapper" style="display:none">'
               + ''.join('<div><a data-depth="0" href="/pl/%d-kategoria.html">%s Mobilna %d</a>%s</div>' % (
                   i, '<span class="navbar-toggler" data-toggle="collapse" data-target="#sub%d">+</span>' % i if i == 2 else '', i,
                   '<ul id="sub2" style="display:none"><li><a data-depth="1" href="/pl/21-pod.html">Pod</a></li></ul>' if i == 2 else '')
                   for i in range(1, 5)) + '</div>')
CLASSIC_MENU_JS = """<script>
document.getElementById('menu-icon').addEventListener('click', function () {
  var w = document.getElementById('mobile_top_menu_wrapper'), open = w.style.display === 'none';
  w.style.display = open ? 'block' : 'none';
  document.getElementById('header').classList.toggle('is-open');
  ['notifications', 'wrapper', 'footer'].forEach(function (id) { document.getElementById(id).style.display = open ? 'none' : ''; });
});
document.querySelectorAll('[data-toggle=collapse]').forEach(function (t) {
  t.addEventListener('click', function (e) { e.preventDefault(); var u = document.querySelector(t.getAttribute('data-target')); u.style.display = u.style.display === 'none' ? 'block' : 'none'; });
});
</script>"""


# PrestaShop's front-end event bus (core.js), as modules and the theme use it
PS_BUS = """<script>window.prestashop = { _h: {}, page: {}, urls: {},
  on: function (n, f) { (this._h[n] = this._h[n] || []).push(f); },
  emit: function (n, d) { (this._h[n] || []).forEach(function (f) { f(d); }); } };</script>"""

# Classic's checkout: a section per step, its title with the done mark, the number and "edit"
CHECKOUT_STEPS = [('personal-information', 'Dane osobowe'), ('addresses', 'Adresy'), ('delivery', 'Sposób dostawy'), ('payment', 'Płatność')]


def checkout(done=0):
    return ('<section id="checkout">' + ''.join(
        '<section id="checkout-%s-step" class="checkout-step -reachable%s%s"><h1 class="step-title js-step-title h3">'
        '<i class="material-icons rtl-no-flip done">&#10003;</i><span class="step-number">%d</span> %s '
        '<span class="step-edit text-muted"><i class="material-icons edit">&#9998;</i> edytuj</span></h1><div class="content">krok %d</div></section>' % (
            s, ' -complete' if i < done else '', ' -current' if i == done else '', i + 1, name, i + 1)
        for i, (s, name) in enumerate(CHECKOUT_STEPS))
        + '<div id="payment-confirmation"><button type="submit">Zamawiam i płacę</button></div></section>')


CHECKOUT = checkout(0)

# what a Classic-based theme looks like around the module's pieces (fonts, buttons, cards)
THEME_CSS = ('body{margin:0;background:#f6f6f6;font:16px/1.5 Manrope,"Noto Sans",Arial,sans-serif;color:#232323}'
             '.btn{display:inline-block;border:0;cursor:pointer;font:inherit}.btn-primary{padding:.5rem 1.25rem;background:#24b9d7;color:#fff;text-transform:uppercase;'
             'box-shadow:2px 2px 4px 0 rgba(0,0,0,.2);font-weight:600}.btn-primary:hover{background:#2592a9}'
             '.wrap{max-width:1180px;margin:30px auto;padding:0 15px}h2{font-weight:700}'
             '#checkout .checkout-step{background:#fff;padding:18px 24px;border-bottom:1px solid #eee}'
             '.step-title{margin:0;font-size:1.6rem;font-weight:500;text-transform:uppercase}.step-title .done{color:#4cbb6c;margin-right:14px;display:none}'
             '.checkout-step.-complete .step-title .done{display:inline-block}.checkout-step.-complete .step-number{display:none}'
             '.step-number{display:inline-block;width:2.4rem;height:2.4rem;line-height:2.4rem;border-radius:50%;background:#4cbb6c;color:#fff;text-align:center;margin-right:14px;font-size:1.1rem}'
             '.step-edit{float:right;font-size:1rem;text-transform:none}.checkout-step:not(.-current) .content{display:none}')


def body_of(path):
    """The page type and object as PrestaShop puts them on <body> (id, class)."""
    m = re.match(r'^/pl/(\d+)-(kategoria|produkt)', path)
    if m:
        kind = 'category' if m.group(2) == 'kategoria' else 'product'
        extra = ' product-id-category-3' if kind == 'product' else ' category-id-parent-2'
        return kind, '%s-id-%s%s' % (kind, m.group(1), extra)
    return {'/pl/': 'index', '/pl/koszyk': 'cart', '/pl/zamowienie': 'checkout', '/pl/szukaj': 'search'}.get(path, 'cms'), 'lang-pl'


def behaviour(query):
    q = dict(urllib.parse.parse_qsl(query))
    cfg = {'url': '/__collect', 'consent': 1 if q.get('bh_consent') else 0, 'skipBots': bool(q.get('bh_bots')), 'idle': int(q.get('bh_idle') or 0)}
    return '<script>window.spcBehaviour=%s</script><script src="/modules/speedpackcore/views/js/behaviour.js" defer></script>' % json.dumps(cfg)


def page(path, mode, query=''):
    links = ''.join('<a data-depth="0" href="/pl/%d-kategoria.html">Kategoria %d</a> ' % (i, i) for i in range(1, 7))
    cards = ''.join('<div class="card"><svg width="160" height="160"><rect width="160" height="160" fill="#%02x6a4e"/></svg><p>Produkt %d</p></div>' % (40 + i * 20, i) for i in range(8))
    add = ''
    visitor = mode == 'visitor'
    mode = 'all' if visitor else mode
    if mode in ('all', 'nav_smartprefetch'):
        add += '<script>window.smartPrefetchConfig=%s</script><script src="/modules/speedpackcore/views/js/smartprefetch.js" defer></script>' % json.dumps(SP)
    if mode in ('all', 'nav_instantnav'):
        add += '<script>window.instantNavConfig=%s</script><script src="/modules/speedpackcore/views/js/instantnav.js" defer></script>' % json.dumps(NAV)
    if visitor:
        add += behaviour(query)
    kind, classes = body_of(path)
    q = dict(urllib.parse.parse_qsl(query))
    if kind == 'checkout' and q.get('summary') and os.environ.get('CHECKOUT_JSON'):
        add += ('<style>%s</style><link rel="stylesheet" href="/modules/speedpackcore/views/css/checkout-summary.css">'
                '<script>window.spcCheckout=%s</script><script src="/modules/speedpackcore/views/js/checkout-summary.js" defer></script>') % (
            THEME_CSS, open(os.environ['CHECKOUT_JSON'], encoding='utf-8').read())
    extra = checkout(int(q.get('done', 0))) if kind == 'checkout' else ('<section id="product-search-no-matches">Brak wyników</section>' if kind == 'search' else '')
    return ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%s</title>'
            '<style>#header a{margin:8px;display:inline-block}.card{display:inline-block;margin:6px}#menu-icon{display:none}'
            '@media(max-width:767px){.desk{display:none}#menu-icon{display:inline-block;padding:10px}}</style></head>'
            '<body id="%s" class="%s">%s<div id="header"><b>SHOP</b> <span class="desk">%s</span> <a href="/pl/koszyk">Koszyk</a>%s</div>'
            '<div id="notifications"></div><div id="wrapper">%s<h1>%s</h1>%s%s</div><div id="footer">Stopka</div>%s%s%s</body></html>') % (
        path, kind, classes, PS_BUS, links, MOBILE_MENU, EVIL.get(path.strip('/').split('/')[-1].replace('.html', ''), ''), path, extra, cards, add, REPORT, CLASSIC_MENU_JS)


class H(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def send(self, body, ctype, extra=None):
        body = body if isinstance(body, bytes) else body.encode()
        self.send_response(200)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def cookie(self):
        for part in (self.headers.get('Cookie') or '').split(';'):
            k, _, v = part.strip().partition('=')
            if k == 'spc_audit':
                return v
        return None

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        if self.path.startswith('/__collect'):
            try:
                COLLECT.append(json.loads(raw.decode()))
            except ValueError:
                COLLECT.append({'bad': raw.decode(errors='replace')})
            self.send_response(204)
            self.end_headers()
            return
        boundary = self.headers.get('Content-Type', '').split('boundary=')[-1].encode()
        fields = {}
        for chunk in raw.split(b'--' + boundary):
            if b'name="' in chunk:
                fields[chunk.split(b'name="')[1].split(b'"')[0].decode()] = chunk.split(b'\r\n\r\n', 1)[1].rsplit(b'\r\n', 1)[0].decode()
        step = fields.get('step')
        if step == 'plan':
            ans = PLAN
        elif step == 'page':
            ans = {'off': 410, 'on': 95, 'verified': True}
        elif step == 'cart':
            ans = {'core': 388, 'lean': 61}
        elif step == 'cartspeed':
            ans = {'lookups': 73, 'off': {'queries': 73, 'ms': 21}, 'on': {'queries': 1, 'ms': 0.4}}
        elif step == 'save':
            run = json.loads(fields['results'])
            if fields.get('replace') and SAVED:
                SAVED.pop()
                run['replaced'] = True
            SAVED.append(run)
            ans = {'run': run, 'history': SAVED}
        else:
            ans = {'error': 'unknown step'}
        self.send(json.dumps(ans), 'application/json')

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        p = u.path
        if p == '/__log':
            return self.send('\n'.join(LOG), 'text/plain')
        if p == '/__state':
            STATE.append(dict(urllib.parse.parse_qsl(u.query)))
            return self.send('ok', 'text/plain')
        if p == '/__states':
            return self.send(json.dumps(STATE), 'application/json')
        if p == '/__saved':
            return self.send(json.dumps(SAVED), 'application/json')
        if p == '/__collected':
            out = json.dumps(COLLECT)
            if 'clear=1' in u.query:
                del COLLECT[:]
            return self.send(out, 'application/json')
        if p == '/bh-admin':
            return self.send(open(os.environ['BH_ADMIN'], encoding='utf-8').read(), 'text/html; charset=utf-8')
        if p == '/__bh':
            q = dict(urllib.parse.parse_qsl(u.query))
            BHQ.append(q)
            data = json.load(open(os.environ['BH_REPORT'], encoding='utf-8'))
            return self.send(json.dumps(data['session'] if q.get('op') == 'session' else data['report']), 'application/json')
        if p == '/bo-order' or p == '/bo-cart':
            # a back-office page as PrestaShop draws it: the order page with the panel from its hook,
            # the cart page (old layout or the new one) with the panel sent along with the head
            q = dict(urllib.parse.parse_qsl(u.query))
            bo = ('body{margin:0;background:#eff1f2;font:13px/1.5 "Open Sans",Arial,sans-serif;color:#363a41}'
                  '.card,.panel{margin-bottom:16px;border:1px solid #dbe6e9;border-radius:5px;background:#fff}'
                  '.card-header,.panel-heading{padding:10px 16px;border-bottom:1px solid #dbe6e9;font-size:14px;font-weight:600}'
                  '.card-body,.panel-body{padding:16px}a{color:#25b9d7;text-decoration:none}'
                  '.wrap{max-width:1400px;padding:20px}.page-head{padding:14px 20px;background:#fff;border-bottom:1px solid #dbe6e9;font-size:18px}')
            if p == '/bo-order':
                body = '<div class="wrap"><div class="card"><div class="card-header">Zamówienie #77</div><div class="card-body">Produkty…</div></div>%s</div>' % open(os.environ['JOURNEY_HTML'], encoding='utf-8').read()
                head = ''
            else:
                head = open(os.environ['JOURNEY_CART_HTML'], encoding='utf-8').read()
                cart = '<div class="panel"><div class="panel-heading">Koszyk #900</div><div class="panel-body">Klient, produkty…</div></div>'
                body = ('<div id="main-div"><div class="header-toolbar">Koszyk</div><div class="content-div"><div class="container-fluid">%s</div></div></div>' % cart.replace('panel', 'card')) if q.get('layout') == 'new' \
                    else ('<div class="page-head">Koszyk</div><div id="content" class="bootstrap"><div class="row">%s</div></div>' % cart)
            return self.send('<!doctype html><html><head><meta charset="utf-8"><style>%s</style>%s</head><body>%s</body></html>' % (bo, head, body), 'text/html; charset=utf-8')
        if p == '/reorder-card':
            card = open(os.environ['REORDER_HTML'], encoding='utf-8').read()
            # the shop's images of the test order: grey squares with the product's initial
            card = re.sub(r'src="https://shop.test/(\d+)-small_default/(\w+)\.jpg"', lambda m: 'src="/__thumb/%s"' % m.group(2), card)
            return self.send('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                             '<style>%s</style><link rel="stylesheet" href="/modules/speedpackcore/views/css/reorder.css"></head>'
                             '<body><div class="wrap"><p style="color:#24b9d7">Wszystkie produkty</p>%s</div></body></html>' % (THEME_CSS, card), 'text/html; charset=utf-8')
        if p.startswith('/__thumb/'):
            name = p.split('/')[-1]
            hue = sum(map(ord, name)) % 360
            svg = ('<svg xmlns="http://www.w3.org/2000/svg" width="98" height="98"><rect width="98" height="98" fill="hsl(%d,45%%,80%%)"/>'
                   '<text x="49" y="62" font-size="38" text-anchor="middle" fill="hsl(%d,40%%,35%%)" font-family="Arial">%s</text></svg>') % (hue, hue, name[:1].upper())
            return self.send(svg, 'image/svg+xml')
        if p == '/__bhq':
            return self.send(json.dumps(BHQ), 'application/json')
        if p in ('/admin', '/admin-go', '/admin-auto'):
            html = open(ADMIN, encoding='utf-8').read()
            if p == '/admin-auto':
                html = html.replace('data-spc-auto="0"', 'data-spc-auto="1"')
            if p == '/admin-go':
                html = html.replace('</body>', '<script>setTimeout(function(){document.querySelector("[data-spc-start]").click()},800)</script></body>')
            return self.send(html, 'text/html; charset=utf-8')
        if p == '/left-behind-sw.js':
            return self.send('self.addEventListener("fetch", function () {});', 'application/javascript', {'Service-Worker-Allowed': '/'})
        if p.startswith('/modules/speedpackcore/'):
            f = os.path.join(MOD, p[len('/modules/speedpackcore/'):])
            if os.path.isfile(f):
                ctype = 'application/javascript' if f.endswith('.js') else 'text/css' if f.endswith('.css') else 'application/octet-stream'
                return self.send(open(f, 'rb').read(), ctype, {'Service-Worker-Allowed': '/'})
        if p.startswith('/pl/'):
            q = dict(urllib.parse.parse_qsl(u.query))
            kind = 'start' if 'spc_start' in q else 'nav' if 'spc_nav' in q else 'other'
            c = self.cookie()
            # an ordinary visitor (no audit cookie) gets every part, as on the real shop
            mode = 'all' if PAGECACHE else ('visitor' if c is None else c)
            LOG.append('%s %s cookie=%s purpose=%s' % (kind, p, c, self.headers.get('Sec-Purpose') or ''))
            time.sleep(DELAY)
            return self.send(page(p, mode, u.query), 'text/html; charset=utf-8')
        self.send_response(404)
        self.send_header('Content-Length', '0')
        self.end_headers()

    def log_message(self, *a):
        pass


http.server.ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
