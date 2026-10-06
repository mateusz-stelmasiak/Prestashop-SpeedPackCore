// The shopper's path in the back office, in a real Chromium: the panel on an order page (from its
// hook) and on a cart page, old layout (1.7, 8) and new (9), where views/js/journey.js places it.
//   node journey.e2e.js PORT    (shop.py started with JOURNEY_HTML and JOURNEY_CART_HTML from tests/php/health.php)
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');
const BASE = 'http://127.0.0.1:' + process.argv[2];
const SHOTS = process.env.SPC_SHOTS;
let failed = 0;
const ok = (c, what) => { console.log((c ? 'ok  ' : 'FAIL: ') + what); if (!c) failed++; };

(async () => {
  const b = await chromium.launch(process.env.SPC_CHROME ? { executablePath: process.env.SPC_CHROME } : {});
  const errors = [];
  const open = async (path, w) => {
    const p = await b.newPage({ viewport: { width: w || 1400, height: 900 }, deviceScaleFactor: 2 });
    p.on('pageerror', (e) => errors.push(e.message));
    await p.goto(BASE + path);
    await p.waitForLoadState('networkidle');
    return p;
  };
  const look = (p) => p.evaluate(() => {
    const j = document.getElementById('spc-journey');
    if (!j) { return null; }
    const flows = Array.prototype.map.call(j.querySelectorAll('.spc-jflow'), (f) => f.children.length);
    const css = getComputedStyle(j.querySelector('.spc-jcard'));
    const overflow = Array.prototype.some.call(j.querySelectorAll('.spc-jcard, .spc-jtile, .spc-jev'), (e) => e.getBoundingClientRect().right > j.getBoundingClientRect().right + 1);
    return { flows, styled: css.borderTopStyle === 'solid' && css.borderRadius === '6px', overflow, index: Array.prototype.filter.call(j.parentNode.children, (c) => c.tagName !== 'LINK').indexOf(j), parent: j.parentNode.id || j.parentNode.className, tiles: j.querySelectorAll('.spc-jtile').length };
  });

  let p = await open('/bo-order');
  let st = await look(p);
  ok(st && st.flows.join() === '4,4' && st.tiles === 6 && st.styled && !st.overflow, 'order page: the panel, styled, two visits of 4 pages, six tiles, nothing spilling out ' + JSON.stringify(st));
  if (SHOTS) { await p.locator('#spc-journey').screenshot({ path: SHOTS + '/journey-order.png' }); }
  await p.close();

  p = await open('/bo-order', 900);
  st = await look(p);
  ok(st && !st.overflow, 'a narrower screen: the pages wrap, nothing spills out');
  if (SHOTS) { await p.locator('#spc-journey').screenshot({ path: SHOTS + '/journey-order-900.png' }); }
  await p.close();

  for (const layout of ['old', 'new']) {
    p = await open('/bo-cart?layout=' + layout);
    st = await look(p);
    ok(st && st.index === 0 && String(st.parent).indexOf(layout === 'old' ? 'content' : 'container-fluid') !== -1 && st.flows.join() === '4,4' && st.styled, 'cart page (' + layout + ' layout): the panel at the top of the content ' + JSON.stringify(st));
    ok(await p.evaluate(() => !document.getElementById('spc-journey-cart') && document.querySelectorAll('#spc-journey').length === 1), 'cart page (' + layout + '): placed once, the template removed');
    await p.close();
  }
  ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
