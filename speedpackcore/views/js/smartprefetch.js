/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * Smart Prefetch - page side.
 *
 * Decides what is worth fetching ahead of the visitor and hands the list to a
 * service worker, which does the downloading off the main thread and keeps
 * the results in Cache Storage. The browser then serves the next navigation
 * from that cache itself.
 *
 * Two strategies feed the worker:
 *
 *   Intent  - the visitor hovers, focuses or touches a link. High hit rate,
 *             almost no waste, so it runs everywhere.
 *   Warm-up - a small capped set of top-level links on the session's first
 *             page, for the visitor who has not moved yet.
 *
 * Where service workers are unavailable it falls back to <link rel=prefetch>,
 * which is a hint rather than a download, but costs nothing to keep.
 *
 * Nothing here runs on the main thread during load: the script is deferred
 * and every decision is scheduled through requestIdleCallback.
 */
(function () {
    'use strict';

    var cfg = window.smartPrefetchConfig || {};

    if (cfg.enabled === false) {
        return;
    }

    var ORIGIN = window.location.origin;
    var CURRENT = stripHash(window.location.href);

    var hoverDelay = num(cfg.hoverDelay, 65);
    var maxTotal = num(cfg.maxTotal, 12);
    var maxWarmup = num(cfg.maxWarmup, 3);
    var useViewport = cfg.viewport === true;
    var debug = cfg.debug !== false;

    var DENY_PARAM = /(^|&)(add|delete|deleteproduct|deleteaddress|update|reorder|mylogout|logout|token|submitdelete|submitaddtocart|action|ajax|id_customization)(=|&|$)/i;
    var DENY_EXT = /\.(pdf|zips?|rar|7z|docx?|xlsx?|csv|jpe?g|png|gif|webp|avif|svg|ico|mp4|webm|mp3|css|js|json|xml|rss|ics)($|\?)/i;

    var denyPrefixes = (cfg.denyPrefixes || []).map(function (p) {
        return String(p).toLowerCase();
    });

    var seen = Object.create(null);
    var used = 0;
    var worker = null;
    var log = [];

    function say(message, detail) {
        if (!debug || !window.console || !window.console.info) { return; }
        if (detail === undefined) {
            window.console.info('[smart-prefetch] ' + message);
        } else {
            window.console.info('[smart-prefetch] ' + message, detail);
        }
    }

    /* ---------------------------------------------------------------- *
     *  Should we do anything at all?
     * ---------------------------------------------------------------- */

    function connectionAllows() {
        var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!c) { return true; }

        if (c.saveData) {
            say('standing down: Data Saver is on');
            return false;
        }
        if (typeof c.effectiveType === 'string' && /(^|-)(2g|slow-2g)$/.test(c.effectiveType)) {
            say('standing down: connection is ' + c.effectiveType);
            return false;
        }
        return true;
    }

    function environmentAllows() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-data: reduce)').matches) {
            say('standing down: prefers-reduced-data');
            return false;
        }
        return connectionAllows();
    }

    /**
     * Whether a cached HTML document could safely be shown again.
     *
     * A shop page carries the customer in its markup, so a page fetched while
     * signed in must not be served after signing out. The page is the only
     * side that can see who is signed in, so it tells the worker, which keeps
     * the two apart on separate shelves.
     *
     * This used to refuse to cache anything at all once there was a cart or a
     * customer -- which is to say, for most of the people it was meant to
     * help. Keeping the shelves apart is the same guarantee without the cost.
     */
    function identity() {
        var shop = window.prestashop;
        if (shop && shop.customer && shop.customer.is_logged) { return 'member'; }
        return 'guest';
    }

    /* ---------------------------------------------------------------- *
     *  Eligibility
     * ---------------------------------------------------------------- */

    /**
     * Whether a URL may be touched ahead of the visitor at all: same shop,
     * ordinary page, nothing that changes state. Separate from the prefetch
     * budget below, so a caller can ask the safety question on its own,
     * without caring how many pages have already been fetched.
     */
    function safeToVisit(url) {
        if (!url) { return false; }

        var parsed;
        try {
            parsed = new URL(url, window.location.href);
        } catch (e) {
            return false;
        }

        if (parsed.origin !== ORIGIN) { return false; }
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') { return false; }
        if (DENY_EXT.test(parsed.pathname)) { return false; }
        if (parsed.search && DENY_PARAM.test(parsed.search.slice(1))) { return false; }

        var path = parsed.pathname.toLowerCase();
        for (var i = 0; i < denyPrefixes.length; i++) {
            if (denyPrefixes[i] && path.indexOf(denyPrefixes[i]) === 0) { return false; }
        }

        return true;
    }

    function eligible(url, anchor) {
        if (!url || used >= maxTotal || seen[url]) { return false; }

        if (anchor) {
            if (anchor.hasAttribute('download') || anchor.hasAttribute('data-no-prefetch')) { return false; }
            if (anchor.getAttribute('data-button-action') || anchor.getAttribute('rel') === 'nofollow') { return false; }
        }

        /* Normalised first: a relative href is the current page just as
         * often as an absolute one is. */
        try {
            if (stripHash(new URL(url, window.location.href).href) === CURRENT) { return false; }
        } catch (e) {
            return false;
        }

        return safeToVisit(url);
    }

    /* ---------------------------------------------------------------- *
     *  Handing work to the worker
     * ---------------------------------------------------------------- */

    function request(url, reason) {
        var clean = stripHash(url);
        if (!eligible(clean, null)) { return; }

        seen[clean] = true;
        used++;
        log.push({ url: clean, reason: reason, at: Date.now() });

        if (worker) {
            worker.postMessage({ type: 'prefetch', urls: [clean], reason: reason });
        } else {
            hint(clean);
            say(reason + ' → ' + clean + '  (hint, no worker)  (' + used + '/' + maxTotal + ')');
        }

        if (used === maxTotal) {
            say('ceiling of ' + maxTotal + ' reached, nothing more this page');
        }
    }

    /** Fallback for browsers without a service worker: a prefetch hint. */
    function hint(url) {
        try {
            var link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = url;
            link.setAttribute('as', 'document');
            document.head.appendChild(link);
        } catch (e) {
            /* nothing to do */
        }
    }

    /* ---------------------------------------------------------------- *
     *  Strategy 1: intent
     * ---------------------------------------------------------------- */

    var hoverTimer = null;

    function anchorFrom(target) {
        var el = target;
        while (el && el.nodeType === 1) {
            if (el.tagName === 'A' && el.href) { return el; }
            el = el.parentElement;
        }
        return null;
    }

    function onIntent(event) {
        var anchor = anchorFrom(event.target);
        if (!anchor) { return; }

        var url = stripHash(anchor.href);
        if (!eligible(url, anchor)) { return; }

        if (event.type === 'touchstart' || event.type === 'focusin') {
            request(url, event.type === 'touchstart' ? 'touch' : 'focus');
            return;
        }

        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () {
            request(url, 'hover');
        }, hoverDelay);
    }

    function bindIntent() {
        var opts = supportsPassive() ? { passive: true, capture: true } : true;
        document.addEventListener('mouseover', onIntent, opts);
        document.addEventListener('mouseout', function () { clearTimeout(hoverTimer); }, opts);
        document.addEventListener('touchstart', onIntent, opts);
        document.addEventListener('focusin', onIntent, opts);
    }

    /* ---------------------------------------------------------------- *
     *  Strategy 2: warm-up
     * ---------------------------------------------------------------- */

    function warmup() {
        if (!cfg.warmupSelector || maxWarmup < 1 || alreadyWarmed()) { return; }
        markWarmed();

        var anchors = toArray(document.querySelectorAll(cfg.warmupSelector));
        var queue = [];

        for (var i = 0; i < anchors.length && queue.length < maxWarmup; i++) {
            var url = stripHash(anchors[i].href || '');
            if (url && eligible(url, anchors[i]) && queue.indexOf(url) === -1) {
                queue.push(url);
            }
        }

        (function next() {
            if (!queue.length) { return; }
            idle(function () {
                request(queue.shift(), 'warm-up');
                next();
            });
        }());
    }

    function alreadyWarmed() {
        try {
            return window.sessionStorage.getItem('smartprefetch:warmed') === '1';
        } catch (e) {
            return true;
        }
    }

    function markWarmed() {
        try {
            window.sessionStorage.setItem('smartprefetch:warmed', '1');
        } catch (e) { /* nothing to do */ }
    }

    /* ---------------------------------------------------------------- *
     *  Strategy 3: viewport (opt-in)
     * ---------------------------------------------------------------- */

    function observeViewport() {
        if (!useViewport || !window.IntersectionObserver || !cfg.viewportSelector) { return; }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                observer.unobserve(entry.target);
                idle(function () {
                    request(stripHash(entry.target.href || ''), 'in view');
                });
            });
        }, { rootMargin: '200px' });

        toArray(document.querySelectorAll(cfg.viewportSelector)).forEach(function (a) {
            observer.observe(a);
        });
    }

    /* ---------------------------------------------------------------- *
     *  The worker
     * ---------------------------------------------------------------- */

    function connect() {
        if (!('serviceWorker' in navigator) || !cfg.workerUrl) {
            say('no service worker available, falling back to prefetch hints');
            return Promise.resolve(null);
        }

        navigator.serviceWorker.addEventListener('message', function (event) {
            var data = event.data || {};

            if (data.type === 'cached') {
                say(data.reason + ' → ' + data.url + '  (downloaded)');
            } else if (data.type === 'served') {
                say('served from cache → ' + data.url);
            } else if (data.type === 'failed') {
                say('could not fetch ' + data.url);
            } else if (data.type === 'skipped') {
                say('not cacheable (' + data.status + ') ' + data.url);
            } else if (data.type === 'stats') {
                say('cache holds ' + data.documents + ' page(s) and ' + data.assets + ' asset(s)');
            }
        });

        /* A registration that never settles must not leave the warm-up
         * pending for the life of the page, so it races a timer. */
        var settled = new Promise(function (resolve) {
            setTimeout(function () { resolve(null); }, 4000);
        });

        var registering = navigator.serviceWorker.register(cfg.workerUrl, { scope: cfg.scope || '/' })
            .then(function () {
                return navigator.serviceWorker.ready;
            })
            .then(function (registration) {
                var active = registration.active || navigator.serviceWorker.controller;
                if (!active) { return null; }

                active.postMessage({
                    type: 'config',
                    denyPrefixes: denyPrefixes,
                    identity: identity()
                });

                watchCart(active);

                return active;
            })
            .catch(function (error) {
                say('service worker registration failed: ' + error.message);
                return null;
            });

        return Promise.race([registering, settled]);
    }

    /**
     * Every stored page prints the cart it was fetched with, so when the cart
     * changes they are all wrong. PrestaShop announces that itself.
     */
    function watchCart(active) {
        var shop = window.prestashop;
        if (!shop || typeof shop.on !== 'function') { return; }

        try {
            shop.on('updateCart', function () {
                active.postMessage({ type: 'flush' });
                say('cart changed, stored pages dropped');
            });
        } catch (e) {
            say('could not watch the cart: ' + e.message);
        }
    }

    /* ---------------------------------------------------------------- *
     *  Helpers
     * ---------------------------------------------------------------- */

    function stripHash(url) {
        var i = String(url).indexOf('#');
        return i === -1 ? String(url) : String(url).slice(0, i);
    }

    function num(value, fallback) {
        var n = parseInt(value, 10);
        return isNaN(n) ? fallback : n;
    }

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    function idle(fn) {
        if (window.requestIdleCallback) {
            window.requestIdleCallback(fn, { timeout: 2000 });
        } else {
            setTimeout(fn, 1);
        }
    }

    function supportsPassive() {
        var ok = false;
        try {
            window.addEventListener('x', null, Object.defineProperty({}, 'passive', {
                get: function () { ok = true; return true; }
            }));
        } catch (e) { /* nothing to do */ }
        return ok;
    }

    /* ---------------------------------------------------------------- *
     *  Start
     * ---------------------------------------------------------------- */

    function start() {
        window.smartPrefetch = {
            log: log,
            config: cfg,
            prefetched: function () {
                return log.map(function (e) { return e.url; });
            },
            stats: function () {
                if (worker) { worker.postMessage({ type: 'stats' }); }
            },
            identity: identity
        };

        if (!environmentAllows()) { return; }

        /* Bound before the worker is asked for. Registration can be slow,
         * or blocked outright by a browser setting, and intent prefetching
         * has no reason to wait on it - it works without one. */
        bindIntent();

        connect().then(function (active) {
            worker = active;

            idle(warmup);
            idle(observeViewport);

            say('active — ' + (worker ? 'service worker downloading in the background' : 'prefetch hints only') +
                ', hover ' + hoverDelay + 'ms, max ' + maxTotal + ' per page, ' + maxWarmup + ' warm-up' +
                ', pages stored as ' + identity() +
                '. window.smartPrefetch.prefetched() lists what was fetched.');
        });
    }

    if (document.readyState === 'complete') {
        idle(start);
    } else {
        window.addEventListener('load', function () { idle(start); }, { once: true });
    }
}());
