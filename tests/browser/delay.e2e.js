/**
 * SpeedPack Core – Optimize's delayed third-party scripts in Chromium: the page PHP made
 * (SpcHtml::delay) runs none of them while it loads, all of them in the page's order at the first
 * move of the visitor, and all of them after the time limit when the visitor never moves.
 *   node browser/delay.e2e.js MODULE_DIR
 */
const { execFileSync } = require('child_process');
const path = require('path');
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');

let failed = 0;
const ok = (c, m) => { console.log((c ? 'ok  ' : 'FAIL: ') + m); if (!c) failed++; };
const MOD = process.argv[2];

function page(timeout) {
  const php = `define('_PS_VERSION_','x'); require '${MOD}/classes/SpcHtml.php';
    $ext = 'data:text/javascript,' . rawurlencode('window.order.push("ext"); // googletagmanager.com');
    $h = '<html><head><script>window.order=[];</script><script async src="' . $ext . '&tracker=googletagmanager.com"></script>'
       . '<script>window.order.push("inline-tracker");gtag("config","G-1");</script></head><body><p>shop</p>'
       . '<script>window.order.push("shop");</script><script>window.order.push("fbq-tracker");</script></body></html>';
    echo SpcHtml::delay($h, ['googletagmanager.com', 'gtag(', 'fbq-tracker'], ${timeout});`;
  return execFileSync('php', ['-r', php]).toString();
}

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage();
  const errors = [];
  p.on('pageerror', (e) => errors.push(e.message));
  await p.setContent(page(0));
  await p.waitForTimeout(400);
  ok(JSON.stringify(await p.evaluate(() => window.order)) === '["shop"]', 'while the page loads only the shop own script runs');
  ok(await p.evaluate(() => typeof window.gtag === 'function' && Array.isArray(window.dataLayer)), 'gtag() is there already (the stand-in)');
  await p.mouse.move(40, 40);
  await p.waitForTimeout(500);
  ok(JSON.stringify(await p.evaluate(() => window.order)) === '["shop","ext","inline-tracker","fbq-tracker"]', 'at the first move: every delayed script, in the page order (an address waited for before the next) ' + JSON.stringify(await p.evaluate(() => window.order)));
  ok(await p.evaluate(() => window.dataLayer.length >= 1), 'the tag call reached dataLayer');
  await p.mouse.move(80, 80);
  await p.waitForTimeout(200);
  ok((await p.evaluate(() => window.order.length)) === 4, 'run once only');

  const q = await b.newPage();
  await q.setContent(page(1));
  await q.waitForTimeout(300);
  ok((await q.evaluate(() => window.order.length)) === 1, 'with a time limit: still waiting at first');
  await q.waitForTimeout(1300);
  ok((await q.evaluate(() => window.order.length)) === 4, 'and run when the time is up, with no move at all');
  ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
  await b.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
