/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * The cache warm-up on the settings page: a string of short requests, a few pages each, so none
 * hits PHP's time limit and the page can show how far it got. Starts on its own right after
 * "Empty the cache" when the warm-up setting is on.
 */
(function () {
  'use strict';

  function start() {
    var box = document.getElementById('spc-cache-actions');
    if (!box || !window.fetch || !window.FormData) return;
    var url = box.getAttribute('data-spc-url');
    var job = box.querySelector('[data-spc-job]');
    var bar = box.querySelector('[data-spc-bar]');
    var say = box.querySelector('[data-spc-say]');
    var button = box.querySelector('[data-spc-warmup]');
    var running = false;

    function step(offset) {
      var body = new FormData();
      body.append('spc_ajax', 'warmup');
      body.append('offset', offset);
      fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (r) {
          if (!r || r.ok !== true) throw new Error('unexpected answer');
          bar.style.width = (r.total ? Math.round(100 * r.offset / r.total) : 100) + '%';
          say.textContent = r.message;
          if (r.finished) { running = false; button.disabled = false; return; }
          step(r.offset);
        })
        .catch(function (e) { say.textContent = 'Stopped: ' + e.message; running = false; button.disabled = false; });
    }

    function run() {
      if (running) return;
      running = true;
      button.disabled = true;
      job.hidden = false;
      bar.style.width = '0%';
      step(0);
    }

    button.addEventListener('click', run);
    if (box.getAttribute('data-spc-autostart')) run();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
