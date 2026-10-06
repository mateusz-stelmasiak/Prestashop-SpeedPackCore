// The shop scripts in a real Chromium against tests/browser/shop.py (an ordinary visitor: every part on).
//   node shop.e2e.js PORT
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');
const BASE = 'http://127.0.0.1:' + process.argv[2];
let failed = 0;
const ok = (c, what) => { console.log((c ? 'ok  ' : 'FAIL: ') + what); if (!c) failed++; };

(async () => {
  const b = await chromium.launch(process.env.SPC_CHROME ? { executablePath: process.env.SPC_CHROME } : {});
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  p.on('pageerror', (e) => errors.push(e.message));

  // --- InstantNav: clean pages swap in place, pages with active content load normally
  for (const [slug, expect] of [['ok-meta', 'swapped'], ['3-kategoria', 'swapped'], ['evil-onerror', 'loaded'], ['evil-jsurl', 'loaded'],
    ['evil-svg', 'loaded'], ['evil-iframe', 'loaded'], ['evil-script', 'loaded']]) {
    await p.goto(BASE + '/pl/');
    await p.waitForFunction(() => window.instantNavConfig && document.readyState === 'complete');
    await p.waitForTimeout(300);
    await p.evaluate(() => { window.__marker = 1; window.__pwned = 0; });
    await p.evaluate((u) => document.querySelector('#header a[data-depth="0"]').setAttribute('href', u), '/pl/' + slug + '.html');
    await p.click('#header a[data-depth="0"]');
    await p.waitForTimeout(1200);
    const st = await p.evaluate(() => ({ marker: window.__marker === 1, pwned: window.__pwned, path: location.pathname, meta: !!document.querySelector('#wrapper meta[itemprop=sku]') }));
    const how = st.marker ? 'swapped' : 'loaded';
    ok(how === expect && st.path === '/pl/' + slug + '.html' && !(how === 'swapped' && st.pwned), 'InstantNav ' + slug + ': ' + how + (st.meta ? ' (microdata kept)' : ''));
  }

  // --- SmartPrefetch: a hovered link gets a speculation rule; the cart never does
  await p.goto(BASE + '/pl/');
  await p.waitForFunction(() => window.smartPrefetchConfig && document.readyState === 'complete');
  await p.waitForTimeout(400);
  const rules = () => p.evaluate(() => Array.prototype.map.call(document.querySelectorAll('script[type=speculationrules]'), (s) => s.textContent).join('\n'));
  // InstantNav owns the menu links; a product link in the content is SmartPrefetch's
  await p.evaluate(() => { const a = document.createElement('a'); a.href = '/pl/9-produkt.html'; a.id = 'prod'; a.textContent = 'Produkt'; document.getElementById('wrapper').appendChild(a); });
  await p.hover('#prod');
  await p.waitForTimeout(400);
  const r1 = await rules();
  ok(/9-produkt\.html/.test(r1) && /"prefetch"/.test(r1), 'SmartPrefetch: hovering a product link adds a prefetch rule for it');
  ok(/"prerender"/.test(r1), 'SmartPrefetch: still on it after 250 ms – a prerender rule too');
  await p.hover('a[href="/pl/koszyk"]');
  await p.waitForTimeout(400);
  ok(!/koszyk/.test(await rules()), 'SmartPrefetch: the cart is never fetched ahead');
  await p.hover('#header a[data-depth="0"]');
  await p.waitForTimeout(400);
  ok(!/1-kategoria/.test(await rules()), 'SmartPrefetch: menu links are left to InstantNav (no double download)');

  // --- InstantNav on a phone: the Classic menu hides the page while open; a tap on a menu link
  // must bring the page back with the new content, not leave a blank screen
  const m = await b.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  m.on('pageerror', (e) => errors.push(e.message));
  await m.goto(BASE + '/pl/');
  await m.waitForFunction(() => window.instantNavConfig && document.readyState === 'complete');
  await m.waitForTimeout(300);
  await m.evaluate(() => { window.__marker = 1; });
  await m.tap('#menu-icon');
  ok(await m.evaluate(() => getComputedStyle(document.getElementById('wrapper')).display === 'none'), 'phone: the open menu hides the page (as Classic does)');
  await m.tap('#mobile_top_menu_wrapper .navbar-toggler');
  await m.waitForTimeout(300);
  ok(await m.evaluate(() => location.pathname === '/pl/' && document.getElementById('sub2').style.display === 'block'), 'phone: the expand control opens the sub-menu, no navigation');
  await m.tap('#mobile_top_menu_wrapper a[href="/pl/3-kategoria.html"]');
  await m.waitForTimeout(1200);
  const ph = await m.evaluate(() => {
    const vis = (id) => { const el = document.getElementById(id); return getComputedStyle(el).display !== 'none' && el.offsetHeight > 0; };
    return { swapped: window.__marker === 1, path: location.pathname, h1: (document.querySelector('#wrapper h1') || {}).textContent,
      wrapper: vis('wrapper'), footer: vis('footer'), menu: document.getElementById('mobile_top_menu_wrapper').style.display, open: document.getElementById('header').classList.contains('is-open') };
  });
  ok(ph.swapped && ph.path === '/pl/3-kategoria.html' && ph.h1 === '/pl/3-kategoria.html', 'phone: a menu tap swaps the new page in ' + JSON.stringify(ph));
  ok(ph.wrapper && ph.footer, 'phone: the page is visible after the tap (no blank screen)');
  ok(ph.menu === 'none' && !ph.open, 'phone: the menu is closed');
  await m.tap('#menu-icon');
  ok(await m.evaluate(() => document.getElementById('mobile_top_menu_wrapper').style.display === 'block'), 'phone: the menu opens again afterwards');

  ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
