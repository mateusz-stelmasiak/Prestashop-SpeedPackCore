// Optimize in a real Chromium: the back-office panel (pictures converted in steps, critical CSS
// made from a shop page at computer and phone width), the page with that critical CSS, and a page
// after Optimize's steps (scripts deferred and still run in their order, lazy pictures).
//   node optimize.e2e.js OUT_DIR MODULE_DIR       (OUT_DIR made by render-opt.php)
const { chromium } = require(process.env.SPC_PLAYWRIGHT || 'playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const OUT = process.argv[2];
const MOD = process.argv[3];
let failed = 0;
const ok = (c, what) => { console.log((c ? 'ok  ' : 'FAIL: ') + what); if (!c) failed++; };

const CSS = `@font-face{font-family:T;src:url(fonts/t.woff2) format("woff2")}
html{margin:0}body{margin:0;font-family:T,Arial}
#header{height:80px;background:#143848}
.btn-top{color:red}.btn-top:hover{color:blue}
.hero{height:300px;background:url(img/hero.jpg)}
.gone{display:none}
.far{margin-top:3000px;color:green}
.unused-thing{color:pink}
@media (max-width:600px){.hero{height:120px}.far{color:olive}}
@media (min-width:1000px){.hero{padding:10px}}
::selection{background:yellow}`;
const saved = {};
let port;
const server = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  if (req.method === 'POST' && u.pathname === '/__opt') {
    let body = '';
    req.on('data', (d) => { body += d; });
    req.on('end', () => {
      const field = (n) => { const m = body.match(new RegExp('name="' + n + '"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--')); return m ? m[1] : null; };
      const op = field('op');
      let a;
      if (op === 'images') {
        a = field('offset') === '0' ? { offset: 15, total: 31, files: 20, saved: 200000, done: false } : { offset: 31, total: 31, files: 5, saved: 50000, done: true };
      } else if (op === 'critical_plan') {
        a = { pages: { index: '/shop-page.html?spc_nocrit=1&spc_nocache=1', category: '/shop-page.html?spc_nocrit=1&spc_nocache=1&c=1' } };
      } else if (op === 'critical_save') {
        saved[field('page')] = { css: field('css'), hrefs: JSON.parse(field('hrefs')) };
        a = { ok: true, bytes: field('css').length };
      } else { a = { error: 'unknown' }; }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(a));
    });
    return;
  }
  let file = null, type = 'text/html';
  if (u.pathname.startsWith('/modules/speedpackcore/')) { file = path.join(MOD, u.pathname.slice('/modules/speedpackcore/'.length)); }
  else if (/^\/[\w-]+\.html$/.test(u.pathname)) { file = path.join(OUT, u.pathname); }
  if (u.pathname === '/shop-css/theme.css') { res.writeHead(200, { 'Content-Type': 'text/css' }); res.end(CSS); return; }
  if (u.pathname === '/shop-css/print.css') { res.writeHead(200, { 'Content-Type': 'text/css' }); res.end('.x{color:red}'); return; }
  if (u.pathname === '/shop-js/one.js' || u.pathname === '/shop-js/two.js') {
    res.writeHead(200, { 'Content-Type': 'application/javascript' }); res.end("window.order.push('" + (u.pathname.includes('one') ? 'one' : 'two') + "');"); return;
  }
  if (file && fs.existsSync(file)) {
    if (file.endsWith('.js')) type = 'application/javascript'; else if (file.endsWith('.css')) type = 'text/css';
    res.writeHead(200, { 'Content-Type': type }); res.end(fs.readFileSync(file)); return;
  }
  res.writeHead(404); res.end('');
});

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  port = server.address().port;
  const BASE = 'http://127.0.0.1:' + port;
  const b = await chromium.launch(process.env.SPC_CHROME ? { executablePath: process.env.SPC_CHROME } : {});
  const errors = [];
  const page = async (w, h) => { const p = await b.newPage({ viewport: { width: w || 1280, height: h || 900 } }); p.on('pageerror', (e) => errors.push(e.message)); return p; };

  // --- the panel: pictures in steps
  let p = await page();
  await p.goto(BASE + '/admin-opt.html');
  await p.click('[data-spc-convert]');
  await p.waitForFunction(() => /Done/.test(document.querySelector('[data-spc-convert-state]').textContent));
  const conv = await p.evaluate(() => ({ text: document.querySelector('[data-spc-convert-state]').textContent, bar: document.querySelector('[data-spc-convert-bar] span').style.width, off: document.querySelector('[data-spc-convert]').disabled }));
  ok(conv.text === 'Done: 25 copies made, 244 kB smaller.' && conv.bar === '100%' && !conv.off, 'pictures: two steps, the copies and the bytes saved added up (' + conv.text + ')');

  // --- the panel: critical CSS from a real page
  await p.click('[data-spc-critical]');
  await p.waitForFunction(() => /made for|Stopped|blocked/.test(document.querySelector('[data-spc-critical-state]').textContent), null, { timeout: 120000 });
  const state = await p.evaluate(() => document.querySelector('[data-spc-critical-state]').textContent);
  ok(state === 'Critical CSS made for 2 kinds of page.', 'critical CSS made for both pages (' + state + ')');
  const css = saved.index ? saved.index.css : '';
  console.log('    critical CSS: ' + css.length + ' bytes: ' + css.slice(0, 600));
  ok(/#header\s*\{/.test(css) && /\.hero\s*\{\s*height: 300px/.test(css) && !/\.btn-top:hover/.test(css), 'kept: the header, the hero; left out: a hover, which the first paint never needs');
  ok(/\.gone\s*\{\s*display: none/.test(css), 'kept: the rule that hides something in the first screen (no flash of it)');
  ok(!/\.far\s*\{\s*margin-top/.test(css) && !/unused-thing/.test(css) && !/::selection/.test(css), 'left out: what is far below, what matches nothing, ::selection');
  ok(/@media \(max-width: 600px\)\s*\{\s*\.hero\s*\{\s*height: 120px/.test(css) && !/olive/.test(css), 'the phone width read too: its @media rule for the hero, not the one for what is below');
  ok(/@media \(min-width: 1000px\)\s*\{\s*\.hero/.test(css), 'the computer-only @media rule for the hero');
  ok(css.includes('url("' + BASE + '/shop-css/fonts/t.woff2")') && css.includes('url("' + BASE + '/shop-css/img/hero.jpg")'), 'font and picture addresses made absolute (the CSS moves into the page)');
  ok(JSON.stringify(saved.index && saved.index.hrefs) === JSON.stringify(['/shop-css/theme.css']), 'saved with the stylesheets it was made from (the print one left out)');
  ok(await p.evaluate(() => /kB/.test(document.querySelector('[data-spc-crit="index"] [data-spc-crit-kb]').textContent) && document.querySelector('[data-spc-crit="cms"] [data-spc-crit-kb]').textContent === '–'), 'the table shows the new sizes');
  ok(await p.evaluate(() => !document.querySelector('[data-spc-frame] iframe')), 'the shop pages read are gone from the panel');
  await p.close();

  // --- the page with that critical CSS (PHP SpcHtml::critical with the saved fingerprint)
  fs.writeFileSync(path.join(OUT, 'saved.json'), JSON.stringify(saved.index));
  execFileSync('php', [path.join(__dirname, 'render-opt.php'), OUT, 'critical', path.join(OUT, 'saved.json')]);
  const critHtml = fs.readFileSync(path.join(OUT, 'shop-page-crit.html'), 'utf8');
  ok(critHtml.includes('<style id="spc-critical">') && critHtml.includes('rel="preload" as="style"') && critHtml.includes('<noscript><link rel="stylesheet" href="/shop-css/theme.css"'), 'PHP finds the same stylesheets the browser read: the CSS inline, the stylesheet preloaded');
  p = await page();
  await p.goto(BASE + '/shop-page-crit.html');
  await p.waitForLoadState('networkidle');
  const crit = await p.evaluate(() => ({ h: getComputedStyle(document.querySelector('.hero')).height, rel: document.querySelector('link[href="/shop-css/theme.css"]').rel, far: getComputedStyle(document.querySelector('.far')).color }));
  ok(crit.h === '300px' && crit.rel === 'stylesheet' && crit.far === 'rgb(0, 128, 0)', 'the page looks the same, and the full stylesheet arrives (' + JSON.stringify(crit) + ')');
  await p.close();

  // --- a page after Optimize: deferred scripts run in their order, pictures lazy below the first two
  const runs = {};
  for (const name of ['shop-page', 'shop-page-opt']) {
    p = await page();
    await p.goto(BASE + '/' + name + '.html');
    await p.waitForLoadState('load');
    runs[name] = await p.evaluate(() => window.order.join(','));
    if (name === 'shop-page-opt') {
      const st = await p.evaluate(() => ({
        deferred: Array.prototype.every.call(document.querySelectorAll('script[src^="/shop-js/"]'), (s) => s.defer),
        lazy: Array.prototype.map.call(document.querySelectorAll('#wrapper img'), (i) => i.getAttribute('loading') || '-').join(','),
        json: !!document.querySelector('script[type="application/ld+json"]'),
        waited: window.domReadyAtInline,
      }));
      ok(st.deferred && st.json, 'the page\'s scripts wait for it (defer); data blocks left as they are');
      ok(st.waited !== 'loading', 'inline scripts after them ran once the page was there (' + st.waited + ')');
      ok(st.lazy === '-,-,lazy', 'pictures: the first two of the content at once, the rest lazy (' + st.lazy + ')');
    }
    await p.close();
  }
  ok(runs['shop-page'] === 'one,inline-after-one,two,inline-last' && runs['shop-page-opt'] === runs['shop-page'], 'deferred, the scripts still run in the same order (' + runs['shop-page-opt'] + ')');

  ok(errors.length === 0, 'no script errors ' + JSON.stringify(errors));
  await b.close();
  server.close();
  console.log(failed ? failed + ' FAILED' : 'ALL OK');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
