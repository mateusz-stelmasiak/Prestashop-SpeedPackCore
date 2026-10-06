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
    const list = document.querySelector('.spc-reorder-items').getBoundingClientRect();
    const main = document.querySelector('.spc-reorder-main').getBoundingClientRect();
    const imgs = Array.prototype.map.call(document.querySelectorAll('.spc-reorder-items img'), (i) => i.complete && i.naturalWidth > 0);
    const icons = Array.prototype.map.call(document.querySelectorAll('.spc-reorder svg'), (s) => Math.round(s.getBoundingClientRect().width));
    return { inside: btn.right <= c.right && btn.left >= c.left && btn.bottom <= c.bottom, right: list.left > main.right - 1 && list.right <= c.right, rows: document.querySelectorAll('.spc-reorder-items li').length,
      imgs, icons, overflow: document.documentElement.scrollWidth > innerWidth, bg: getComputedStyle(document.querySelector('.spc-reorder')).backgroundColor, upper: getComputedStyle(document.querySelector('.spc-reorder-go')).textTransform };
  });
  console.log('    card', JSON.stringify(card));
  ok(card.rows === 5 && card.imgs.length === 2 && card.imgs.every(Boolean), 'the card lists the products (four and "1 more"), with their pictures');
  ok(card.right && card.inside && !card.overflow, 'the list on the right, the button inside the card under the text, nothing spills out');
  ok(card.icons.every((w) => w <= 20), 'the icons stay small (' + card.icons.join(', ') + ' px)');
  ok(card.bg === 'rgb(255, 255, 255)' && card.upper === 'uppercase', 'a white card, and the theme\'s own button (its uppercase kept)');
  if (SHOTS) { await p.locator('.wrap').screenshot({ path: SHOTS + '/reorder-card.png' }); }
  // without the stylesheet (a stale combined CSS): still no giant icons
  await p.evaluate(() => document.querySelector('link[href*="reorder.css"]').remove());
  await p.waitForTimeout(200);
  ok(await p.evaluate(() => Array.prototype.every.call(document.querySelectorAll('.spc-reorder svg'), (s) => s.getBoundingClientRect().width <= 20)), 'even with no stylesheet at all, the icons stay small');
  await p.close();

  // --- the card, on a phone
  p = await page(390);
  await p.goto(BASE + '/reorder-card');
  await p.waitForLoadState('networkidle');
  const phone = await p.evaluate(() => {
    const c = document.querySelector('.spc-reorder').getBoundingClientRect();
    const btn = document.querySelector('.spc-reorder-go').getBoundingClientRect();
    return { overflow: document.documentElement.scrollWidth > innerWidth, wide: btn.width > c.width * 0.75, below: document.querySelector('.spc-reorder-items').getBoundingClientRect().top > btn.bottom - 1 };
  });
  ok(!phone.overflow && phone.wide && phone.below, 'on a phone: one column, a full-width button, the list under it, no sideways scroll');
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
    const cl = await p.evaluate(() => {
      const box = document.getElementById('spc-checkout-cart');
      const col = document.querySelector('.cart-grid-right');
      return box && { first: col.firstElementChild === box, rows: box.querySelectorAll('li').length, shown: Array.prototype.filter.call(box.querySelectorAll('li'), (l) => l.offsetHeight > 0).length,
        head: box.querySelector('.spc-ccart-head').textContent, more: (box.querySelector('.spc-ccart-more') || {}).textContent, line: box.querySelector('li').textContent,
        fits: box.getBoundingClientRect().right <= col.getBoundingClientRect().right + 1 };
    });
    ok(cl && cl.first && cl.rows === 9 && cl.shown === 5 && cl.head === 'W koszyku27 sztuk' && cl.more === 'Pokaż wszystkie (9)' && cl.fits, w + ' px: the cart\'s products at the top of the side column, five shown ' + JSON.stringify(cl));
    ok(/Produkt 7.*2 × 10,00 zł.*20,00 zł/.test(cl.line), w + ' px: a line: name, quantity × price, total');
    if (SHOTS) { await p.locator('.cart-grid-right').screenshot({ path: SHOTS + '/checkout-cart-' + w + '.png' }); }
    await p.click('.spc-ccart-more');
    ok(await p.evaluate(() => Array.prototype.filter.call(document.querySelectorAll('#spc-checkout-cart li'), (l) => l.offsetHeight > 0).length === 9 && document.querySelector('.spc-ccart-more').textContent === 'Pokaż mniej'), w + ' px: "show all" opens the rest');
    await p.evaluate(() => { const h = window.prestashop && window.prestashop._h && window.prestashop._h.updatedCart; (h || []).forEach((f) => f({})); });
    await p.waitForTimeout(800);
    ok(await p.evaluate(() => document.querySelector('#spc-checkout-cart li').textContent.indexOf('Kapusta kiszona') !== -1 && document.querySelector('.spc-ccart-count').textContent === '28 sztuk'), w + ' px: after the cart changed on the page, the list is asked for again');
    if (SHOTS) { await p.locator('section#checkout').screenshot({ path: SHOTS + '/checkout-summaries-' + w + '.png' }); }
    await p.close();
  }
  // --- a finished step opens from a click anywhere on it, not only on "edit"
  for (const core of [false, true]) {
    p = await page(1280);
    await p.goto(BASE + '/pl/zamowienie?summary=1&done=3');
    await p.waitForSelector('.spc-step-summary');
    if (core) {
      // PrestaShop's own handler (core checkout.js): the steps before the current one open on a click
      await p.evaluate(() => {
        const steps = Array.prototype.slice.call(document.querySelectorAll('.checkout-step'));
        steps.slice(0, 3).forEach((s) => { s.classList.add('-clickable'); s.addEventListener('click', () => {
          steps.forEach((x) => x.classList.remove('-current', 'js-current-step')); s.classList.add('-current', 'js-current-step');
        }); });
      });
    }
    const current = () => p.evaluate(() => Array.prototype.map.call(document.querySelectorAll('.checkout-step'), (s) => s.classList.contains('-current') ? 1 : 0).join(''));
    const how = core ? ' (with PrestaShop\'s own handler)' : ' (without it)';
    ok(await p.evaluate(() => getComputedStyle(document.querySelector('#checkout-addresses-step')).cursor === 'pointer' && document.querySelector('#checkout-addresses-step .step-title').getAttribute('role') === 'button'), 'a closed step shows the hand anywhere on it, and is a button for the keyboard' + how);
    await p.click('#checkout-addresses-step .spc-step-summary');
    await p.waitForTimeout(600);
    ok(await current() === '0100' && await p.evaluate(() => document.querySelector('#checkout-addresses-step .content').offsetHeight > 0 && document.querySelector('#checkout-addresses-step .content').style.height === ''), 'a click on the summary text opens the step, and it unfolds fully' + how);
    const box = await p.locator('#checkout-personal-information-step').boundingBox();
    await p.mouse.click(box.x + box.width - 40, box.y + box.height - 6);
    await p.waitForTimeout(600);
    ok(await current() === '1000', 'a click on an empty corner of a step opens it too' + how);
    await p.focus('#checkout-delivery-step .step-title');
    await p.keyboard.press('Enter');
    await p.waitForTimeout(600);
    ok(await current() === '0010', 'Enter on a focused step opens it' + how);
    await p.click('#checkout-delivery-step .content');
    await p.waitForTimeout(300);
    ok(await current() === '0010', 'clicks inside the open step change nothing');
    await p.close();
  }

  ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
