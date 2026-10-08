/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * "Warm the whole catalogue now" on the page cache's tab: the catalogue's addresses are listed
 * (SpcWarm::catalogue) and opened by the server four at a time, with a bar and a count.
 */
(function () {
  'use strict';

  var BATCH = 4;

  function fmt(s) {
    var args = Array.prototype.slice.call(arguments, 1), i = 0;
    return String(s || '').replace(/%(\d)\$[sd]|%[sd]/g, function (m, pos) { return String(pos ? args[pos - 1] : args[i++]); });
  }

  function post(url, data) {
    var body = new FormData();
    body.append('spc_ajax', 'warm');
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }

  function start() {
    var box = document.getElementById('spc-warm');
    if (!box || !window.fetch) { return; }
    var btn = box.querySelector('[data-spc-warm]');
    var state = box.querySelector('[data-spc-warm-state]');
    var bar = box.querySelector('[data-spc-warm-bar]');
    var fill = bar.querySelector('span');
    var url = box.getAttribute('data-url');
    var t = {};
    try { t = JSON.parse(box.getAttribute('data-texts')) || {}; } catch (e) { t = {}; }

    btn.addEventListener('click', function () {
      btn.disabled = true;
      bar.hidden = false;
      fill.style.width = '0%';
      state.textContent = t.planning;
      var built = 0, ready = 0, done = 0, total = 0;
      post(url, { op: 'plan' }).then(function (plan) {
        if (plan.error) { throw new Error(plan.error === 'off' ? t.off : plan.error); }
        var urls = plan.urls || [];
        total = urls.length;
        var chain = Promise.resolve();
        for (var i = 0; i < urls.length; i += BATCH) {
          (function (part) {
            chain = chain.then(function () {
              return post(url, { op: 'batch', urls: JSON.stringify(part) }).then(function (a) {
                (a.done || []).forEach(function (d) {
                  // the computer's answer: HIT was there already, MISS was built now
                  if ((d.states || [])[0] === 'HIT' || (d.states || [])[0] === 'HIT cart') { ready++; } else { built++; }
                });
                done += part.length;
                fill.style.width = Math.round(100 * done / Math.max(1, total)) + '%';
                state.textContent = fmt(t.progress, done, total);
              });
            });
          })(urls.slice(i, i + BATCH));
        }
        return chain;
      }).then(function () {
        state.textContent = fmt(t.done, total, built, ready);
      }).catch(function (e) {
        state.textContent = fmt(t.failed, e.message);
      }).then(function () {
        btn.disabled = false;
      });
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
