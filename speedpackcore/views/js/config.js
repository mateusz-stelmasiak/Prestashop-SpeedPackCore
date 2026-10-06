/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * The settings page in tabs. Every section starts with a marker (pane.tpl); the elements after a
 * marker, up to the next one, become that tab's pane. The tab shown is the one whose form was
 * just sent, else the one in the address (#spc-cache), else the last one opened, else the
 * overview. Without this script, the page shows every section one after another.
 */
(function () {
  'use strict';
  var KEY = 'speedpackcore:tab';

  function start() {
    var head = document.getElementById('spc-head');
    var markers = Array.prototype.slice.call(document.querySelectorAll('[data-spc-pane-start]'));
    if (!head || markers.length < 2) return;
    var panes = {};
    markers.forEach(function (marker) {
      var id = marker.getAttribute('data-spc-pane-start');
      if (!id) return;
      var pane = document.createElement('div');
      pane.className = 'spc-pane';
      pane.setAttribute('role', 'tabpanel');
      pane.setAttribute('data-spc-pane', id);
      var node = marker.nextSibling;
      while (node && !(node.nodeType === 1 && node.hasAttribute('data-spc-pane-start'))) {
        var next = node.nextSibling;
        pane.appendChild(node);
        node = next;
      }
      marker.parentNode.insertBefore(pane, node || null);
      panes[id] = pane;
    });
    var tabs = Array.prototype.slice.call(head.querySelectorAll('[data-spc-tab]'));

    function show(id, remember) {
      if (!panes[id]) id = 'overview';
      Object.keys(panes).forEach(function (k) { panes[k].hidden = k !== id; });
      tabs.forEach(function (a) {
        var on = a.getAttribute('data-spc-tab') === id;
        a.parentNode.className = on ? 'is-active' : '';
        a.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (remember) {
        try { localStorage.setItem(KEY, id); } catch (e) { /* private mode */ }
        if (window.history && history.replaceState) history.replaceState(null, '', '#spc-' + id);
      }
    }

    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('[data-spc-tab], [data-spc-goto]');
      if (!a) return;
      e.preventDefault();
      show(a.getAttribute('data-spc-tab') || a.getAttribute('data-spc-goto'), true);
      head.scrollIntoView({ block: 'start' });
    });

    var stored = '';
    try { stored = localStorage.getItem(KEY) || ''; } catch (e) { stored = ''; }
    var fromHash = (location.hash.match(/^#spc-([a-z]+)$/) || [])[1];
    show(head.getAttribute('data-spc-active') || fromHash || stored || 'overview', false);
    head.classList.add('spc-tabbed');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
