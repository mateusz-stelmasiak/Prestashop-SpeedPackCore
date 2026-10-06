// The cart page's instant quantity change in a real Chromium (SpeedPack Core's InstantCart and
// AsyncCart), on Classic's markup: after the change, every summary line shows its own value once,
// and the small text under the shipping price (displayCheckoutSubtotalDetails) stays as it was.
//   node cart.e2e.js PORT
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');
const BASE = 'http://127.0.0.1:' + process.argv[2];
let failed = 0;
const ok = (c, what) => { console.log((c ? 'ok  ' : 'FAIL: ') + what); if (!c) failed++; };

(async () => {
  const b = await chromium.launch(process.env.SPC_CHROME ? { executablePath: process.env.SPC_CHROME } : {});
  for (const script of ['instantcart', 'asynccart']) {
    const p = await b.newPage();
    const errors = [];
    p.on('pageerror', (e) => errors.push(e.message));
    await p.goto(BASE + '/ic-cart?script=' + script);
    await p.click('.bootstrap-touchspin-up');
    await p.click('.bootstrap-touchspin-up');
    await p.waitForTimeout(1500);
    const st = await p.evaluate(() => ({
      qty: document.querySelector('.js-cart-line-product-quantity').value,
      shipping: document.querySelector('#cart-subtotal-shipping').textContent.replace(/\s+/g, ' ').trim(),
      small: document.querySelector('#cart-subtotal-shipping small').textContent,
      products: document.querySelector('#cart-subtotal-products > .value').textContent,
      total: document.querySelector('.cart-total > .value').textContent
    }));
    console.log('    ' + script, JSON.stringify(st));
    ok(st.qty === '3' && st.products === '30,00 zł' && st.total === '30,00 zł', script + ': two taps on +, one request, the summary follows');
    ok(st.shipping.split('Za darmo!').length === 2 && st.small === 'Dostawa w 2 dni', script + ': the shipping price once, the text under it kept');
    ok(errors.length === 0, script + ': no script errors ' + JSON.stringify(errors));
    await p.close();
  }
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
