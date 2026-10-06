/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * Health check: database care (sizes counted on load, each cleanup in batches until done),
 * ANALYZE TABLE in batches, module weight, and copying the lines for the host. Everything is
 * built as elements (no markup strings), and every request goes to the settings page itself.
 */
(function () {
  'use strict';

  function start() {
    var box = document.getElementById('spc-diagnostics');
    if (!box || !window.fetch || !window.FormData) return;
    var url = box.getAttribute('data-spc-url');
    var t = {};
    try { t = JSON.parse(box.getAttribute('data-spc-texts') || '{}'); } catch (e) { t = {}; }

    function fmt(text) {
      var args = Array.prototype.slice.call(arguments, 1), i = 0;
      return String(text || '').replace(/%(\d+\$)?[sd]/g, function (m, pos) { return String(pos ? args[parseInt(pos, 10) - 1] : args[i++]); });
    }
    function num(n) { return Number(n || 0).toLocaleString(); }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
    function post(step, fields) {
      var body = new FormData();
      body.append('spc_ajax', step);
      Object.keys(fields || {}).forEach(function (k) {
        var v = fields[k];
        if (v && typeof v === 'object') Object.keys(v).forEach(function (kk) { body.append(k + '[' + kk + ']', v[kk]); });
        else body.append(k, v);
      });
      return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (r) { if (!r || r.error) throw new Error(r && r.error ? r.error : 'unexpected answer'); return r; });
    }

    /* ---- database care ---- */
    var rows = Array.prototype.slice.call(box.querySelectorAll('[data-spc-item]'));
    function daysOf(row) { return Math.max(7, parseInt(row.querySelector('[data-spc-item-days]').value, 10) || 7); }
    function scan() {
      var days = {};
      rows.forEach(function (row) { days[row.getAttribute('data-spc-item')] = daysOf(row); row.querySelector('[data-spc-item-old]').textContent = t.scanning; });
      return post('care_scan', { days: days }).then(function (r) {
        rows.forEach(function (row) {
          var id = row.getAttribute('data-spc-item'), it = r.items[id], btn = row.querySelector('[data-spc-item-clean]');
          if (!it) { row.hidden = true; return; }
          row.querySelector('[data-spc-item-size]').textContent = it.size + ' · ' + fmt(t.rows, num(it.rows));
          row.querySelector('[data-spc-item-old]').textContent = it.old ? fmt(t.toRemove, num(it.old)) : t.nothing;
          row.setAttribute('data-old', it.old);
          btn.disabled = !it.old;
        });
      }).catch(function (e) { rows.forEach(function (row) { row.querySelector('[data-spc-item-old]').textContent = fmt(t.failed, e.message); }); });
    }
    rows.forEach(function (row) {
      var id = row.getAttribute('data-spc-item'), names = (t.items || {})[id] || [id, ''];
      row.querySelector('[data-spc-item-name]').textContent = names[0];
      row.querySelector('[data-spc-item-what]').textContent = names[1];
      var btn = row.querySelector('[data-spc-item-clean]'), say = row.querySelector('[data-spc-item-say]');
      btn.textContent = t.clean;
      row.querySelector('[data-spc-item-days]').addEventListener('change', scan);
      btn.addEventListener('click', function () {
        var days = daysOf(row), old = row.getAttribute('data-old') || 0;
        if (!window.confirm(fmt(t.confirm, num(old), names[0], days))) return;
        btn.disabled = true;
        btn.textContent = t.cleaning;
        var total = 0;
        (function step() {
          post('care', { item: id, days: days }).then(function (r) {
            total += r.deleted;
            say.textContent = fmt(t.cleaned, num(total));
            if (!r.done) return step();
            btn.textContent = t.clean;
            scan();
          }).catch(function (e) { say.textContent = fmt(t.failed, e.message); btn.textContent = t.clean; btn.disabled = false; });
        })();
      });
    });
    if (rows.length) scan();

    /* ---- ANALYZE TABLE ---- */
    var aBtn = box.querySelector('[data-spc-analyze]');
    if (aBtn) {
      aBtn.addEventListener('click', function () {
        var job = box.querySelector('[data-spc-analyze-job]'), bar = box.querySelector('[data-spc-analyze-bar]'), say = box.querySelector('[data-spc-analyze-say]');
        aBtn.disabled = true; job.hidden = false; bar.style.width = '0%';
        (function step(offset) {
          post('analyze', { offset: offset }).then(function (r) {
            bar.style.width = (r.total ? Math.round(100 * r.offset / r.total) : 100) + '%';
            say.textContent = r.done ? fmt(t.analyzed, r.total) : fmt(t.analyzing, r.offset, r.total);
            if (!r.done) return step(r.offset);
            aBtn.disabled = false;
          }).catch(function (e) { say.textContent = fmt(t.failed, e.message); aBtn.disabled = false; });
        })(0);
      });
    }

    /* ---- module weight ---- */
    var wBtn = box.querySelector('[data-spc-weight]');
    if (wBtn) {
      wBtn.addEventListener('click', function () {
        var say = box.querySelector('[data-spc-weight-say]'), out = box.querySelector('[data-spc-weight-out]');
        wBtn.disabled = true;
        say.textContent = t.measuring;
        post('weight').then(function (r) {
          while (out.firstChild) out.removeChild(out.firstChild);
          var p = r.pages || {}, home = p.home || { files: 0, size: '–' }, prod = p.product || { files: 0, size: '–' };
          say.textContent = fmt(t.pages, home.files, home.size, prod.files, prod.size);
          if (r.combined) out.appendChild(el('p', 'alert alert-info', t.combined));
          var table = el('table', 'table spc-weight'), head = el('tr');
          [[t.module, ''], [t.hooks, 'num'], [t.files, 'num'], [t.size, 'num']].forEach(function (h) { head.appendChild(el('th', h[1], h[0])); });
          var thead = el('thead'); thead.appendChild(head); table.appendChild(thead);
          var body = el('tbody');
          (r.modules || []).forEach(function (m) {
            if (!m.hooks && !m.bytes) return;
            var tr = el('tr', m.heavy ? 'is-heavy' : ''), name = el('td');
            name.appendChild(el('strong', '', (t.names || {})[m.name] || m.name));
            if (m.heavy) { name.appendChild(document.createTextNode(' ')); name.appendChild(el('span', 'label label-warning', t.heavy)); }
            if (m.hook_names && m.hook_names.length) { name.appendChild(el('br')); name.appendChild(el('small', '', m.hook_names.join(', ') + (m.hooks > m.hook_names.length ? ' …' : ''))); }
            tr.appendChild(name);
            tr.appendChild(el('td', 'num', String(m.hooks || '–')));
            tr.appendChild(el('td', 'num', m.css || m.js ? m.css + ' / ' + m.js : '–'));
            tr.appendChild(el('td', 'num', m.bytes ? m.size : '–'));
            body.appendChild(tr);
          });
          table.appendChild(body);
          out.appendChild(table);
          wBtn.disabled = false;
        }).catch(function (e) { say.textContent = fmt(t.failed, e.message); wBtn.disabled = false; });
      });
    }

    /* ---- the lines for the host ---- */
    var copy = box.querySelector('[data-spc-copy]');
    if (copy) {
      copy.addEventListener('click', function () {
        var area = box.querySelector('[data-spc-host]'), say = box.querySelector('[data-spc-copy-say]');
        var done = function () { say.textContent = t.copied; };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(area.value).then(done, function () { area.select(); });
        else { area.select(); try { document.execCommand('copy'); done(); } catch (e) { /* selected for a manual copy */ } }
      });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
