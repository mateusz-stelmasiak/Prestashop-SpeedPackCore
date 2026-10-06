// Reorder's card and the checkout summaries in a real Chromium, styled like a Classic-based theme.
//   node reorder.e2e.js PORT      (shop.py started with REORDER_HTML and CHECKOUT_JSON from tests/php/reorder.php)
// SPC_SHOTS: a folder for screenshots.
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');
const BASE = 'http://127.0.0.1:' + process.argv[2];
const SHOTS = process.env.SPC_SHOTS;
let failed = 0;
const ok = (c, what) => { console.log((c ? 'ok  ' : 'FAIL: ') + what); if (!c) failed++; };

(async () => {
  const b = await chromium.launch(process.env.SPC_CHROME ? { executablePath: process.env.SPC_CHROME } : {});
  const errors = [];
  const page = async (w) => { const p = await b.newPage({ viewport: { width: w, height: 900 }, deviceScaleFactor: 2 }); p.on('pageerror', (e) => errors.push(e.message)); return p; };

  // --- the card, on a computer
  let p = await page(1280);
  await p.goto(BASE + '/reorder-card');
  await p.waitForLoadState('networkidle');
  const card = await p.evaluate(() => {
    const c = document.querySelector('.spc-reorder').getBoundingClientRect();
    const btn = document.querySelector('.spc-reorder-go').getBoundingClientRect();
    const thumbs = Array.prototype.map.call(document.querySelectorAll('.spc-reorder-thumbs img'), (i) => i.complete && i.naturalWidth > 0);
    return { inside: btn.right <= c.right && btn.left >= c.left, row: Math.abs((btn.top + btn.bottom) / 2 - (c.top + c.bottom) / 2) < 30, thumbs, more: !!document.querySelector('.spc-reorder-more'),
      overflow: document.documentElement.scrollWidth > innerWidth, bg: getComputedStyle(document.querySelector('.spc-reorder')).backgroundColor, upper: getComputedStyle(document.querySelector('.spc-reorder-go')).textTransform };
  });
  ok(card.thumbs.length === 2 && card.thumbs.every(Boolean) && card.more, 'the card shows the products\' pictures and "+2"');
  ok(card.inside && card.row && !card.overflow, 'the button sits inside the card, beside the text, nothing spills out');
  ok(card.bg === 'rgb(255, 255, 255)' && card.upper === 'uppercase', 'a white card, and the theme\'s own button (its uppercase kept)');
  if (SHOTS) { await p.locator('.wrap').screenshot({ path: SHOTS + '/reorder-card.png' }); }
  await p.hover('.spc-reorder');
  await p.waitForTimeout(400);
  ok(await p.evaluate(() => getComputedStyle(document.querySelector('.spc-reorder')).transform !== 'none'), 'it lifts a little under the pointer');
  await p.close();

  // --- the card, on a phone
  p = await page(390);
  await p.goto(BASE + '/reorder-card');
  await p.waitForLoadState('networkidle');
  const phone = await p.evaluate(() => {
    const c = document.querySelector('.spc-reorder').getBoundingClientRect();
    const btn = document.querySelector('.spc-reorder-go').getBoundingClientRect();
    return { overflow: document.documentElement.scrollWidth > innerWidth, wide: btn.width > c.width * 0.75, below: btn.top > document.querySelector('.spc-reorder-text').getBoundingClientRect().bottom - 1 };
  });
  ok(!phone.overflow && phone.wide && phone.below, 'on a phone: one column, a full-width button under the text, no sideways scroll');
  if (SHOTS) { await p.locator('.wrap').screenshot({ path: SHOTS + '/reorder-card-phone.png' }); }
  await p.close();

  // --- the checkout: steps 1-3 done, payment open
  for (const w of [1280, 390]) {
    p = await page(w);
    await p.goto(BASE + '/pl/zamowienie?summary=1&done=3');
    await p.waitForSelector('.spc-step-summary');
    await p.waitForTimeout(400);
    const st = await p.evaluate(() => Array.prototype.map.call(document.querySelectorAll('.checkout-step'), (s) => {
      const sum = s.querySelector('.spc-step-summary');
      const title = s.querySelector('.step-title');
      let textLeft = 0;
      const walker = document.createTreeWalker(title, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) { if (n.nodeValue.trim() && !n.parentNode.closest('.step-number,.step-edit,.material-icons')) { const r = document.createRange(); r.selectNodeContents(n); textLeft = r.getBoundingClientRect().left; break; } }
      return { id: s.id, text: sum ? sum.textContent : null, shown: !!sum && getComputedStyle(sum).display !== 'none' && sum.offsetHeight > 0,
        aligned: sum ? Math.abs(sum.getBoundingClientRect().left + parseFloat(getComputedStyle(sum).paddingLeft) - textLeft) < 3 : null, fits: sum ? sum.getBoundingClientRect().right <= s.getBoundingClientRect().right + 1 : null };
    }));
    console.log('    ' + w + 'px', JSON.stringify(st.map((x) => [x.shown, x.aligned, x.text && x.text.slice(0, 40)])));
    ok(st.slice(0, 3).every((x) => x.shown && x.aligned && x.fits) && /Anna Kowalska · anna@example.com/.test(st[0].text) && /Warszawa/.test(st[1].text) && /Kurier 8 · gratis/.test(st[2].text),
      w + ' px: each finished step shows its data under the title, lined up with its words');
    ok(!st[3].shown, w + ' px: the open step (payment) shows none');
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), w + ' px: nothing spills sideways');
    if (SHOTS) { await p.locator('section#checkout').screenshot({ path: SHOTS + '/checkout-summaries-' + w + '.png' }); }
    await p.close();
  }
  ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
