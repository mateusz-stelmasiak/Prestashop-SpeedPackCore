/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * The speed audit on the settings page, in about two minutes:
 *
 *   1. the click test, in a shop window opened from here: three menu clicks after a 0.3 s hover,
 *      with no speed-ups, with SmartPrefetch, with InstantNav and with everything, timed from the
 *      click to the first paint of the new page (or to the swap, for InstantNav);
 *   2. five pages answered by the server without the data cache and with it;
 *   3. adding to the cart through PrestaShop's cart page and through the lean endpoint;
 *   4. the address lookups of a cart page, counted with CartSpeed off and on.
 *
 * "Without" is a signed cookie (made by the module, valid 15 minutes) that tells the shop to leave
 * parts out for the requests that carry it; nothing is switched off for customers. The server
 * steps (2-4) run in the module (classes/SpcAudit.php); this file asks for them one by one and
 * draws the results.
 */
(function () {
  'use strict';

  var HOVER = 300;          // how long the pointer rests on a link before the click
  var CLICKS = 3;           // clicks per mode; the median is kept
  var SETTLE = 900;         // after a page loads: scripts start, the visitor looks around
  var PER_CLICK = 15000;    // longest wait for a page

  var NS = 'http://www.w3.org/2000/svg';

  function start() {
    var box = document.getElementById('spc-audit');
    if (!box || !window.fetch || !window.Promise || !window.FormData) return;

    var url = box.getAttribute('data-spc-url');
    var home = box.getAttribute('data-spc-home');
    var t = parseJson(box.getAttribute('data-spc-texts'), {});
    var history = parseJson(box.getAttribute('data-spc-history'), []);
    var button = box.querySelector('[data-spc-start]');
    var buttonLabel = box.querySelector('[data-spc-start-label]');
    var progress = box.querySelector('[data-spc-progress]');
    var fill = box.querySelector('[data-spc-fill]');
    var say = box.querySelector('[data-spc-say]');
    var clock = box.querySelector('[data-spc-clock]');
    var running = false;

    /* ---------------------------------------------------------------- *
     *  Small helpers
     * ---------------------------------------------------------------- */

    function parseJson(text, fallback) {
      try { var v = JSON.parse(text || ''); return v === null ? fallback : v; } catch (e) { return fallback; }
    }
    function fmt(text) {
      var args = Array.prototype.slice.call(arguments, 1), i = 0;
      return String(text || '').replace(/%(\d+\$)?[sd]/g, function (m, pos) {
        return String(pos ? args[parseInt(pos, 10) - 1] : args[i++]);
      });
    }
    function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
    function median(list) {
      var s = list.filter(function (v) { return typeof v === 'number' && isFinite(v); }).sort(function (a, b) { return a - b; });
      if (!s.length) return null;
      var m = Math.floor(s.length / 2);
      return s.length % 2 ? s[m] : Math.round((s[m - 1] + s[m]) / 2);
    }
    function num(v) { return Math.round(v).toLocaleString(); }
    function el(tag, cls, text) {
      var n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text !== undefined) n.textContent = text;
      return n;
    }
    function svg(tag, attrs) {
      var n = document.createElementNS(NS, tag);
      Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
      return n;
    }
    function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
    function card(part) { return box.querySelector('[data-spc-part="' + part + '"]'); }
    function state(part, name) {
      var c = card(part);
      if (!c) return;
      c.classList.remove('is-running', 'is-done', 'is-skipped');
      if (name) c.classList.add('is-' + name);
    }

    function post(step, fields) {
      var body = new FormData();
      body.append('spc_ajax', 'audit');
      body.append('step', step);
      Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
      return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (r) { if (!r || typeof r !== 'object') throw new Error('unexpected answer'); return r; });
    }

    /* ---------------------------------------------------------------- *
     *  Drawing
     * ---------------------------------------------------------------- */

    /** Rows of {label, value, text, cls}: horizontal bars that grow in, longest = full width. */
    function bars(node, rows) {
      clear(node);
      var max = Math.max.apply(null, rows.map(function (r) { return r.value || 0; }).concat([1]));
      var fills = [];
      rows.forEach(function (r) {
        node.appendChild(el('span', 'spc-bar-label', r.label));
        var track = el('div', 'spc-bar-track');
        var f = el('div', 'spc-bar-fill' + (r.cls ? ' ' + r.cls : ''));
        track.appendChild(f);
        node.appendChild(track);
        var value = el('span', 'spc-bar-value', '');
        node.appendChild(value);
        fills.push([f, Math.max(2, Math.round(100 * (r.value || 0) / max)), value, r]);
      });
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          fills.forEach(function (x) {
            x[0].style.width = x[1] + '%';
            countUp(x[2], x[3].value, x[3].unit);
          });
        });
      });
    }

    function countUp(node, target, unit) {
      if (typeof target !== 'number') { node.textContent = '-'; return; }
      var t0 = performance.now(), d = 900;
      (function frame(now) {
        var k = Math.min(1, (now - t0) / d), e = 1 - Math.pow(1 - k, 3);
        var v = Math.round(target * e);
        node.textContent = fmt(typeof unit === 'object' ? (v === 1 ? unit.one : unit.many) : unit, num(v));
        if (k < 1) requestAnimationFrame(frame);
      })(t0);
    }

    /** "2.4x faster" (or "12 fewer queries"), or "about the same" when the gain is within noise. */
    function gain(part, before, after, queries) {
      var c = card(part);
      if (!c) return;
      var g = c.querySelector('[data-spc-gain]');
      g.classList.remove('is-flat');
      if (typeof before !== 'number' || typeof after !== 'number') { g.textContent = ''; return; }
      if (queries) {
        if (before - after >= 1) { g.textContent = fmt(t.fewer, num(before - after)); } else { g.classList.add('is-flat'); g.textContent = t.same; }
        return;
      }
      if (after > 0 && before / after >= 1.1 && before - after >= 10) {
        var x = before / after;
        g.textContent = fmt(t.faster, x >= 10 ? Math.round(x) : x.toFixed(1));
      } else {
        g.classList.add('is-flat');
        g.textContent = t.same;
      }
    }

    function note(part, text) {
      var c = card(part);
      if (c) c.querySelector('[data-spc-note]').textContent = text || '';
    }

    function pair(part, before, after, unit) {
      var c = card(part);
      if (!c) return;
      bars(c.querySelector('[data-spc-bars]'), [
        { label: t.without, value: before, unit: unit || t.ms, cls: '' },
        { label: t.with, value: after, unit: unit || t.ms, cls: 'is-on' }
      ]);
    }

    function hero(nav) {
      var rows = [];
      [['off', t.clickWithout], ['smartprefetch', t.clickSp], ['instantnav', t.clickNav], ['all', t.clickAll]].forEach(function (m) {
        if (typeof nav[m[0]] === 'number') rows.push({ label: m[1], value: nav[m[0]], unit: t.ms, cls: m[0] === 'off' ? '' : (m[0] === 'all' ? 'is-best' : 'is-on') });
      });
      var box2 = box.querySelector('[data-spc-hero]');
      if (rows.length < 2) { box2.hidden = true; return; }
      box2.hidden = false;
      box.querySelector('[data-spc-hero-title]').textContent = t.heroTitle;
      bars(box.querySelector('[data-spc-hero-bars]'), rows);
    }

    /** A whole audit's numbers on the cards (a fresh one, or the last saved one on page load). */
    function show(run, plan) {
      var enabled = (plan && plan.enabled) || {};
      if (run.pages) {
        pair('cache', run.pages.off, run.pages.on);
        gain('cache', run.pages.off, run.pages.on);
        state('cache', 'done');
        if (plan && !enabled.cache) note('cache', t.noCache);
        else if ((plan ? plan.cache : run.cache) && (plan ? plan.cache : run.cache) !== 'redis') note('cache', t.noBypass);
      }
      var nav = run.nav || {};
      ['smartprefetch', 'instantnav'].forEach(function (part) {
        if (typeof nav[part] === 'number' && typeof nav.off === 'number') {
          pair(part, nav.off, nav[part]);
          gain(part, nav.off, nav[part]);
          state(part, 'done');
          if (part === 'smartprefetch') note(part, t.prerenderNote);
        } else if (plan && enabled[part] === false) {
          note(part, t.switchedOff);
          state(part, 'skipped');
        }
      });
      if (run.nav) hero(nav);
      if (run.cart) {
        pair('instantcart', run.cart.core, run.cart.lean);
        gain('instantcart', run.cart.core, run.cart.lean);
        state('instantcart', 'done');
        if (plan && enabled.instantcart === false) note('instantcart', t.switchedOff);
      }
      if (run.cartspeed) {
        pair('cartspeed', run.cartspeed.off, run.cartspeed.on, { many: t.queries, one: t.query });
        gain('cartspeed', run.cartspeed.off, run.cartspeed.on, true);
        state('cartspeed', 'done');
        if (plan && enabled.cartspeed === false) note('cartspeed', t.switchedOff);
      }
    }

    /** The click and server times of every saved audit, as two lines. */
    function drawHistory(list) {
      var wrap = box.querySelector('[data-spc-history-box]');
      var holder = box.querySelector('[data-spc-history-chart]');
      var a = list.map(function (r) { return r.nav && typeof r.nav.all === 'number' ? r.nav.all : null; });
      var b = list.map(function (r) { return r.pages && typeof r.pages.on === 'number' ? r.pages.on : null; });
      if (list.length < 2 || !a.concat(b).some(function (v) { return v !== null; })) { wrap.hidden = true; return; }
      wrap.hidden = false;
      box.querySelector('[data-spc-history-title]').textContent = t.historyTitle;
      clear(holder);
      var W = Math.max(300, holder.clientWidth || 640), H = 200, L = 56, R = 24, T = 12, B = 28;
      var max = Math.max.apply(null, a.concat(b).filter(function (v) { return v !== null; }).concat([10]));
      max = Math.ceil(max / 50) * 50;
      var x = function (i) { return L + (list.length === 1 ? 0 : i * (W - L - R) / (list.length - 1)); };
      var y = function (v) { return T + (H - T - B) * (1 - v / max); };
      var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img' });
      // one day: the time of each audit; several days: the date
      var oneDay = list.every(function (r) { return String(r.at || '').slice(0, 10) === String(list[0].at || '').slice(0, 10); });
      [0, 0.5, 1].forEach(function (k) {
        var v = Math.round(max * k);
        s.appendChild(svg('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), 'class': 'spc-axis' }));
        var label = svg('text', { x: L - 6, y: y(v) + 4, 'text-anchor': 'end', 'class': 'spc-tick' });
        label.textContent = fmt(t.ms, num(v));
        s.appendChild(label);
      });
      list.forEach(function (r, i) {
        if (i % Math.ceil(list.length / 6) && i !== list.length - 1) return;
        var anchor = i === 0 ? 'start' : (i === list.length - 1 ? 'end' : 'middle');
        var label = svg('text', { x: x(i), y: H - 8, 'text-anchor': anchor, 'class': 'spc-tick' });
        label.textContent = oneDay ? String(r.at || '').slice(11, 16) : String(r.at || '').slice(5, 10);
        s.appendChild(label);
      });
      [[a, 'a'], [b, 'b']].forEach(function (series) {
        var d = '';
        series[0].forEach(function (v, i) { if (v !== null) d += (d ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1); });
        if (d) s.appendChild(svg('path', { d: d, 'class': 'spc-line spc-line-' + series[1] }));
        series[0].forEach(function (v, i) { if (v !== null) s.appendChild(svg('circle', { cx: x(i), cy: y(v), r: 4, 'class': 'spc-pt spc-pt-' + series[1] })); });
      });
      holder.appendChild(s);
      var legend = el('div', 'spc-history-legend');
      [[t.historyClick, 'var(--spc-on)'], [t.historyPage, 'var(--spc-good)']].forEach(function (l) {
        var item = el('span', '', '');
        var swatch = el('i');
        swatch.style.background = l[1];
        item.appendChild(swatch);
        item.appendChild(document.createTextNode(l[0]));
        legend.appendChild(item);
      });
      holder.appendChild(legend);
    }

    /* ---------------------------------------------------------------- *
     *  The click test, in a window of the shop
     * ---------------------------------------------------------------- */

    function clickTest(popup, plan, onClick) {
      var modes = [['off', 'nav_off']];
      if (plan.enabled.smartprefetch) modes.push(['smartprefetch', 'nav_smartprefetch']);
      if (plan.enabled.instantnav) modes.push(['instantnav', 'nav_instantnav']);
      if (modes.length > 1) modes.push(['all', 'all']);
      var results = {};
      var chain = Promise.resolve();

      function setCookie(value, age) {
        document.cookie = plan.cookie + '=' + value + '; path=/; max-age=' + age + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      }

      function doc() { try { return popup.document; } catch (e) { return null; } }

      function load(target) {
        var old = doc();
        popup.location.href = target;
        var t0 = Date.now();
        return new Promise(function (resolve, reject) {
          (function poll() {
            if (popup.closed) return reject(new Error('window closed'));
            var d = doc();
            if (d && d !== old && d.readyState === 'complete' && String(popup.location.href).indexOf('http') === 0) return resolve(d);
            if (Date.now() - t0 > 20000) return reject(new Error('the shop page did not load'));
            setTimeout(poll, 50);
          })();
        });
      }

      function links(d) {
        var seen = {}, out = [];
        var here = String(popup.location.href).split('#')[0];
        var list = [];
        try { list = d.querySelectorAll(plan.links); } catch (e) { list = []; }
        Array.prototype.forEach.call(list, function (a) {
          if (!a.href || a.target === '_blank' || a.getClientRects().length === 0) return;
          var u;
          try { u = new URL(a.href); } catch (e) { return; }
          var key = u.href.split('#')[0];
          if (u.origin !== location.origin || key === here || seen[key]) return;
          seen[key] = 1;
          out.push(a);
        });
        return out;
      }

      /** Click to first paint (a new page) or to the swap (InstantNav), in ms. */
      function measure(d, a) {
        return new Promise(function (resolve, reject) {
          var done = false;
          var p0 = popup.performance;
          var clickAt = p0.timeOrigin + p0.now();
          function finish(ms) { if (!done) { done = true; resolve(Math.max(0, Math.round(ms))); } }
          d.addEventListener('instantnav:loaded', function () {
            popup.requestAnimationFrame(function () {
              popup.requestAnimationFrame(function () { finish(popup.performance.timeOrigin + popup.performance.now() - clickAt); });
            });
          }, { once: true });
          a.click();
          var t0 = Date.now();
          (function poll() {
            if (done) return;
            if (popup.closed) { done = true; return reject(new Error('window closed')); }
            var nd = doc();
            if (nd && nd !== d) {
              try {
                var p = popup.performance;
                var fcp = p.getEntriesByName('first-contentful-paint')[0];
                var nav = p.getEntriesByType('navigation')[0];
                if (fcp) return finish(p.timeOrigin + Math.max(fcp.startTime, nav && nav.activationStart ? nav.activationStart : 0) - clickAt);
              } catch (e) { /* still arriving */ }
            }
            if (Date.now() - t0 > PER_CLICK) { done = true; return reject(new Error('the page did not show')); }
            setTimeout(poll, 30);
          })();
        });
      }

      function one(mode, k) {
        var start = home + (home.indexOf('?') === -1 ? '?' : '&') + 'spc_start=' + Date.now();
        return load(start).then(function (d) {
          return wait(SETTLE).then(function () {
            var list = links(d);
            if (!list.length) throw new Error(t.noLinks);
            var a = list[k % list.length];
            var u = new URL(a.href);
            // an address no earlier click used, so nothing cached from before can answer it
            u.searchParams.set('spc_nav', mode + '-' + k + '-' + Date.now());
            a.setAttribute('href', u.href);
            ['pointerover', 'mouseover', 'pointerenter', 'mouseenter', 'mousemove'].forEach(function (type) {
              a.dispatchEvent(new popup.MouseEvent(type, { bubbles: type.indexOf('enter') === -1, cancelable: true, view: popup }));
            });
            return wait(HOVER).then(function () { return measure(d, a); });
          });
        });
      }

      modes.forEach(function (m) {
        var times = [];
        for (var k = 0; k < CLICKS; k++) {
          (function (k) {
            chain = chain.then(function () {
              if (k === 0) setCookie(plan.tokens[m[1]], plan.expires);
              onClick(m[0], k);
              return one(m[0], k).then(function (ms) { times.push(ms); });
            });
          })(k);
        }
        chain = chain.then(function () { results[m[0]] = median(times); });
      });

      return chain.then(function () { return results; }, function (e) { results.error = e.message; return results; })
        .then(function (r) {
          setCookie('', 0);
          try { popup.close(); } catch (e) { /* already closed */ }
          return r;
        });
    }

    /* ---------------------------------------------------------------- *
     *  The audit
     * ---------------------------------------------------------------- */

    function run() {
      if (running) return;
      running = true;

      // the window has to open now, while the click still counts as the admin's own
      var popup = null;
      var sameOrigin = false;
      try { sameOrigin = new URL(home, location.href).origin === location.origin; } catch (e) { sameOrigin = false; }
      if (sameOrigin) {
        popup = window.open('', 'spc_audit', 'width=1280,height=860');
        if (popup) {
          try {
            popup.document.title = 'SpeedPack Core';
            popup.document.body.style.font = '16px sans-serif';
            popup.document.body.style.padding = '40px';
            popup.document.body.textContent = t.popupWait;
          } catch (e) { /* a page of its own already */ }
        }
      }

      button.disabled = true;
      buttonLabel.textContent = t.running;
      box.classList.remove('spc-audit--first');
      progress.hidden = false;
      fill.style.width = '0%';
      ['cache', 'smartprefetch', 'instantnav', 'instantcart', 'cartspeed'].forEach(function (p) { state(p, null); note(p, ''); gain(p); clear(card(p).querySelector('[data-spc-bars]')); });
      box.querySelector('[data-spc-hero]').hidden = true;

      var t0 = Date.now();
      var tick = setInterval(function () { clock.textContent = Math.round((Date.now() - t0) / 1000) + ' s'; }, 250);
      var units = 0, total = 1;
      function advance(text) { units++; fill.style.width = Math.min(100, Math.round(100 * units / total)) + '%'; if (text) say.textContent = text; }

      var plan, results = { nav: {} };
      say.textContent = t.plan;

      post('plan').then(function (p) {
        if (p.error) throw new Error(p.error);
        plan = p;
        results.cache = plan.cache;
        var navModes = 1 + (plan.enabled.smartprefetch ? 1 : 0) + (plan.enabled.instantnav ? 1 : 0);
        if (navModes > 1) navModes++;
        total = 1 + (popup ? navModes * CLICKS : 0) + plan.pages.length + 2 + 1;
        advance();

        // 1. clicks
        if (!sameOrigin) { note('smartprefetch', t.otherOrigin); note('instantnav', t.otherOrigin); return null; }
        if (!popup) { note('smartprefetch', t.popupBlocked); note('instantnav', t.popupBlocked); return null; }
        var names = { off: t.clickWithout, smartprefetch: t.clickSp, instantnav: t.clickNav, all: t.clickAll };
        return clickTest(popup, plan, function (mode) {
          state('smartprefetch', mode === 'off' || mode === 'smartprefetch' || mode === 'all' ? 'running' : null);
          state('instantnav', mode === 'off' || mode === 'instantnav' || mode === 'all' ? 'running' : null);
          advance(fmt(t.nav, names[mode]));
        }).then(function (nav) {
          state('smartprefetch', null);
          state('instantnav', null);
          if (nav.error) { note('smartprefetch', fmt(t.failed, nav.error)); note('instantnav', fmt(t.failed, nav.error)); }
          ['off', 'smartprefetch', 'instantnav', 'all'].forEach(function (m) { if (typeof nav[m] === 'number') results.nav[m] = nav[m]; });
          show({ nav: results.nav }, plan);
        });
      }).then(function () {
        // 2. pages, one at a time
        state('cache', 'running');
        var off = [], on = [], chain = Promise.resolve();
        plan.pages.forEach(function (page, i) {
          chain = chain.then(function () {
            advance(fmt(t.page, i + 1, plan.pages.length, page.name));
            return post('page', { i: i }).then(function (r) {
              if (r.error) { note('cache', fmt(t.failed, r.error)); return; }
              off.push(r.off); on.push(r.on);
            });
          });
        });
        return chain.then(function () {
          var avg = function (l) { return l.length ? Math.round(l.reduce(function (s, v) { return s + v; }, 0) / l.length) : null; };
          if (off.length) { results.pages = { off: avg(off), on: avg(on) }; show({ pages: results.pages, cache: plan.cache }, plan); } else state('cache', null);
        });
      }).then(function () {
        // 3. the cart
        state('instantcart', 'running');
        advance(t.cart);
        return post('cart').then(function (r) {
          if (r.error) { state('instantcart', null); note('instantcart', fmt(t.failed, r.error)); return; }
          results.cart = { core: r.core, lean: r.lean };
          show({ cart: results.cart }, plan);
        });
      }).then(function () {
        // 4. address lookups
        state('cartspeed', 'running');
        advance(t.cartspeed);
        return post('cartspeed').then(function (r) {
          if (r.error) { state('cartspeed', null); note('cartspeed', fmt(t.failed, r.error)); return; }
          results.cartspeed = { off: r.off.queries, on: r.on.queries };
          show({ cartspeed: results.cartspeed }, plan);
        });
      }).then(function () {
        advance(t.save);
        return post('save', { results: JSON.stringify(results) }).then(function (r) {
          if (r.history) { history = r.history; drawHistory(history); }
        });
      }).then(function () {
        fill.style.width = '100%';
        say.textContent = fmt(t.done, Math.round((Date.now() - t0) / 1000));
      }, function (e) {
        say.textContent = fmt(t.stopped, e.message);
        try { if (popup && !popup.closed) popup.close(); } catch (x) { /* gone */ }
      }).then(function () {
        clearInterval(tick);
        running = false;
        button.disabled = false;
        buttonLabel.textContent = t.again;
      });
    }

    button.addEventListener('click', run);
    if (history.length) {
      show(history[history.length - 1], null);
      drawHistory(history);
      buttonLabel.textContent = t.again;
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
