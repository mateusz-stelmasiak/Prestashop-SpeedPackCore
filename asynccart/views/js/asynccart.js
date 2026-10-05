/**
 * AsyncCart
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * The cart page without waiting.
 *
 * +, - and a typed quantity change the line and the header count at once; a removed line slides
 * away at once, with Undo. The shop hears about it a moment after the last click, once, with the
 * final quantity, and its answer brings the line total and the summary totals. Refusals (stock,
 * minimum quantity) put the number back with the shop's own message. The theme's own handlers
 * never see these clicks: they are taken in the capture phase, before jQuery's.
 */
(function () {
  'use strict';
  var C = window.asyncCart;
  if (!C || !window.fetch || !window.FormData || !document.addEventListener) return;
  // SpeedPack Core's InstantCart already does this on the page: one handler per click
  if (window.instantcart && (window.instantcart.qtyUrl || window.instantcart.removeUrl)) return;

  var T = C.t || {};
  var DELAY = Math.max(100, +C.delay || 400);
  var QTY_SEL = '.js-cart-line-product-quantity, input[name="product-quantity-spin"]';
  var QTY_BTN = '.bootstrap-touchspin-up, .bootstrap-touchspin-down, .js-increase-product-quantity, .js-decrease-product-quantity';
  var DEL_SEL = '[data-link-action="delete-from-cart"], a.remove-from-cart';
  var chain = Promise.resolve();   // requests go one after another, in click order
  var busy = 0;                    // changes the shop has not confirmed yet

  function reduced() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
  function token() { return (window.prestashop && window.prestashop.static_token) || ''; }
  function emit(name, data) { var ps = window.prestashop; if (ps && typeof ps.emit === 'function') ps.emit(name, data); }
  function vibrate(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) { /* none */ } }
  // the shop's own pages only: anything else is not followed
  function leave(url) {
    try {
      var u = new URL(url, location.href);
      if (u.origin === location.origin && /^https?:$/.test(u.protocol)) location.assign(u.href);
    } catch (e) { /* not a URL: stay */ }
  }
  function post(url, body) {
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' }, keepalive: true })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); });
  }

  /* ----- header count and summary ----- */

  function countEls() { return document.querySelectorAll('.cart-products-count'); }
  function readCount() { var el = countEls()[0], m = el && el.textContent.match(/\d+/); return m ? +m[0] : 0; }
  function setCount(n) {
    n = Math.max(0, n | 0);
    Array.prototype.forEach.call(countEls(), function (el) {
      el.textContent = /\d/.test(el.textContent) ? el.textContent.replace(/\d+/, n) : '(' + n + ')';
    });
  }
  function setText(sel, text) {
    var els = document.querySelectorAll(sel);
    Array.prototype.forEach.call(els, function (el) { el.textContent = text; });
    return els.length > 0;
  }
  function pendingTotals(on) {
    Array.prototype.forEach.call(document.querySelectorAll('.cart-summary-line .value, .cart-total .value, .js-subtotal'), function (el) {
      el.classList.toggle('ac-pending', on);
    });
  }
  // the summary's numbers from the shop's answer; false when the theme's markup is not the usual one
  function patchTotals(res) {
    var t = res.totals;
    if (!t) return false;
    if (res.label) setText('.js-subtotal', res.label);
    var found = setText('#cart-subtotal-products .value', t.products);
    setText('#cart-subtotal-shipping .value', t.shipping);
    if (t.discount) setText('#cart-subtotal-discount .value', t.discount);
    return setText('.cart-summary-totals .cart-total .value, .cart-detailed-totals .cart-total .value', t.total) && found;
  }
  // vouchers came or went, the cart emptied, or the theme is unusual: let the theme re-render
  function settle(res, line) {
    var patched = patchTotals(res);
    pendingTotals(false);
    if (!res.count || res.rules || !patched || C.notify) {
      emit('updateCart', { reason: { idProduct: line.p, idProductAttribute: line.a, idCustomization: line.c, linkAction: 'refresh' }, resp: {} });
      if (!window.prestashop || typeof window.prestashop.emit !== 'function') location.reload();
    } else {
      emit('updatedCart', { eventType: 'updateCart', resp: {} });
    }
  }

  /* ----- the message card ----- */

  var box = null, timer = 0;
  function toast(head, text, action) {
    if (!box) {
      box = document.createElement('div');
      box.className = 'ac-toast';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    box.textContent = '';
    box.classList.toggle('is-error', !action);
    var words = document.createElement('div');
    words.className = 'ac-text';
    var b = document.createElement('strong');
    b.textContent = head;
    words.appendChild(b);
    if (text) { var s = document.createElement('span'); s.textContent = text; words.appendChild(s); }
    box.appendChild(words);
    if (action) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ac-undo';
      btn.textContent = action.label;
      btn.addEventListener('click', function () { box.classList.remove('is-on'); action.fn(); });
      box.appendChild(btn);
    }
    void box.offsetWidth;
    box.classList.add('is-on');
    clearTimeout(timer);
    timer = setTimeout(function () { box.classList.remove('is-on'); }, action ? 5000 : 4000);
  }

  /* ----- one cart line ----- */

  function cartLine(el) { return el && el.closest && el.closest('.cart-item, .cart-items > li'); }
  function lineName(row) { var n = row && row.querySelector('.product-line-info a, .label, .product-title'); return n ? n.textContent.trim() : ''; }
  // which product the line is: read from the theme's own links
  function lineId(row) {
    if (row._acId) return row._acId;
    var input = row.querySelector(QTY_SEL), del = row.querySelector(DEL_SEL);
    var id = { p: input ? +input.getAttribute('data-product-id') || 0 : 0, a: 0, c: 0 };
    var url = (input && (input.getAttribute('data-up-url') || input.getAttribute('data-update-url'))) || (del && del.href) || '';
    try {
      var u = new URL(url, location.href);
      id.p = +u.searchParams.get('id_product') || id.p;
      id.a = +u.searchParams.get('id_product_attribute') || 0;
      id.c = +u.searchParams.get('id_customization') || 0;
    } catch (e) { /* no URL support: the theme's flow stays */ }
    row._acId = id;
    return id;
  }
  // the most specific match wins (a list selector would return the outer span first)
  function lineTotal(row) {
    var sels = ['.product-line-grid-right .product-price strong', '.product-total .value', '.product-line-grid-right .product-price'];
    for (var i = 0; i < sels.length; i++) { var el = row.querySelector(sels[i]); if (el) return el; }
    return null;
  }
  function animate(row, frames, gone) {
    row._acGone = gone;
    if (row._acAnim) row._acAnim.cancel();
    if (reduced() || !row.animate) { row.hidden = gone; return; }
    row.hidden = false;
    row.style.overflow = 'hidden';
    var a = row._acAnim = row.animate(frames, { duration: 320, easing: 'cubic-bezier(.4,0,.2,1)' });
    a.onfinish = function () { if (row._acAnim !== a) return; row._acAnim = null; row.style.overflow = ''; row.hidden = row._acGone; };
  }
  function collapse(row) {
    var h = row.offsetHeight;
    animate(row, [{ height: h + 'px', opacity: 1 }, { height: h + 'px', opacity: 0, offset: 0.4 }, { height: '0px', opacity: 0, paddingTop: '0px', paddingBottom: '0px', marginTop: '0px', marginBottom: '0px' }], true);
  }
  function expand(row) {
    row.hidden = false;
    var h = row.offsetHeight;
    animate(row, [{ height: '0px', opacity: 0 }, { height: h + 'px', opacity: 0, offset: 0.5 }, { height: h + 'px', opacity: 1 }], false);
  }

  /* ----- quantity ----- */

  function qtyState(input) {
    if (!input._acQty) input._acQty = { shown: parseInt(input.getAttribute('value'), 10) || parseInt(input.value, 10) || 1, timer: 0, waiting: false };
    return input._acQty;
  }
  function showQty(input, n) {
    var st = qtyState(input), row = cartLine(input);
    var min = Math.max(1, parseInt(input.getAttribute('min'), 10) || 1);
    n = Math.max(min, n | 0);
    input.value = n;
    if (n === st.shown) return;
    setCount(readCount() + n - st.shown);
    st.shown = n;
    var total = lineTotal(row);
    if (total) total.classList.add('ac-pending');
    pendingTotals(true);
    if (!st.waiting) { st.waiting = true; busy++; }
    clearTimeout(st.timer);
    st.timer = setTimeout(function () { sendQty(input); }, DELAY);
  }
  function sendQty(input) {
    var st = qtyState(input), row = cartLine(input), id = lineId(row), wanted = st.shown;
    var body = new FormData();
    body.set('token', token()); body.set('p', id.p); body.set('a', id.a); body.set('c', id.c); body.set('qty', wanted);
    chain = chain.then(function () { return post(C.qtyUrl, body); }).then(function (res) {
      if (res && res.fallback) { location.reload(); return; }
      if (!res || res.quantity === undefined) throw new Error((res && res.error) || '');
      var newer = st.shown !== wanted;   // the shopper kept clicking while this was on its way
      if (!newer) {
        if (res.quantity !== st.shown) { setCount(readCount() + res.quantity - st.shown); st.shown = res.quantity; input.value = res.quantity; }
        st.waiting = false;
        busy--;
        var total = lineTotal(row);
        if (total) { if (res.line) total.textContent = res.line; total.classList.remove('ac-pending'); }
        if (!busy) { setCount(res.count); settle(res, id); }
      }
      if (!res.ok) { toast(T.qtyError || 'Quantity not changed', res.error || T.error); vibrate([40, 60, 40]); }
    }).catch(function (err) {
      toast(T.qtyError || 'Quantity not changed', (err && err.message && !/^http/.test(err.message)) ? err.message : T.error);
      // the page no longer knows what the cart holds: show the shop's own version of it
      if (st.shown === wanted) setTimeout(function () { location.reload(); }, 1800);
    });
  }

  /* ----- removing ----- */

  var rm = { queue: [], timer: 0 };
  function removeLine(row) {
    var id = lineId(row);
    if (!id.p) return false;
    var input = row.querySelector(QTY_SEL);
    // a quantity change still waiting for this line is dropped: the line is going
    if (input && input._acQty && input._acQty.waiting) { clearTimeout(input._acQty.timer); input._acQty.waiting = false; busy--; }
    var e = { row: row, id: id, qty: input ? qtyState(input).shown : 1, name: lineName(row) };
    collapse(row);
    setCount(readCount() - e.qty);
    pendingTotals(true);
    vibrate(9);
    rm.queue.push(e);
    clearTimeout(rm.timer);
    rm.timer = setTimeout(flushRemove, DELAY);
    toast(T.removed || 'Removed', e.name, id.c ? null : { label: T.undo || 'Undo', fn: function () { undo(e); } });
    return true;
  }
  function undo(e) {
    var i = rm.queue.indexOf(e);
    if (i >= 0) {   // not sent yet: simply not sent
      rm.queue.splice(i, 1);
      if (!rm.queue.length) { clearTimeout(rm.timer); pendingTotals(false); }
      expand(e.row);
      setCount(readCount() + e.qty);
      return;
    }
    if (!e.done) return;
    expand(e.row);
    setCount(readCount() + e.qty);
    pendingTotals(true);
    var body = new FormData();
    body.set('token', token()); body.set('p', e.id.p); body.set('a', e.id.a); body.set('c', 0); body.set('qty', e.qty); body.set('restore', 1);
    chain = chain.then(function () { return post(C.qtyUrl || C.removeUrl.replace(/remove(?=[^/]*$)/, 'qty'), body); }).then(function (res) {
      if (!res || res.fallback || res.quantity === undefined) throw new Error((res && res.error) || '');
      e.done = false;
      setCount(res.count);
      var input = e.row.querySelector(QTY_SEL);
      if (input) { qtyState(input).shown = res.quantity; input.value = res.quantity; }
      var total = lineTotal(e.row);
      if (total && res.line) total.textContent = res.line;
      settle(res, e.id);
    }).catch(function () { location.reload(); });
  }
  function flushRemove() {
    var batch = rm.queue.splice(0);
    if (!batch.length) return;
    var body = new FormData();
    body.set('token', token());
    body.set('lines', JSON.stringify(batch.map(function (e) { return e.id; })));
    busy++;
    function restore(e) { expand(e.row); setCount(readCount() + e.qty); }
    chain = chain.then(function () { return post(C.removeUrl, body); }).then(function (res) {
      busy--;
      if (res && res.fallback) { leave(batch[0].row.querySelector(DEL_SEL).href); return; }
      if (!res || !res.ok) throw new Error((res && res.error) || '');
      (res.failed || []).forEach(function (i) { if (batch[i]) { restore(batch[i]); batch[i].failed = true; } });
      batch.forEach(function (e) { if (!e.failed) e.done = true; });
      if (!busy) setCount(res.count);
      settle(res, batch[0].id);
    }).catch(function (err) {
      busy--;
      batch.forEach(restore);
      pendingTotals(false);
      toast(T.removeError || 'Not removed', (err && err.message && !/^http/.test(err.message)) ? err.message : T.error);
    });
  }
  // leaving the page right after a removal: it is still sent
  window.addEventListener('pagehide', function () { if (rm.queue.length) { clearTimeout(rm.timer); flushRemove(); } });

  /* ----- taking the clicks (capture phase, before the theme's own handlers) ----- */

  if (C.qtyUrl) {
    // the theme's spinner starts on mousedown / touchstart: it must never see these
    ['mousedown', 'touchstart'].forEach(function (type) {
      document.addEventListener(type, function (e) {
        var btn = e.target.closest && e.target.closest(QTY_BTN);
        var row = cartLine(btn);
        if (row && row.querySelector(QTY_SEL)) e.stopImmediatePropagation();
      }, true);
    });
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest(QTY_BTN);
      var row = cartLine(btn), input = row && row.querySelector(QTY_SEL);
      if (!input || !lineId(row).p) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      var up = btn.matches('.bootstrap-touchspin-up, .js-increase-product-quantity');
      showQty(input, qtyState(input).shown + (up ? 1 : -1));
      vibrate(9);
    }, true);
    // a typed number counts when it is confirmed (Enter, leaving the field)
    ['keyup', 'keydown', 'focusout', 'change'].forEach(function (type) {
      document.addEventListener(type, function (e) {
        var input = e.target;
        if (!input.matches || !input.matches(QTY_SEL) || !cartLine(input)) return;
        e.stopImmediatePropagation();
        if (type === 'keydown') { if (e.key === 'Enter') e.preventDefault(); return; }
        if (type === 'keyup' && e.key !== 'Enter') return;
        var n = parseInt(input.value, 10), st = qtyState(input);
        if (n === 0 && C.removeUrl) { input.value = st.shown; removeLine(cartLine(input)); return; }
        if (!(n > 0)) { input.value = st.shown; return; }
        showQty(input, n);
      }, true);
    });
  }
  if (C.removeUrl) {
    document.addEventListener('click', function (e) {
      var link = e.target.closest && e.target.closest(DEL_SEL);
      if (!link || e.button || e.metaKey || e.ctrlKey) return;
      var row = cartLine(link);
      if (!row || !removeLine(row)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
    }, true);
  }
})();
