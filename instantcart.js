/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * Instant add to cart.
 *
 * A click on "Add to cart" is answered at once: the header count goes up and a small confirmation
 * slides in. The product goes to the lean endpoint in the background (one request, no cart
 * presenter, no pop-up page build); the count is then set to what the server says. If the server
 * says no, the count goes back and the reason is shown. Anything unusual (composer products,
 * required customisation, a failed request) is handed to the shop's own add-to-cart.
 */
(function () {
  'use strict';
  var C = window.instantcart;
  if (!C || !window.fetch || !window.FormData || !document.addEventListener) return;
  var T = C.t || {};
  var chain = Promise.resolve();   // adds go one after another, in click order
  var pending = 0;                 // clicks the shop has not confirmed yet
  var BATCH_MS = 300, BATCH_MAX = 1200;   // rapid clicks on one product become one request

  /* ----- header count ----- */

  function countEls() { return document.querySelectorAll('.cart-products-count'); }
  function readCount() {
    var el = countEls()[0], m = el && el.textContent.match(/\d+/);
    return m ? +m[0] : 0;
  }
  function setCount(n) {
    n = Math.max(0, n | 0);
    Array.prototype.forEach.call(countEls(), function (el) {
      var m = el.textContent.match(/\d+/), was = m ? +m[0] : 0;
      el.textContent = /\d/.test(el.textContent) ? el.textContent.replace(/\d+/, n) : '(' + n + ')';
      // the number rolls in from above when it grows, like an odometer
      if (n > was && !reduced()) { el.classList.remove('ic-roll'); void el.offsetWidth; el.classList.add('ic-roll'); }
    });
    // an empty cart's header has no link yet
    Array.prototype.forEach.call(document.querySelectorAll('.blockcart'), function (b) {
      b.classList.toggle('active', n > 0);
      b.classList.toggle('inactive', n <= 0);
      var head = b.querySelector('.header');
      if (n > 0 && head && !head.querySelector('a')) {
        var a = document.createElement('a');
        a.href = C.cartUrl;
        a.rel = 'nofollow';
        while (head.firstChild) a.appendChild(head.firstChild);
        head.appendChild(a);
      }
    });
  }

  /* ----- confirmation ----- */

  var box = null, timer = 0;
  function hide() { if (box) box.classList.remove('is-on'); }
  function later(ms) { clearTimeout(timer); timer = setTimeout(hide, ms); }
  function toast(item, qty, error, action) {
    var key = (error ? '!' : '') + (action ? 'rm:' : '') + item.name;
    if (box && !error && !action && box._key === key && box.classList.contains('is-on')) {
      var ln = box.querySelector('.ic-text span');
      if (ln) ln.textContent = item.name + (qty > 1 ? ' \u00d7 ' + qty : '');
      later(3500);
      return;
    }
    if (!box) {
      box = document.createElement('div');
      box.className = 'ic-toast';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      box.addEventListener('mouseenter', function () { clearTimeout(timer); });
      box.addEventListener('mouseleave', function () { later(2500); });
      swipeAway(box);
      document.body.appendChild(box);
    }
    box.textContent = '';
    box._key = key;
    box.classList.toggle('is-error', !!error);
    if (item.img && !error) {
      var img = document.createElement('img');
      img.src = item.img; img.alt = ''; img.width = 48; img.height = 48; img.draggable = false;
      box.appendChild(img);
    } else {
      var mark = document.createElement('span');
      mark.className = 'ic-mark';
      mark.setAttribute('aria-hidden', 'true');
      mark.textContent = error ? '!' : '✓';
      box.appendChild(mark);
    }
    var text = document.createElement('div');
    text.className = 'ic-text';
    var head = document.createElement('strong');
    head.textContent = error ? (T.error || 'Error') : action ? (T.removed || 'Removed') : (T.added || 'Added');
    var line = document.createElement('span');
    line.textContent = error ? error : item.name + (qty > 1 ? ' × ' + qty : '');
    text.appendChild(head);
    if (line.textContent) text.appendChild(line);
    box.appendChild(text);
    if (action) {
      var undo = document.createElement('button');
      undo.type = 'button';
      undo.className = 'ic-go';
      undo.textContent = action.label;
      undo.addEventListener('click', function () { hide(); action.fn(); });
      box.appendChild(undo);
    } else if (!error) {
      var go = document.createElement('a');
      go.className = 'ic-go';
      go.href = C.cartUrl;
      go.rel = 'nofollow';
      go.textContent = T.go || 'Cart';
      box.appendChild(go);
    }
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'ic-x';
    x.setAttribute('aria-label', T.close || 'Close');
    x.textContent = '×';
    x.addEventListener('click', hide);
    box.appendChild(x);
    box.classList.remove('is-on');
    void box.offsetWidth;   // replay the slide-in
    box.classList.add('is-on');
    later(error ? 6000 : action ? 5000 : 3500);
  }

  // drag the toast sideways or down to dismiss it; let go early and it springs back
  function swipeAway(el) {
    var x0 = 0, y0 = 0, dx = 0, dy = 0, on = false;
    el.addEventListener('pointerdown', function (e) {
      if (e.target.closest('a, button')) return;
      if (e.pointerType === 'mouse') e.preventDefault();   // no text selection or image drag while swiping
      on = true; x0 = e.clientX; y0 = e.clientY; dx = dy = 0;
      clearTimeout(timer);
      el.style.transition = 'none';
      if (el.setPointerCapture) el.setPointerCapture(e.pointerId);
    });
    el.addEventListener('pointermove', function (e) {
      if (!on) return;
      dx = e.clientX - x0; dy = Math.max(0, e.clientY - y0);
      el.style.transform = 'translate(' + dx + 'px,' + dy + 'px) rotate(' + dx / 40 + 'deg)';
      el.style.opacity = String(Math.max(0.2, 1 - Math.max(Math.abs(dx) / 220, dy / 120)));
    });
    function end() {
      if (!on) return;
      on = false;
      el.style.transition = '';
      if (Math.abs(dx) > 70 || dy > 40) {
        el.style.transform = 'translate(' + (dx * 3) + 'px,' + (dy * 3) + 'px)';
        el.style.opacity = '0';
        setTimeout(function () { hide(); el.style.transform = ''; el.style.opacity = ''; }, 200);
      } else {
        el.style.transform = ''; el.style.opacity = '';
        later(2500);
      }
    }
    el.addEventListener('pointerup', end);
    el.addEventListener('pointercancel', end);
  }

  // name and picture from the product page / miniature the button sits in
  function about(btn) {
    var scope = btn.closest('.product-miniature, .js-product-miniature, .quickview, #main, .product-container') || document;
    var name = scope.querySelector('h1, .h1, .product-title, [itemprop="name"]');
    var img = scope.querySelector('.product-cover img, .js-qv-product-cover, img.thumbnail-img, .product-thumbnail img');
    return { name: name ? name.textContent.trim() : '', img: img ? (img.currentSrc || img.src) : '' };
  }

  /* ----- the button itself: a line-drawn jar that "loads" while the shop saves, and a multiplier ----- */

  var JAR_D = 'M9.2 2.8h5.6v2.4H9.2z M8.6 5.2 7 8.2v11.3c0 1.1.9 2 2 2h6c1.1 0 2-.9 2-2V8.2l-1.6-3 M7 12.2h10';
  var JAR = '<svg class="ic-jar" viewBox="0 0 24 24" aria-hidden="true">'
    + '<path class="ic-jar-base" pathLength="100" d="' + JAR_D + '"/>'
    + '<path class="ic-jar-run" pathLength="100" d="' + JAR_D + '"/>'
    + '<path class="ic-jar-tick" pathLength="100" d="M9.4 16.4l1.9 1.9 3.6-3.9"/></svg>';   // drawn in once the shop confirms
  var lastBump = 0;
  function bump(el) {
    var now = Date.now();
    if (!el || now - lastBump < 180) return;
    lastBump = now;
    el.classList.remove('ic-bump');
    void el.offsetWidth;
    el.classList.add('ic-bump');
  }

  // a puff of pastel blobs from the button (about 0.6 s), on every add
  // jar-shop colours: beet, carrot, dill, cucumber, honey, plum, the shop's aqua
  var BLOBS = ['#e2445c', '#f28b30', '#8cc63f', '#2f9e6e', '#f6c343', '#8e5bd6', '#25b9d7'];
  var layers = [];
  function burst(btn, big) {
    if (!btn || !btn.getBoundingClientRect || reduced()) return;
    // clicking like crazy: not more than ~6 bursts a second, smaller ones, and only a few alive at once
    var now = Date.now(), since = now - (btn._icBurst || 0);
    if (since < 160 && !big) return;
    btn._icBurst = now;
    var pieces = big ? 36 : since < 600 ? 10 : 24;
    while (layers.length >= 3) { var old = layers.shift(); if (old.parentNode) old.remove(); }
    var r = btn.getBoundingClientRect(), cy = r.top + r.height / 2;
    var layer = document.createElement('span');
    layer.className = 'ic-burst';
    layer.setAttribute('aria-hidden', 'true');
    for (var i = 0; i < pieces; i++) {
      // every third piece is a paper strip, the rest are soft blobs; they start along the whole button
      var strip = i % 3 === 0, b = document.createElement('i');
      var w = strip ? 4 + Math.random() * 3 : 7 + Math.random() * 9, h = strip ? 11 + Math.random() * 6 : w * (0.75 + Math.random() * 0.5);
      var x = r.left + r.width * (0.15 + Math.random() * 0.7);
      var a = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.4, sp = (55 + Math.random() * 75) * (big ? 1.5 : 1);
      var dx = Math.cos(a) * sp + (x - r.left - r.width / 2) * 0.4, dy = Math.sin(a) * sp;
      var spin = (Math.random() < 0.5 ? -1 : 1) * (180 + Math.random() * 360);
      b.style.width = w + 'px';
      b.style.height = h + 'px';
      b.style.left = (x - w / 2) + 'px';
      b.style.top = (cy - h / 2) + 'px';
      b.style.background = BLOBS[i % BLOBS.length];
      b.style.borderRadius = strip ? '2px' : (40 + Math.random() * 20) + '% ' + (40 + Math.random() * 20) + '% ' + (40 + Math.random() * 20) + '% ' + (40 + Math.random() * 20) + '%';
      layer.appendChild(b);
      if (b.animate) {
        b.animate([
          { transform: 'translate(0,0) scale(.2) rotate(0deg)', opacity: 1 },
          { transform: 'translate(' + dx * 0.85 + 'px,' + dy * 0.85 + 'px) scale(1.15) rotate(' + spin * 0.4 + 'deg)', opacity: 1, offset: 0.4 },
          { transform: 'translate(' + dx + 'px,' + (dy + 30) + 'px) scale(.9) rotate(' + spin * 0.75 + 'deg)', opacity: 1, offset: 0.8 },
          { transform: 'translate(' + dx * 1.05 + 'px,' + (dy + 70) + 'px) scale(.6) rotate(' + spin + 'deg)', opacity: 0 }
        ], { duration: 760 + Math.random() * 260, easing: 'cubic-bezier(.2,.6,.45,1)', fill: 'forwards' });
      }
    }
    document.body.appendChild(layer);
    layers.push(layer);
    setTimeout(function () { layer.remove(); var k = layers.indexOf(layer); if (k >= 0) layers.splice(k, 1); }, 1100);
  }

  /* ----- feel: press, ripple, haptics, the jar flying into the cart, streak milestones ----- */

  function reduced() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
  var IOS = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var MILESTONES = { 5: 1, 10: 1, 20: 1, 50: 1, 100: 1 };

  // Android: the vibration API. iOS has none, but Safari 18+ ticks when a switch control is toggled
  // during a tap, so a hidden one is toggled (works only inside the click itself).
  var hap = null;
  function haptic(kind) {
    try {
      if (navigator.vibrate) {
        navigator.vibrate(kind === 'big' ? [12, 45, 22] : kind === 'error' ? [40, 60, 40] : kind === 'ok' ? 6 : 9);
      } else if (IOS && kind !== 'ok' && kind !== 'error') {
        if (!hap) {
          hap = document.createElement('label');
          hap.className = 'ic-hap';
          hap.setAttribute('aria-hidden', 'true');
          hap.innerHTML = '<input type="checkbox" switch tabindex="-1">';
          document.body.appendChild(hap);
        }
        hap.click();
      }
    } catch (e) { /* no haptics here */ }
  }

  // the product's picture arcs from the button into the cart (or into the toast when the cart is
  // off screen, e.g. a phone scrolled down); the cart squishes as it lands
  var lastFly = 0, flying = 0;
  function onScreen(r) { return r.width && r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth; }
  function fly(btn, item) {
    var cart = document.querySelector('.blockcart .header, .blockcart, .cart-preview');
    var now = Date.now();
    if (reduced() || !btn.animate || now - lastFly < 110 || flying >= 4) { if (cart && now - lastFly >= 110) bump(cart); return; }
    lastFly = now;
    var from = btn.getBoundingClientRect(), to = null;
    if (cart && onScreen(cart.getBoundingClientRect())) {
      var cr = cart.getBoundingClientRect();
      to = { x: cr.left + cr.width / 2, y: cr.top + cr.height / 2, el: cart };
    } else if (box) {
      var pic = box.querySelector('img, .ic-mark');
      if (pic) to = { x: box.offsetLeft + pic.offsetLeft + pic.offsetWidth / 2, y: box.offsetTop + pic.offsetTop + pic.offsetHeight / 2, el: null };
    }
    if (!to) return;
    var el = document.createElement('span');
    el.className = 'ic-fly';
    el.setAttribute('aria-hidden', 'true');
    if (item && item.img) el.style.backgroundImage = 'url("' + String(item.img).replace(/"/g, '%22') + '")';
    var x0 = from.left + from.width / 2, y0 = from.top + from.height / 2;
    el.style.left = (x0 - 22) + 'px';
    el.style.top = (y0 - 22) + 'px';
    document.body.appendChild(el);
    flying++;
    var dx = to.x - x0, dy = to.y - y0, lift = Math.min(170, Math.abs(dx) * 0.3 + 70), frames = [];
    for (var i = 0; i <= 12; i++) {
      var t = i / 12, sc = t < 0.18 ? 0.5 + t / 0.18 * 0.7 : 1.2 - 0.85 * (t - 0.18) / 0.82;
      frames.push({ transform: 'translate(' + (dx * t) + 'px,' + (dy * t - lift * 4 * t * (1 - t)) + 'px) scale(' + sc + ') rotate(' + (t * 160) + 'deg)', opacity: t > 0.94 ? 0 : 1 });
    }
    var anim = el.animate(frames, { duration: 520 + Math.min(240, Math.sqrt(dx * dx + dy * dy) * 0.22), easing: 'cubic-bezier(.4,.05,.55,1)', fill: 'forwards' });
    anim.onfinish = function () {
      el.remove();
      flying--;
      if (to.el) { to.el.classList.remove('ic-land'); void to.el.offsetWidth; to.el.classList.add('ic-land'); }
    };
  }

  // press: the button gives under the finger at once and springs back on release, with a ripple
  // from the touch point; no 300 ms tap delay, no grey tap flash (CSS)
  function ripple(btn, e) {
    if (reduced()) return;
    var r = btn.getBoundingClientRect(), clip = document.createElement('span'), ink = document.createElement('i');
    clip.className = 'ic-ink';
    clip.setAttribute('aria-hidden', 'true');
    clip.style.cssText = 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px;border-radius:' + getComputedStyle(btn).borderRadius;
    var d = Math.max(r.width, r.height) * 2.2;
    ink.style.cssText = 'width:' + d + 'px;height:' + d + 'px;left:' + (e.clientX - r.left - d / 2) + 'px;top:' + (e.clientY - r.top - d / 2) + 'px';
    clip.appendChild(ink);
    document.body.appendChild(clip);
    setTimeout(function () { clip.remove(); }, 650);
  }
  document.addEventListener('pointerdown', function (e) {
    var btn = e.target.closest && e.target.closest('[data-button-action="add-to-cart"]');
    if (!btn || btn.disabled || (e.button && e.button !== 0)) return;
    btn.classList.add('ic-press');
    ripple(btn, e);
    function up() {
      btn.classList.remove('ic-press');
      document.removeEventListener('pointerup', up, true);
      document.removeEventListener('pointercancel', up, true);
    }
    document.addEventListener('pointerup', up, true);
    document.addEventListener('pointercancel', up, true);
  }, true);

  // everything an add feels like: tick and the flight; more at ×5, ×10, ×20…
  function feel(btn, total, item) {
    var big = !!MILESTONES[total];
    haptic(big ? 'big' : 'tap');
    fly(btn, item);
    if (big) {
      burst(btn, true);
      var m = btn.querySelector('.ic-mult');
      if (m) { m.classList.remove('is-milestone'); void m.offsetWidth; m.classList.add('is-milestone'); }
    }
  }

  // state per button: how many were added in this burst and how many still wait for the shop
  function paint(btn) {
    var st = btn._ic;
    if (!st) return;
    if (!st.html) {
      st.html = btn.innerHTML;
      btn.style.minWidth = btn.getBoundingClientRect().width + 'px';
    }
    btn.classList.add('ic-state');
    btn.classList.toggle('is-saving', st.pending > 0);
    btn.classList.toggle('is-done', st.pending === 0);
    if (st.drawn) {
      var mult = btn.querySelector('.ic-mult');
      if (st.count > 1) {
        if (!mult) { mult = document.createElement('b'); mult.className = 'ic-mult'; btn.appendChild(mult); }
        if (mult.textContent !== '\u00d7' + st.count) mult.textContent = '\u00d7' + st.count;
      }
      btn.setAttribute('aria-label', (T.added || 'Added') + (st.count > 1 ? ' \u00d7' + st.count : ''));
      return;
    }
    st.drawn = true;
    // no word, just the jar (a tick appears in it when saved) and ×N
    btn.innerHTML = JAR + (st.count > 1 ? '<b class="ic-mult">\u00d7' + st.count + '</b>' : '');
    btn.setAttribute('aria-label', (T.added || 'Added') + (st.count > 1 ? ' \u00d7' + st.count : ''));
  }
  function restore(btn) {
    var st = btn._ic;
    if (!st) return;
    btn.innerHTML = st.html;
    btn.style.minWidth = '';
    btn.classList.remove('ic-state', 'is-saving', 'is-done');
    btn.removeAttribute('aria-label');
    btn._ic = null;
  }
  function started(btn, qty) {
    var st = btn._ic || (btn._ic = { count: 0, pending: 0, t: 0, html: '' });
    clearTimeout(st.t);
    st.count += qty;
    st.pending++;
    paint(btn);
    burst(btn);
    var m = btn.querySelector('.ic-mult');
    if (m) { m.classList.remove('is-pop'); void m.offsetWidth; m.classList.add('is-pop'); }
    return st.count;
  }
  function settled(btn, failed, clicks) {
    var st = btn._ic;
    if (!st) return;
    st.pending = Math.max(0, st.pending - (clicks || 1));
    if (failed) { restore(btn); return; }
    paint(btn);
    if (!st.pending) st.t = setTimeout(function () { restore(btn); }, 1600);
  }

  /* ----- requests ----- */

  function post(url, body, extra) {
    var headers = { Accept: 'application/json' };
    if (extra) headers['X-Requested-With'] = 'XMLHttpRequest';
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: headers, keepalive: true })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); });
  }

  function emit(name, data) {
    var ps = window.prestashop;
    if (ps && typeof ps.emit === 'function') ps.emit(name, data);
  }

  // the shop's own add-to-cart (with its pop-up), for anything the lean endpoint hands back
  function classic(form, qty) {
    var body = new URLSearchParams(new FormData(form));
    if (qty) body.set('qty', qty);
    body.set('add', '1');
    body.set('action', 'update');
    return post(form.getAttribute('action') || (window.prestashop && window.prestashop.urls.pages.cart), body, true).then(function (resp) {
      if (!resp || resp.hasError) {
        var errors = resp && resp.errors;
        throw new Error(Array.isArray(errors) ? errors.join(' ') : (errors || ''));
      }
      emit('updateCart', {
        reason: { idProduct: resp.id_product, idProductAttribute: resp.id_product_attribute, idCustomization: resp.id_customization, linkAction: 'add-to-cart', cart: resp.cart },
        resp: resp
      });
      return resp.cart ? resp.cart.products_count : null;
    });
  }

  /**
   * The optimistic add, shared by the shop's button and other modules: the button, the header count
   * and the confirmation answer now; send() runs in the background (in click order) and resolves
   * {ok, count?, error?}; only a refusal undoes it.
   */
  function answer(btn, qty, item) {
    var total = started(btn, qty);
    setCount(readCount() + qty);
    toast(item, total);
    feel(btn, total, item);
    pending++;
  }
  // queue send(); qty / clicks are what it covers, undone together if the shop says no
  function queue(btn, qty, clicks, item, send) {
    function done(failed) {
      pending -= clicks;
      settled(btn, failed, clicks);
    }
    chain = chain.then(send).then(function (res) {
      if (!res || !res.ok) {
        setCount(readCount() - qty);
        done(true);
        toast(item, qty, (res && res.error) || T.error);
        haptic('error');
        return;
      }
      done(false);
      if (!pending) haptic('ok');
      // only once nothing else is on its way: the shop's number would otherwise undo newer clicks
      if (pending === 0 && res.count !== null && res.count !== undefined) setCount(res.count);
      emit('instantCartAdded', res);
      if (C.notify) {
        emit('updateCart', { reason: { idProduct: res.id_product, idProductAttribute: res.id_product_attribute, linkAction: 'instant-add' }, resp: res });
      }
    }, function (e) {
      setCount(readCount() - qty);
      done(true);
      toast(item, qty, (e && e.message) || T.error);
      haptic('error');
    });
  }
  function run(btn, qty, item, send) {
    answer(btn, qty, item);
    queue(btn, qty, 1, item, send);
  }

  /**
   * The shop's own button: every click is answered at once, but clicks on the same button within
   * BATCH_MS of each other are collected and sent as one request with the summed quantity
   * (a long click streak is still sent every BATCH_MAX ms).
   */
  function add(form, btn) {
    var field = form.querySelector('[name="qty"]');
    var qty = Math.max(1, parseInt(field && field.value, 10) || 1);
    var item = about(btn);
    answer(btn, qty, item);
    var bt = btn._icBatch || (btn._icBatch = { qty: 0, clicks: 0, timer: 0, first: 0 });
    if (!bt.clicks) bt.first = Date.now();
    bt.qty += qty;
    bt.clicks++;
    clearTimeout(bt.timer);
    if (Date.now() - bt.first >= BATCH_MAX) flush(form, btn, item);
    else bt.timer = setTimeout(function () { flush(form, btn, item); }, BATCH_MS);
  }
  function flush(form, btn, item) {
    var bt = btn._icBatch;
    if (!bt || !bt.clicks) return;
    clearTimeout(bt.timer);
    var qty = bt.qty, clicks = bt.clicks;
    bt.qty = bt.clicks = 0;
    var body = new FormData(form);
    body.set('qty', qty);
    if (!body.get('token') && window.prestashop) body.set('token', window.prestashop.static_token || '');
    queue(btn, qty, clicks, item, function () {
      return post(C.url, body).then(function (res) {
        if (res.fallback) throw { fallback: true };
        return res;
      }).catch(function () {
        // lean path not possible: let the shop do it its own way (with its pop-up)
        hide();
        return classic(form, qty).then(function (count) { return { ok: true, count: count }; });
      });
    });
  }
  // leaving the page mid-streak: send what was collected
  window.addEventListener('pagehide', function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-button-action="add-to-cart"]'), function (b) {
      if (b._icBatch && b._icBatch.clicks) flush(b.form || b.closest('form'), b, about(b));
    });
  });

  // for other modules (e.g. the product composer): window.InstantCart.add(button, qty, send[, item])
  window.InstantCart = {
    add: function (btn, qty, send, item) {
      run(btn, Math.max(1, parseInt(qty, 10) || 1), item || about(btn), send);
    }
  };

  /* ----- product lists: the module prints a small add form per miniature; put it next to the
     theme's "see" button (also for lists swapped in by filters or instant navigation) ----- */

  function placeMinis() {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-ic-mini]:not([data-placed])'), function (f) {
      f.setAttribute('data-placed', '1');
      var card = f.closest('.product-miniature, .js-product-miniature');
      var slot = card && card.querySelector('.product-miniature-buttons-container');
      if (slot) slot.appendChild(f);
      f.classList.add('is-placed');
    });
  }
  var placeQueued = false;
  function queuePlace() {
    if (placeQueued) return;
    placeQueued = true;
    requestAnimationFrame(function () { placeQueued = false; placeMinis(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', placeMinis);
  else placeMinis();
  if ('MutationObserver' in window) {
    new MutationObserver(function (list) {
      for (var i = 0; i < list.length; i++) if (list[i].addedNodes.length) { queuePlace(); return; }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  /* ----- instant remove on the cart page: the line goes at once, the shop is told in the
     background (lean endpoint, quick removes sent together), a refusal brings the line back ----- */

  var rm = { queue: [], timer: 0 };
  function lineParams(link) {
    var p = { p: +link.getAttribute('data-id-product') || 0, a: +link.getAttribute('data-id-product-attribute') || 0, c: +link.getAttribute('data-id-customization') || 0 };
    if (!p.p) {
      try {
        var u = new URL(link.href, location.href);
        p = { p: +u.searchParams.get('id_product') || 0, a: +u.searchParams.get('id_product_attribute') || 0, c: +u.searchParams.get('id_customization') || 0 };
      } catch (e) { /* no URL support */ }
    }
    return p;
  }
  // the line's state decides; a newer animation (undo while it is still sliding out) cancels the older
  function animateLine(line, frames, gone) {
    line._icGone = gone;
    if (line._icAnim) line._icAnim.cancel();
    line.style.overflow = 'hidden';
    var a = line._icAnim = line.animate(frames, { duration: 340, easing: 'cubic-bezier(.4,0,.2,1)' });
    a.onfinish = function () {
      if (line._icAnim !== a) return;
      line._icAnim = null;
      line.style.overflow = '';
      line.hidden = line._icGone;
    };
  }
  function collapse(line) {
    if (reduced() || !line.animate) { line._icGone = true; line.hidden = true; return; }
    var h = line.offsetHeight;
    animateLine(line, [
      { height: h + 'px', opacity: 1, transform: 'none' },
      { height: h + 'px', opacity: 0, transform: 'translateX(48px)', offset: 0.45 },
      { height: '0px', opacity: 0, transform: 'translateX(48px)', marginTop: '0px', marginBottom: '0px', paddingTop: '0px', paddingBottom: '0px' }
    ], true);
  }
  function expand(line) {
    line._icGone = false;
    if (line._icAnim) { line._icAnim.cancel(); line._icAnim = null; }
    line.hidden = false;
    line.style.overflow = '';
    if (reduced() || !line.animate) return;
    var h = line.offsetHeight;
    animateLine(line, [
      { height: '0px', opacity: 0, transform: 'translateX(48px)' },
      { height: h + 'px', opacity: 0, transform: 'translateX(48px)', offset: 0.5 },
      { height: h + 'px', opacity: 1, transform: 'none' }
    ], false);
  }
  // the summary shows "updating" until the shop sends the new totals
  function pendingTotals(on) {
    Array.prototype.forEach.call(document.querySelectorAll('.cart-summary-line .value, .cart-total .value, .js-subtotal'), function (el) {
      el.classList.toggle('ic-pending', on);
    });
  }
  function setText(sel, text) {
    var els = document.querySelectorAll(sel);
    Array.prototype.forEach.call(els, function (el) { el.textContent = text; });
    return els.length > 0;
  }
  function patchTotals(res) {
    var t = res.totals;
    if (!t) return false;
    if (res.label) setText('.js-subtotal', res.label);
    var found = setText('#cart-subtotal-products .value', t.products);
    setText('#cart-subtotal-shipping .value', t.shipping);
    if (t.discount) setText('#cart-subtotal-discount .value', t.discount);
    return setText('.cart-summary-totals .cart-total .value, .cart-detailed-totals .cart-total .value', t.total) && found;
  }

  function removeLine(link, line) {
    var e = lineParams(link);
    if (!e.p) return false;
    var spin = line.querySelector('.js-cart-line-product-quantity, input[name="product-quantity-spin"]');
    var name = line.querySelector('.product-line-info a, .label, .product-title');
    var img = line.querySelector('.product-line-grid-left img, .product-image img, img');
    e.qty = Math.max(1, parseInt(spin && spin.value, 10) || 1);
    e.line = line;
    e.href = link.href;
    e.item = { name: name ? name.textContent.trim() : '', img: img ? (img.currentSrc || img.src) : '' };
    collapse(line);
    setCount(readCount() - e.qty);
    pendingTotals(true);
    haptic('tap');
    rm.queue.push(e);
    clearTimeout(rm.timer);
    rm.timer = setTimeout(flushRemove, BATCH_MS);
    // undo: before the request it is simply cancelled; after it, a plain line is added back
    toast(e.item, e.qty, null, { label: T.undo || 'Undo', fn: function () { undoRemove(e); } });
    return true;
  }
  function undoRemove(e) {
    var i = rm.queue.indexOf(e);
    if (i >= 0) {
      rm.queue.splice(i, 1);
      if (!rm.queue.length) { clearTimeout(rm.timer); pendingTotals(false); }
      expand(e.line);
      setCount(readCount() + e.qty);
      return;
    }
    if (!e.done || e.c) return;   // a customised line cannot be put back from here
    expand(e.line);
    setCount(readCount() + e.qty);
    pendingTotals(true);
    var body = new FormData();
    body.set('token', (window.prestashop && window.prestashop.static_token) || '');
    body.set('id_product', e.p); body.set('id_product_attribute', e.a); body.set('id_customization', 0); body.set('qty', e.qty);
    chain = chain.then(function () { return post(C.url, body); }).then(function (res) {
      if (!res || !res.ok) throw new Error((res && res.error) || '');
      if (res.count !== undefined) setCount(res.count);
      e.done = false;
      // the totals and the line's own numbers come from the shop's normal refresh
      refreshCart(e, true);
    }).catch(function (err) {
      collapse(e.line);
      setCount(readCount() - e.qty);
      pendingTotals(false);
      toast(e.item, e.qty, (err && err.message) || T.error);
    });
  }
  function refreshCart(e, full) {
    if (full) emit('updateCart', { reason: { idProduct: e.p, idProductAttribute: e.a, idCustomization: e.c, linkAction: 'delete-from-cart' }, resp: {} });
    // lighter: widgets that follow the cart (the packages widget…) without re-rendering the page
    emit('updatedCart', { eventType: 'updateCart', resp: {} });
    if (full && !(window.prestashop && typeof window.prestashop.emit === 'function')) location.reload();
  }
  function flushRemove() {
    var batch = rm.queue.splice(0);
    if (!batch.length) return;
    batch.forEach(function (e) { e.sent = true; });
    var body = new FormData();
    body.set('token', (window.prestashop && window.prestashop.static_token) || '');
    body.set('lines', JSON.stringify(batch.map(function (e) { return { p: e.p, a: e.a, c: e.c }; })));
    pending += batch.length;
    function restore(e) { expand(e.line); setCount(readCount() + e.qty); }
    chain = chain.then(function () { return post(C.removeUrl, body); }).then(function (res) {
      pending -= batch.length;
      if (res && res.fallback) {
        // the lean path can't do it: the shop's own delete link (it reloads the cart)
        location.href = batch[0].href;
        return;
      }
      if (!res || !res.ok) throw new Error((res && res.error) || '');
      (res.failed || []).forEach(function (i) { if (batch[i]) { restore(batch[i]); batch[i].failed = true; } });
      batch.forEach(function (e) { if (!e.failed) e.done = true; });
      if (!pending && res.count !== undefined) setCount(res.count);
      var patched = patchTotals(res);
      pendingTotals(false);
      haptic('ok');
      emit('instantCartRemoved', res);
      // an emptied cart or changed vouchers show different blocks: let the theme re-render them
      refreshCart(batch[0], !res.count || res.rules || !patched || (res.failed && res.failed.length) || C.notify);
    }).catch(function (err) {
      pending -= batch.length;
      batch.forEach(restore);
      pendingTotals(false);
      toast(batch[0].item, batch[0].qty, (err && err.message) || T.error);
      haptic('error');
    });
  }
  // leaving the page right after a remove: still send it
  window.addEventListener('pagehide', function () { if (rm.queue.length) { clearTimeout(rm.timer); flushRemove(); } });

  if (C.removeUrl) {
    document.addEventListener('click', function (e) {
      var link = e.target.closest && e.target.closest('[data-link-action="delete-from-cart"], a.remove-from-cart');
      if (!link || e.button || e.metaKey || e.ctrlKey) return;
      var line = link.closest('.cart-item, .cart-items > li');
      if (!line || !removeLine(link, line)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
    }, true);
  }

  // capture phase: runs before the theme's own (jQuery) handler, which it then stops
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-button-action="add-to-cart"]');
    if (!btn || btn.disabled) return;
    var form = btn.form || btn.closest('form');
    if (!form || !form.querySelector('[name="id_product"]')) return;
    // the product composer has its own add-to-cart: leave it alone
    if (document.getElementById('divInfobia') || form.querySelector('.inputInfobia')) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    add(form, btn);
  }, true);
})();
