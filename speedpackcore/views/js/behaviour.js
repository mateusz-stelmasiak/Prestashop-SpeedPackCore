/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * Behaviour: what a shopper does on this page, sent to the shop (controllers/front/collect.php).
 *
 * - One page view per page shown: a normal load, an InstantNav swap (instantnav:loaded), or a
 *   page back from the browser's back/forward cache. A page Chrome prerendered is only counted
 *   once it is actually shown.
 * - Engaged time: the page visible and in use (a scroll, a tap, a key or a pointer move in the
 *   last minute). Scroll depth: how far down the page got.
 * - Events: add to cart (the theme's or InstantCart's), each checkout step, the pay button,
 *   error messages in the cart and checkout, searches with their result count.
 * - Core Web Vitals as the shopper got them: LCP, TTFB and FCP of a page load; INP and CLS of
 *   every page shown, InstantNav swaps included (measured as Chrome's field data measures them).
 *
 * Messages go out with navigator.sendBeacon: they survive the page closing and never hold it up.
 * Nothing is stored in the browser; the shop ties the pages of a visit together itself. The page
 * type and object come from <body>: PrestaShop sets id="product" and class="product-id-7".
 */
(function () {
    'use strict';

    var cfg = window.spcBehaviour;
    if (!cfg || !cfg.url || !window.JSON || !document.addEventListener) { return; }
    // robots and automated browsers are not shoppers (the browser string is read here, never sent)
    if (cfg.skipBots !== false && (navigator.webdriver || /bot|crawl|spider|slurp|headless|lighthouse|pagespeed/i.test(navigator.userAgent || ''))) {
        return;
    }

    var IDLE = cfg.idle > 0 ? cfg.idle : 60000;
    var TICK = 1000;
    var STEPS = {
        'checkout-personal-information-step': 'personal',
        'checkout-addresses-step': 'addresses',
        'checkout-delivery-step': 'delivery',
        'checkout-payment-step': 'payment'
    };
    var TYPES = ['product', 'category', 'cms', 'manufacturer', 'supplier'];

    var queue = [];
    var view = null;
    var lastInput = now();
    var lastTick = now();
    var flushTimer = null;
    var lastCart = 0;
    var started = false;
    var loadSeen = false;
    // the page load's own figures (they belong to the first page view of the document)
    var doc = { lcp: 0, ttfb: 0, fcp: 0 };
    var supported = { cls: false };

    function now() { return new Date().getTime(); }

    function vkey() {
        var a = new Uint32Array(1);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(a);
        } else {
            a[0] = Math.floor(Math.random() * 4294967295);
        }
        return ('0000000' + a[0].toString(16)).slice(-8);
    }

    /* ---------------------------------------------------------------- *
     *  Sending
     * ---------------------------------------------------------------- */

    function send(messages) {
        if (!messages.length) { return; }
        var body = JSON.stringify({ m: messages });
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(cfg.url, new Blob([body], { type: 'text/plain' }))) { return; }
        } catch (e) { /* fall through */ }
        try {
            fetch(cfg.url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } });
        } catch (e) { /* lost: a page view is not worth more */ }
    }

    function flush() {
        clearTimeout(flushTimer);
        flushTimer = null;
        var out = queue;
        queue = [];
        send(out);
    }

    function push(message, soon) {
        queue.push(message);
        if (queue.length >= 30) { flush(); return; }
        if (!flushTimer) { flushTimer = setTimeout(flush, soon ? 800 : 5000); }
    }

    /* ---------------------------------------------------------------- *
     *  The page
     * ---------------------------------------------------------------- */

    function pageInfo() {
        var body = document.body;
        var type = (body && body.id) || (window.prestashop && window.prestashop.page && window.prestashop.page.page_name) || 'other';
        type = String(type).toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 31) || 'other';
        var id = 0;
        if (TYPES.indexOf(type) !== -1 && body) {
            var m = new RegExp('(?:^|\\s)' + type + '-id-(\\d+)(?:\\s|$)').exec(body.className);
            if (m) { id = parseInt(m[1], 10); }
        }
        return { type: type, id: id };
    }

    var TRACKING = /^(utm_[a-z]+|gclid|fbclid|msclkid|dclid|yclid|_ga|mc_[a-z]+|spc_[a-z]+)$/;

    function address() {
        var q = [];
        location.search.replace(/^\?/, '').split('&').forEach(function (pair) {
            if (pair && !TRACKING.test(decodeURIComponent(pair.split('=')[0]))) { q.push(pair); }
        });
        return (location.pathname + (q.length ? '?' + q.join('&') : '')).slice(0, 255);
    }

    function param(name) {
        var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(location.search);
        if (!m) { return ''; }
        try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
    }

    function device() {
        var w = window.innerWidth || document.documentElement.clientWidth || 1024;
        return w < 768 ? 2 : (w < 1024 ? 1 : 0);
    }

    function depth() {
        var doc = document.documentElement;
        var height = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0);
        if (!height) { return 0; }
        var seen = (window.pageYOffset || doc.scrollTop || 0) + (window.innerHeight || doc.clientHeight || 0);
        return Math.max(0, Math.min(100, Math.round(100 * seen / height)));
    }

    /** A page shown: closes the one before it and reports the new one. */
    function begin(nav) {
        end();
        var info = pageInfo();
        view = { k: vkey(), type: info.type, ms: 0, scroll: depth(), step: '', errors: {}, sent: '', inp: 0, cls: 0, win: 0, winStart: 0, winLast: 0, load: nav === 0 && !loadSeen };
        if (nav === 0) { loadSeen = true; }
        var m = { t: 'v', k: view.k, p: info.type, i: info.id, u: address(), n: nav, d: device() };
        if (nav === 0) {
            // where the visit came from: only the first page of a document can say
            if (document.referrer) { m.r = document.referrer.slice(0, 255); }
            var a = { s: param('utm_source'), m: param('utm_medium'), c: param('utm_campaign'), g: param('gclid') || param('msclkid') ? 1 : 0, f: param('fbclid') ? 1 : 0 };
            if (a.s || a.m || a.c || a.g || a.f) { m.a = a; }
        }
        push(m, true);
        if (info.type === 'search') { searched(); }
        watch();
    }

    /** The time spent on the page so far (running totals: a lost message costs nothing). */
    function end() {
        if (!view) { return; }
        tick();
        var m = { t: 't', k: view.k, ms: view.ms, s: view.scroll };
        if (view.inp) { m.in = Math.round(view.inp); }
        if (view.cls || (view.ms > 0 && supported.cls)) { m.c = Math.round(view.cls * 1000); }
        if (view.load) {
            if (doc.lcp) { m.l = Math.round(doc.lcp); }
            if (doc.ttfb) { m.b = Math.round(doc.ttfb); }
            if (doc.fcp) { m.f = Math.round(doc.fcp); }
        }
        var sig = JSON.stringify(m);
        if (sig !== view.sent) {
            queue.push(m);
            view.sent = sig;
        }
    }

    function event(type, detail, value, soon) {
        if (!view) { return; }
        var m = { t: 'e', k: view.k, e: type };
        if (detail) { m.d = String(detail).slice(0, 128); }
        if (typeof value === 'number') { m.x = value; }
        push(m, soon);
    }

    /* ---------------------------------------------------------------- *
     *  Time and scroll
     * ---------------------------------------------------------------- */

    function tick() {
        var t = now();
        if (view && document.visibilityState !== 'hidden' && t - lastInput < IDLE) {
            // a timer the browser held back (a sleeping laptop) counts one tick at most
            view.ms += Math.min(t - lastTick, TICK * 2);
        }
        lastTick = t;
    }

    function input() {
        lastInput = now();
    }

    function scrolled() {
        input();
        if (view) { view.scroll = Math.max(view.scroll, depth()); }
    }

    /* ---------------------------------------------------------------- *
     *  What happens on the page
     * ---------------------------------------------------------------- */

    function searched() {
        var q = param('s') || param('search_query') || param('q');
        if (!q) { return; }
        setTimeout(function () {
            var count = -1;
            var total = document.querySelector('#js-product-list-top .total-products, .total-products, #js-product-list-header');
            var match = total && /(\d[\d\s.,]*)/.exec(total.textContent || '');
            if (match) {
                count = parseInt(match[1].replace(/\D/g, ''), 10);
            } else if (document.querySelector('#js-product-list, #products')) {
                count = document.querySelectorAll('#js-product-list .product-miniature, #products .product-miniature').length;
            }
            if (document.querySelector('#product-search-no-matches, .page-not-found') && !document.querySelector('.product-miniature')) { count = 0; }
            event('search', q.trim().toLowerCase(), count, true);
        }, 300);
    }

    function cartAdded() {
        // the theme's updateCart and InstantCart's own event can both report one click
        if (now() - lastCart < 600) { return; }
        lastCart = now();
        event('cart', '', undefined, true);
    }

    /** The checkout step shown, and error messages, on the cart and checkout pages. */
    function watch() {
        if (!view || (view.type !== 'checkout' && view.type !== 'order' && view.type !== 'cart')) { return; }
        var mine = view;
        var look = function () {
            if (view !== mine) { return; }
            var current = document.querySelector('.checkout-step.-current');
            var step = current && STEPS[current.id];
            if (step && step !== mine.step) {
                mine.step = step;
                event('step', step, undefined, true);
            }
            var alerts = document.querySelectorAll('#notifications .alert-danger, #checkout .alert-danger, .cart-grid .alert-danger, #checkout .help-block li, .js-error .alert');
            for (var i = 0; i < alerts.length; i++) {
                var el = alerts[i];
                var text = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100);
                if (text && el.offsetParent !== null && !mine.errors[text]) {
                    mine.errors[text] = 1;
                    event('error', (mine.step ? mine.step + ': ' : '') + text, undefined, true);
                }
            }
            setTimeout(look, 1000);
        };
        look();
    }

    function clicked(e) {
        input();
        var t = e.target;
        while (t && t !== document.body && t.nodeType === 1) {
            if (t.hasAttribute && t.hasAttribute('data-spc-reorder')) {
                event('reorder', '', undefined, true);
                flush();
                return;
            }
            if (t.id === 'payment-confirmation' || (t.matches && t.matches('#payment-confirmation button, #payment-confirmation [type=submit]'))) {
                event('pay', '', undefined, true);
                return;
            }
            t = t.parentNode;
        }
    }

    function listenToShop() {
        var ps = window.prestashop;
        if (!ps || typeof ps.on !== 'function') { return; }
        ps.on('updateCart', function (e) {
            var action = e && e.reason && e.reason.linkAction;
            if (action === 'add-to-cart' || action === 'instant-add') { cartAdded(); }
        });
        ps.on('instantCartAdded', cartAdded);
    }

    /* ---------------------------------------------------------------- *
     *  Core Web Vitals
     * ---------------------------------------------------------------- */

    function observe(type, fn, extra) {
        if (!window.PerformanceObserver) { return false; }
        try {
            var opts = { type: type, buffered: true };
            Object.keys(extra || {}).forEach(function (k) { opts[k] = extra[k]; });
            new PerformanceObserver(function (list) { list.getEntries().forEach(fn); }).observe(opts);
            return true;
        } catch (e) {
            return false;
        }
    }

    function vitals() {
        // a prerendered page counts from when it was shown, not from when it was built
        var navEntry = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        var shown = navEntry && navEntry.activationStart > 0 ? navEntry.activationStart : 0;
        if (navEntry && navEntry.responseStart > 0) { doc.ttfb = Math.max(0, navEntry.responseStart - shown); }
        observe('paint', function (e) { if (e.name === 'first-contentful-paint') { doc.fcp = Math.max(0, e.startTime - shown); } });
        // the browser stops reporting candidates at the first input: the last one is the LCP
        observe('largest-contentful-paint', function (e) { doc.lcp = Math.max(0, e.startTime - shown); });
        // CLS: the worst burst of shifts (gaps under 1 s, at most 5 s long) not caused by input
        supported.cls = observe('layout-shift', function (e) {
            if (!view || e.hadRecentInput) { return; }
            if (view.win && e.startTime - view.winLast < 1000 && e.startTime - view.winStart < 5000) {
                view.win += e.value;
            } else {
                view.win = e.value;
                view.winStart = e.startTime;
            }
            view.winLast = e.startTime;
            view.cls = Math.max(view.cls, view.win);
        });
        // INP: the slowest interaction, from input to the next paint
        var slow = function (e) { if (view && (e.interactionId || e.entryType === 'first-input')) { view.inp = Math.max(view.inp, e.duration); } };
        observe('event', slow, { durationThreshold: 16 });
        observe('first-input', slow);
    }

    /* ---------------------------------------------------------------- *
     *  Start
     * ---------------------------------------------------------------- */

    function start() {
        if (started) { return; }
        started = true;
        var passive = { passive: true, capture: true };
        ['pointerdown', 'keydown', 'touchstart', 'mousemove', 'wheel'].forEach(function (name) {
            document.addEventListener(name, input, passive);
        });
        document.addEventListener('scroll', scrolled, passive);
        document.addEventListener('click', clicked, true);
        document.addEventListener('instantnav:loaded', function () { begin(1); });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                end();
                flush();
            } else {
                lastTick = now();
                input();
            }
        });
        window.addEventListener('pagehide', function () { end(); flush(); });
        window.addEventListener('pageshow', function (e) { if (e.persisted) { begin(2); } });
        setInterval(tick, TICK);
        listenToShop();
        begin(0);
        vitals();
    }

    /** With "only after analytics consent": a banner's answer, read several ways. */
    function consented() {
        if (window.spcBehaviourConsent === true) { return true; }
        var dl = window.dataLayer;
        var granted = false;
        if (dl && dl.length) {
            for (var i = 0; i < dl.length; i++) {
                var entry = dl[i];
                if (entry && entry[0] === 'consent' && entry[2] && entry[2].analytics_storage) {
                    granted = entry[2].analytics_storage === 'granted';
                }
            }
        }
        return granted;
    }

    function whenAllowed(go) {
        if (!cfg.consent || consented()) { go(); return; }
        document.addEventListener('spc:consent', go, { once: true });
        var tries = 0;
        var poll = setInterval(function () {
            if (consented() || ++tries > 900) {
                clearInterval(poll);
                if (tries <= 900) { go(); }
            }
        }, 2000);
    }

    function boot() {
        // a page prerendered on hover is not a page seen until the shopper opens it
        if (document.prerendering) {
            document.addEventListener('prerenderingchange', function () { whenAllowed(start); }, { once: true });
            return;
        }
        whenAllowed(start);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
