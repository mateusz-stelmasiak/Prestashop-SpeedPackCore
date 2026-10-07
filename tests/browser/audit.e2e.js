// The speed audit's click test in a real Chromium against tests/browser/shop.py.
//   node audit.e2e.js PORT normal      every mode is a different configuration: all counted
//   node audit.e2e.js PORT pagecache   the shop ignores the cookie: nothing counted, a warning
//   node audit.e2e.js PORT auto        just after an install or update: starts by itself, clicks on one press
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');
const BASE = 'http://127.0.0.1:' + process.argv[2];
const MODE = process.argv[3] || 'normal';
let failed = 0;
const ok = (c, what) => { console.log((c ? 'ok  ' : 'FAIL: ') + what); if (!c) failed++; };

(async () => {
  const b = await chromium.launch(process.env.SPC_CHROME ? { executablePath: process.env.SPC_CHROME } : {});
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', (e) => errors.push('admin: ' + e.message));
  ctx.on('page', (pp) => pp.on('pageerror', (e) => errors.push('shop: ' + e.message)));
  if (MODE === 'auto') {
    // just after an install or update: the audit starts by itself, without a shop window (a
    // browser opens one only on a click); one press then adds the click test to the same audit
    await p.goto(BASE + '/admin-auto');
    await p.waitForSelector('[data-spc-clicks]', { timeout: 120000 });
    let saved = JSON.parse(await (await fetch(BASE + '/__saved')).text());
    let log = (await (await fetch(BASE + '/__log')).text()).split('\n').filter(Boolean);
    const notes = await p.$$eval('[data-spc-note]', (l) => l.map((x) => x.textContent));
    ok(ctx.pages().length === 1 && !log.some((l) => l.startsWith('start ')), 'started by itself, with no shop window (no blocked pop-up)');
    ok(saved.length === 1 && saved[0].pages && saved[0].cart && saved[0].cartspeed && Object.keys(saved[0].nav).length === 0, 'the server measurements are done and saved: ' + JSON.stringify(saved[0]));
    ok(notes.filter((n) => /Measure the clicks too/.test(n)).length === 2, 'the click cards say how to add the click test');
    await p.click('[data-spc-clicks]');
    await p.waitForFunction(() => /Done|stopped/i.test(document.querySelector('[data-spc-say]').textContent), null, { timeout: 180000 });
    saved = JSON.parse(await (await fetch(BASE + '/__saved')).text());
    log = (await (await fetch(BASE + '/__log')).text()).split('\n').filter(Boolean);
    ok(log.filter((l) => l.startsWith('start ')).length === 16, 'one press: the click test in a shop window (16 start pages)');
    ok(saved.length === 1 && saved[0].replaced && ['off', 'smartprefetch', 'instantnav', 'all'].every((m) => typeof saved[0].nav[m] === 'number') && saved[0].pages, 'it completes the same audit: one saved run with the clicks and the server figures');
    ok(await p.evaluate(() => !document.querySelector('[data-spc-clicks]')), 'the button is gone');
    ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
    await b.close();
    console.log(failed ? failed + ' FAILED' : 'ALL OK');
    process.exit(failed ? 1 : 0);
  }
  await p.goto(BASE + '/admin');
  const t0 = Date.now();
  await p.click('[data-spc-start]');
  await p.waitForFunction(() => /Done|stopped/i.test(document.querySelector('[data-spc-say]').textContent), null, { timeout: 240000 });
  const took = Math.round((Date.now() - t0) / 1000);
  const saved = JSON.parse(await (await fetch(BASE + '/__saved')).text());
  const log = (await (await fetch(BASE + '/__log')).text()).split('\n').filter(Boolean);
  const states = JSON.parse(await (await fetch(BASE + '/__states')).text());
  const notes = await p.$$eval('[data-spc-note]', (l) => l.map((x) => x.textContent));
  const nav = saved[0] ? saved[0].nav : {};
  console.log('    took', took, 's; nav', JSON.stringify(nav));
  ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
  ok(took < 180, 'the whole audit in under 3 minutes (' + took + ' s)');
  ok(await p.evaluate(() => document.cookie.indexOf('spc_audit=') === -1), 'the audit cookie is gone afterwards');
  const starts = log.filter((l) => l.startsWith('start '));
  const navs = log.filter((l) => l.startsWith('nav ') && !/purpose=prefetch/.test(l));
  // 4 modes × (1 warm-up + 3 counted rounds)
  ok(starts.length === 16 && navs.length >= 12, 'clicks: 16 start pages, ' + navs.length + ' arrivals (InstantNav fetches its own)');
  const order = starts.map((l) => l.split('cookie=')[1].split(' ')[0]);
  const rounds = [0, 1, 2, 3].map((r) => order.slice(r * 4, r * 4 + 4).join(','));
  console.log('    order per round', JSON.stringify(rounds));
  ok(rounds.every((r) => r.split(',').sort().join() === 'all,nav_instantnav,nav_off,nav_smartprefetch') && new Set(rounds.map((r) => r.split(',')[0])).size === 4,
    'every round takes every mode once, and each round starts with a different one');
  const after = states.slice(1);
  ok(after.length >= 14 && after.every((s) => s.sw === '0' && s.caches === '0' && s.session === '0'),
    'between clicks the shop window is emptied: no service worker, Cache Storage or session storage left (' + after.length + ' checks)');
  if (MODE === 'normal') {
    ok(['off', 'smartprefetch', 'instantnav', 'all'].every((m) => typeof nav[m] === 'number'), 'every mode measured and saved');
    ok(nav.off > nav.instantnav && nav.off > nav.smartprefetch, 'the speed-ups are faster than no speed-ups');
    ok(!notes.some((n) => /page cache/.test(n)), 'no page-cache warning');
    const card = (part) => p.$eval('[data-spc-part="' + part + '"]', (c) => ({ bars: c.querySelector('[data-spc-bars]').innerText.replace(/\s+/g, ' '), gain: c.querySelector('[data-spc-gain]').textContent, note: c.querySelector('[data-spc-note]').textContent }));
    await p.waitForTimeout(1200); // the figures count up
    const pc = await card('pagecache'), opt = await card('optimize');
    console.log('    page cache', JSON.stringify(pc), 'optimize', JSON.stringify(opt));
    ok(saved[0].pagecache && saved[0].pagecache.off === 410 && saved[0].pagecache.on === 12 && /410 ms.*12 ms/.test(pc.bars) && /34x faster/.test(pc.gain),
      'page cache: without and with, over the pages it answered, saved and shown (' + pc.gain + ')');
    ok(saved[0].optimize && saved[0].optimize.off.blocking === 30 && saved[0].optimize.on.eager === 4 && /43 files.*4 files/.test(opt.bars) && /39 fewer files/.test(opt.gain) && /30 to 0/.test(opt.note),
      'Optimize: what holds the page up, without and with, saved and shown');
    const g = await p.$$eval('[data-spc-gain].is-worse', (l) => l.length);
    ok(g === 0, 'no part shown as slower');
  } else {
    ok(!['off', 'smartprefetch', 'instantnav', 'all'].some((m) => typeof nav[m] === 'number'), 'nothing counted when the shop ignored the configuration');
    ok(notes.filter((n) => /page cache/.test(n)).length === 2, 'the page-cache warning on both click cards');
  }
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
