// The speed audit's click test in a real Chromium against tests/browser/shop.py.
//   node audit.e2e.js PORT normal      every mode is a different configuration: all counted
//   node audit.e2e.js PORT pagecache   the shop ignores the cookie: nothing counted, a warning
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
  } else {
    ok(!['off', 'smartprefetch', 'instantnav', 'all'].some((m) => typeof nav[m] === 'number'), 'nothing counted when the shop ignored the configuration');
    ok(notes.filter((n) => /page cache/.test(n)).length === 2, 'the page-cache warning on both click cards');
  }
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
