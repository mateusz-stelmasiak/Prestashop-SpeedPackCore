/**
 * SpeedPack Core - the AI agents tab: the score from an outside check, and the one-click run
 * that sets up each part in turn, shows what it does, and proves it from the outside.
 */
(function () {
  'use strict';

  function init(root) {
    var d = root.dataset;
    var steps = JSON.parse(d.steps || '[]');
    var stepChecks = JSON.parse(d.stepChecks || '{}');
    var names = JSON.parse(d.names || '{}');
    var optional = JSON.parse(d.optional || '[]');
    var $ = function (sel) { return root.querySelector('[data-spc-ag="' + sel + '"]'); };
    var running = false;

    function post(op, extra) {
      var body = new URLSearchParams();
      body.append('op', op);
      Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
      return fetch(d.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function gauge(ok, n, label) {
      var pct = n ? Math.round(ok / n * 100) : 0;
      root.querySelector('.spc-ag-ring-fg').setAttribute('stroke-dasharray', pct + ' 100');
      root.classList.toggle('is-ready', n > 0 && ok === n);
      $('score').textContent = ok;
      $('of').textContent = '/' + n;
      $('label').textContent = label || (ok === n && n ? d.tReady : pct + '%');
    }

    function paint(results) {
      Object.keys(results).forEach(function (id) {
        var li = root.querySelector('[data-check="' + id + '"]');
        if (!li) { return; }
        var st = results[id][0];
        li.querySelector('.spc-ag-state').className = 'spc-ag-state is-' + st;
        li.querySelector('.spc-ag-detail').textContent = results[id][1];
        li.title = st === 'pass' ? d.tPass : (st === 'local' ? d.tLocal : (st === 'optional' ? d.tOptional : d.tFail));
      });
    }

    function scoreOf(results) {
      var ok = 0, n = 0;
      Object.keys(results).forEach(function (id) {
        if (optional.indexOf(id) >= 0) { return; }
        n++; if (results[id][0] === 'pass' || results[id][0] === 'local') { ok++; }
      });
      return [ok, n];
    }

    function check() {
      $('label').textContent = d.tChecking;
      root.classList.add('is-checking');
      return post('check').then(function (r) {
        root.classList.remove('is-checking');
        paint(r.results);
        gauge(r.score[0], r.score[1]);
        blocked(r.blocked);
        return r;
      });
    }

    function blocked(msg) {
      var box = $('blocked');
      if (!box) { return; }
      box.textContent = msg || '';
      box.hidden = !msg;
    }

    function sleep(ms) { return new Promise(function (res) { setTimeout(res, ms); }); }

    function runStep(i, results) {
      if (i >= steps.length) { return Promise.resolve(results); }
      var name = steps[i];
      var el = root.querySelector('.spc-ag-step[data-step="' + name + '"]');
      el.classList.add('is-working');
      el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      var started = Date.now();
      return post('step', { step: name }).then(function (r) {
        // long enough to be read
        return sleep(Math.max(0, 1400 - (Date.now() - started))).then(function () { return r; });
      }).then(function (r) {
        var ok = true, lines = [];
        (stepChecks[name] || []).forEach(function (c) {
          var res = r.results[c];
          if (!res) { return; }
          results[c] = res;
          if (res[0] !== 'pass' && res[0] !== 'local') { ok = false; }
          lines.push((res[0] === 'pass' ? '✓ ' : (res[0] === 'local' ? '✓ ' : (res[0] === 'optional' ? '○ ' : '✗ '))) + names[c] + ': ' + res[1]);
        });
        if (r.note) { lines.push('ℹ ' + r.note); }
        blocked(r.blocked);
        el.querySelector('[data-spc-ag="proof"]').textContent = lines.join('\n');
        el.classList.remove('is-working');
        el.classList.add(ok ? 'is-done' : (name === 'dnsaid' ? 'is-optional' : 'is-failed'));
        paint(r.results);
        var s = scoreOf(results);
        gauge(s[0], s[1]);
        return runStep(i + 1, results);
      }, function () {
        el.classList.remove('is-working');
        el.classList.add('is-failed');
        return runStep(i + 1, results);
      });
    }

    function confetti() {
      var box = root.querySelector('.spc-ag-confetti');
      box.innerHTML = '';
      var colors = ['#7c3aed', '#25b9d7', '#22c55e', '#f59e0b', '#ec4899'];
      for (var i = 0; i < 70; i++) {
        var p = document.createElement('i');
        p.style.left = Math.random() * 100 + '%';
        p.style.background = colors[i % colors.length];
        p.style.animationDelay = (Math.random() * 0.6) + 's';
        p.style.animationDuration = (1.6 + Math.random() * 1.4) + 's';
        p.style.transform = 'rotate(' + Math.random() * 360 + 'deg)';
        box.appendChild(p);
      }
    }

    function run() {
      if (running) { return; }
      running = true;
      root.classList.add('is-running');
      $('steps').hidden = false;
      $('finale').hidden = true;
      root.querySelectorAll('.spc-ag-step').forEach(function (s) { s.classList.remove('is-done', 'is-failed', 'is-working', 'is-optional'); });
      post('start').then(function (r) {
        $('before').textContent = '';
        var results = {};
        Object.keys(names).forEach(function (c) { results[c] = [optional.indexOf(c) >= 0 ? 'optional' : 'fail', '']; });
        gauge(0, r.before ? r.before[1] : steps.length);
        return runStep(0, results);
      }).then(function () { return post('finish'); }).then(function (r) {
        paint(r.results);
        blocked(r.blocked);
        gauge(r.score[0], r.score[1]);
        $('finale-title').textContent = r.score[0] === r.score[1] ? d.tDone : d.tPartly;
        $('finale-before').textContent = r.before[0] + '/' + r.before[1];
        $('finale-after').textContent = r.score[0] + '/' + r.score[1];
        $('finale').hidden = false;
        if (r.score[0] === r.score[1]) { confetti(); }
        $('finale').scrollIntoView({ block: 'center', behavior: 'smooth' });
        root.classList.remove('is-running');
        running = false;
      }).catch(function () { root.classList.remove('is-running'); running = false; });
    }

    root.addEventListener('click', function (e) {
      var t = e.target.closest('[data-spc-ag]');
      if (!t) { return; }
      var what = t.getAttribute('data-spc-ag');
      if (what === 'run') { e.preventDefault(); run(); }
      if (what === 'check') { e.preventDefault(); check(); }
      if (what === 'off') {
        e.preventDefault();
        if (window.confirm(d.tConfirmOff)) { post('off').then(function () { window.location.reload(); }); }
      }
    });
    // the steps open to show what they do
    root.addEventListener('click', function (e) {
      var title = e.target.closest('.spc-ag-step-title');
      if (title) { title.parentNode.parentNode.classList.toggle('is-open'); }
    });

    var last = d.last ? JSON.parse(d.last) : null;
    if (last && last.results) {
      paint(last.results);
      gauge(last.score[0], last.score[1]);
    }
    // the tab is one of many on the settings page: checked the first time it is opened
    var checked = false;
    function maybeCheck() {
      if (!checked && root.offsetParent !== null) { checked = true; check(); }
    }
    document.addEventListener('click', function () { setTimeout(maybeCheck, 50); });
    setTimeout(maybeCheck, 300);
  }

  function boot() {
    var root = document.getElementById('spc-agents');
    if (root) { init(root); }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
