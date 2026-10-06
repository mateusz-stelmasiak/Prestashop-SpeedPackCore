// Behaviour in a real Chromium against tests/browser/shop.py.
//   node behaviour.e2e.js PORT
// The shop side: what behaviour.js sends while a shopper browses, swaps pages with InstantNav, adds
// to the cart, goes through the checkout and searches; that robots, prerendered pages and visitors
// without consent send nothing. With BH_ADMIN set on shop.py: the Behaviour tab drawing a real
// report (tests/php/behaviour.php's), searching, filtering and opening one visit.
// SPC_BH_MESSAGES: where to write everything collected (tests/php/behaviour-replay.php stores it).
// SPC_SHOTS: a folder for screenshots of the tab.
const fs = require('fs');
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');
const BASE = 'http://127.0.0.1:' + process.argv[2];
let failed = 0;
const ok = (c, what) => { console.log((c ? 'ok  ' : 'FAIL: ') + what); if (!c) failed++; };
const all = [];

(async () => {
  const b = await chromium.launch(process.env.SPC_CHROME ? { executablePath: process.env.SPC_CHROME } : {});
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  p.on('pageerror', (e) => errors.push(e.message));
  const got = async (clear = true) => {
    const r = await (await fetch(BASE + '/__collected' + (clear ? '?clear=1' : ''))).json();
    const msgs = [].concat(...r.map((x) => x.m || []));
    all.push(...msgs);
    return msgs;
  };
  const views = (m) => m.filter((x) => x.t === 'v');
  const timeOf = (m, k) => m.filter((x) => x.t === 't' && x.k === k).reduce((a, x) => Math.max(a, x.ms), -1);
  const wiggle = async (ms) => { for (let t = 0; t < ms; t += 250) { await p.mouse.move(100 + (t % 500), 200 + (t % 300)); await p.waitForTimeout(250); } };
  await got();

  // --- a page shown, engaged time, an InstantNav swap
  await p.goto(BASE + '/pl/?bh_idle=1500');
  await p.waitForTimeout(1500);
  let m = await got();
  const home = views(m)[0];
  ok(views(m).length === 1 && home.p === 'index' && home.i === 0 && home.n === 0 && /^[0-9a-f]{8}$/.test(home.k) && home.u === '/pl/?bh_idle=1500' && home.d === 0, 'a page shown: one view, its type, a fresh key, how it was reached, the device ' + JSON.stringify(home));
  await wiggle(3000);
  await p.evaluate(() => { window.__marker = 1; });
  await p.click('#header .desk a[href="/pl/3-kategoria.html"]');
  await p.waitForTimeout(1500);
  m = await got();
  const cat = views(m)[0];
  const homeMs = timeOf(m, home.k);
  ok(cat && cat.p === 'category' && cat.i === 3 && cat.n === 1 && cat.u === '/pl/3-kategoria.html', 'an InstantNav swap is a page view of its own (category 3, reached by InstantNav)');
  ok(homeMs >= 3000 && homeMs <= 5500, 'the home page: ' + homeMs + ' ms engaged, sent when the next page came');
  ok(await p.evaluate(() => window.__marker === 1 && document.body.id === 'category'), 'the swap kept the page (no reload)');

  // --- idle time is not engaged time
  await p.waitForTimeout(4500);
  await p.goto(BASE + '/pl/7-produkt.html');
  await p.waitForTimeout(1200);
  m = await got();
  const catMs = timeOf(m, cat.k);
  ok(catMs >= 500 && catMs <= 3000, 'idle on the category for 4.5 s: only ' + catMs + ' ms counted (a minute without input in the shop; 1.5 s here)');
  const prod = views(m)[0];
  ok(prod && prod.p === 'product' && prod.i === 7 && prod.n === 0, 'a full page load: product 7');

  // --- add to cart: the theme's event and InstantCart's for one click count once
  await p.evaluate(() => { prestashop.emit('updateCart', { reason: { linkAction: 'add-to-cart', idProduct: 7 } }); prestashop.emit('instantCartAdded', {}); });
  await p.waitForTimeout(1200);
  m = await got();
  ok(m.filter((x) => x.t === 'e' && x.e === 'cart' && x.k === prod.k).length === 1, 'add to cart: one event for the product page');
  await p.evaluate(() => prestashop.emit('updateCart', { reason: { linkAction: 'delete-from-cart' } }));
  await p.waitForTimeout(1000);
  ok((await got()).filter((x) => x.e === 'cart').length === 0, 'a line removed is not an add');

  // --- Core Web Vitals: a layout shift, a slow tap
  await p.waitForTimeout(700);
  await p.evaluate(() => { const d = document.createElement('div'); d.style.height = '300px'; document.body.insertBefore(d, document.body.firstChild); });
  await p.waitForTimeout(600);
  await p.evaluate(() => { const b = document.createElement('button'); b.id = 'slow'; b.textContent = 'slow'; b.addEventListener('click', () => { const t = performance.now(); while (performance.now() - t < 260) { /* busy */ } b.textContent = 'done'; }); document.getElementById('wrapper').prepend(b); });
  await p.click('#slow');
  await p.waitForTimeout(500);

  // --- the cart page: an error shown
  await p.goto(BASE + '/pl/koszyk');
  await p.evaluate(() => { const d = document.createElement('div'); d.className = 'alert alert-danger'; d.textContent = '  Produkt   niedostępny '; document.getElementById('notifications').appendChild(d); });
  await p.waitForTimeout(2200);
  m = await got();
  ok(m.some((x) => x.e === 'error' && x.d === 'Produkt niedostępny'), 'an error message on the cart page');
  const pv = m.filter((x) => x.t === 't' && x.k === prod.k).pop() || {};
  console.log('    product page vitals', JSON.stringify(pv));
  ok(pv.l > 0 && pv.b > 0 && pv.f > 0 && pv.f <= pv.l, 'Core Web Vitals of a page load: LCP, TTFB, FCP');
  ok(pv.c >= 100, 'a 300 px shift after load counts as layout shift (CLS ' + (pv.c / 1000) + ')');
  ok(pv.in >= 250, 'a tap answered after 260 ms of work: INP ' + pv.in + ' ms');
  const catT = all.filter((x) => x.t === 't' && x.k === cat.k).pop() || {};
  ok(catT.l === undefined && catT.b === undefined, 'an InstantNav swap has no LCP or TTFB of its own');

  // --- checkout steps, pay
  await p.goto(BASE + '/pl/zamowienie');
  await p.waitForTimeout(1300);
  for (const step of ['addresses', 'delivery', 'payment']) {
    await p.evaluate((s) => {
      document.querySelectorAll('.checkout-step').forEach((e) => e.classList.remove('-current'));
      document.getElementById('checkout-' + s + '-step').classList.add('-current');
    }, step);
    await p.waitForTimeout(1300);
  }
  await p.click('#payment-confirmation button');
  await p.waitForTimeout(1200);
  m = await got();
  const steps = m.filter((x) => x.e === 'step').map((x) => x.d);
  ok(JSON.stringify(steps) === '["personal","addresses","delivery","payment"]', 'checkout steps in order: ' + steps.join(' > '));
  ok(m.filter((x) => x.e === 'pay').length === 1, 'the pay button');

  // --- a search with no results; tracking parameters kept out of the address
  await p.goto(BASE + '/pl/szukaj?s=Kimchy+');
  await p.waitForTimeout(1400);
  m = await got();
  ok(m.some((x) => x.e === 'search' && x.d === 'kimchy' && x.x === 0), 'a search with no results: the words and 0');
  await p.goto(BASE + '/pl/3-kategoria.html?utm_source=fb&utm_medium=cpc&utm_campaign=Jesien&gclid=abc&page=2');
  await p.waitForTimeout(1200);
  m = await got();
  const ad = views(m)[0];
  ok(ad.u === '/pl/3-kategoria.html?page=2' && ad.a && ad.a.s === 'fb' && ad.a.m === 'cpc' && ad.a.c === 'Jesien' && ad.a.g === 1, 'campaign tags sent apart, and kept out of the address');
  ok(m.every((x) => !JSON.stringify(x).includes('Mozilla') && !JSON.stringify(x).includes('HeadlessChrome')), 'no browser string is sent');

  // --- a tap on "Order the same as last time"
  await p.evaluate(() => { const b = document.createElement('button'); b.type = 'button'; b.setAttribute('data-spc-reorder', ''); b.textContent = 'Zamów ponownie'; document.getElementById('wrapper').prepend(b); });
  await p.click('[data-spc-reorder]');
  await p.waitForTimeout(400);
  ok((await got()).some((x) => x.e === 'reorder'), 'a tap on Repeat last order is recorded (sent at once)');

  // --- phones
  const ph = await b.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ph.goto(BASE + '/pl/');
  await ph.waitForTimeout(1200);
  ok(views(await got())[0].d === 2, 'a phone: device 2');
  await ph.close();

  // --- robots, consent, prerender
  await p.goto(BASE + '/pl/?bh_bots=1');
  await p.waitForTimeout(1500);
  ok(views(await got()).length === 0, 'an automated browser sends nothing (navigator.webdriver; robots by name too)');
  await p.goto(BASE + '/pl/?bh_consent=1');
  await p.waitForTimeout(1500);
  ok(views(await got()).length === 0, 'with "only after consent": nothing before the banner allows it');
  await p.evaluate(() => { window.dataLayer = window.dataLayer || []; window.dataLayer.push(['consent', 'update', { analytics_storage: 'granted' }]); });
  await p.waitForTimeout(3200);
  ok(views(await got()).length === 1, 'Google Consent Mode "analytics_storage granted": the page is counted');
  await p.goto(BASE + '/pl/4-kategoria.html?bh_consent=1');
  await p.evaluate(() => document.dispatchEvent(new Event('spc:consent')));
  await p.waitForTimeout(1200);
  ok(views(await got()).length === 1, 'the spc:consent event: counted at once');
  const pre = await b.newPage();
  await pre.addInitScript(() => { window.__pre = true; Object.defineProperty(document, 'prerendering', { configurable: true, get: () => window.__pre }); });
  await pre.goto(BASE + '/pl/5-kategoria.html');
  await pre.waitForTimeout(1500);
  ok(views(await got()).length === 0, 'a prerendered page: nothing until it is shown');
  await pre.evaluate(() => { window.__pre = false; document.dispatchEvent(new Event('prerenderingchange')); });
  await pre.waitForTimeout(1200);
  ok(views(await got()).length === 1, 'shown: counted');
  await pre.close();

  ok(errors.length === 0, 'no script errors on the shop ' + JSON.stringify(errors));
  if (process.env.SPC_BH_MESSAGES) { fs.writeFileSync(process.env.SPC_BH_MESSAGES, JSON.stringify(all)); }

  // --- the Behaviour tab
  const hasAdmin = (await fetch(BASE + '/bh-admin')).status === 200;
  if (hasAdmin) {
    const a = await b.newPage({ viewport: { width: 1360, height: 1000 } });
    const aerr = [];
    a.on('pageerror', (e) => aerr.push(e.message));
    await a.goto(BASE + '/bh-admin');
    await a.waitForSelector('.spc-bh-kpi');
    await a.waitForTimeout(400);
    const st = await a.evaluate(() => ({
      kpi: document.querySelector('.spc-bh-kpi b').textContent,
      funnel: document.querySelectorAll('.spc-bh-bar').length,
      kimchi: document.body.textContent.includes('Kimchi klasyczne'),
      zakwas: Array.prototype.some.call(document.querySelectorAll('.spc-bh-step, .spc-bh-page'), (e) => e.textContent.includes('Zakwas')),
      img: document.querySelectorAll('[data-spc-bh-report] img').length,
      pwned: window.__pwned,
      bars: document.querySelectorAll('.spc-bh-chart rect').length,
      sessions: document.querySelectorAll('.spc-bh-session').length,
      selects: Array.prototype.map.call(document.querySelectorAll('[data-spc-bh-select]'), (s) => s.options.length)
    }));
    ok(st.kpi === '31' && st.bars > 0 && st.funnel >= 10 + 7 && st.sessions === 31, 'the tab draws the report: KPIs, timeline, funnel and time-on-page bars, visits ' + JSON.stringify(st));
    const vit = await a.evaluate(() => ({ tiles: Array.prototype.map.call(document.querySelectorAll('.spc-bh-vital'), (t) => t.className.replace('spc-bh-vital ', '') + ':' + t.querySelector('b').textContent), rows: document.querySelectorAll('.spc-bh-vtable tbody tr').length }));
    ok(vit.tiles.length === 5 && vit.tiles[0] === 'r-ni:3.20 s' && vit.tiles.indexOf('r-ni:0.12') !== -1 && vit.rows >= 1, 'Core Web Vitals: the 75th percentiles with their ratings, and a table by page ' + JSON.stringify(vit));
    ok(st.kimchi && st.zakwas && st.img === 0 && st.pwned === undefined, 'names as text: a product name with markup shows as text, nothing runs');
    ok(st.selects.join(',') === '4,5,4,7,5,3', 'filters: range, bucket, device, source, outcome, shopper');
    if (process.env.SPC_SHOTS) { await a.screenshot({ path: process.env.SPC_SHOTS + '/behaviour.png', fullPage: true }); }
    const last = async () => (await (await fetch(BASE + '/__bhq')).json()).pop();
    await a.fill('[data-spc-bh-q]', 'Kimchi > koszyk');
    await a.waitForTimeout(900);
    let q = await last();
    ok(q.op === 'report' && q.q === 'Kimchi > koszyk' && q.spc_ajax === 'behaviour' && +q.from > 0 && q.returning === undefined, 'typing a search asks for a new report ' + JSON.stringify(q));
    await a.selectOption('[data-spc-bh-select="outcome"]', 'ordered');
    await a.selectOption('[data-spc-bh-select="device"]', 'mobile');
    await a.waitForTimeout(600);
    q = await last();
    ok(q.outcome === 'ordered' && q.device === 'mobile' && q.q === 'Kimchi > koszyk', 'filters go with the search');
    await a.click('.spc-bh-page >> nth=0');
    await a.waitForTimeout(600);
    q = await last();
    ok(/^[a-z-]+(:\d+)?$/.test(q.q), 'a click on a page searches for the visits through it: ' + q.q);
    await a.click('.spc-bh-session >> nth=0');
    await a.waitForSelector('[data-spc-bh-modal]:not([hidden]) .spc-bh-timeline li');
    const visit = await a.evaluate(() => ({ items: document.querySelectorAll('.spc-bh-timeline li').length, events: document.querySelectorAll('.spc-bh-timeline .ev').length, text: document.querySelector('[data-spc-bh-session]').textContent }));
    ok(visit.items === 6 && visit.events === 6 && visit.text.includes('Kimchi klasyczne'), 'one visit page by page, with its events (' + visit.items + ' pages, ' + visit.events + ' events)');
    if (process.env.SPC_SHOTS) { await a.screenshot({ path: process.env.SPC_SHOTS + '/behaviour-visit.png' }); }
    await a.keyboard.press('Escape');
    ok(await a.evaluate(() => document.querySelector('[data-spc-bh-modal]').hidden), 'Escape closes it');
    await a.reload();
    await a.waitForSelector('.spc-bh-kpi');
    ok(await a.evaluate(() => document.querySelector('[data-spc-bh-select="outcome"]').value === 'ordered'), 'the filters are remembered');
    await a.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
    ok(aerr.length === 0, 'no script errors on the tab ' + JSON.stringify(aerr));
  } else {
    console.log('(no BH_ADMIN: the tab is not tested)');
  }

  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
