/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * Instant Navigation.
 *
 * Taking a menu link normally throws the whole document away and builds it
 * again - header, stylesheets, scripts, the lot - even though only the middle
 * of the page changed. This fetches the next page and drops its content
 * region in instead, so the header never repaints and the shop stops flashing
 * white between pages.
 *
 * It does its own prefetching on hover, into a small in-memory store, so it is
 * useful on its own. Where the Smart Prefetch service worker is also
 * installed the fetch is served from its cache and the swap is instant, but
 * nothing here depends on that.
 *
 * The loading placeholder is deliberately plain: soft blocks where the real
 * content will be, and nothing drawn inside them. It takes its colour, corner
 * radius and proportions from the theme by measuring what is on screen, so it
 * sits in the right places - but it does not try to draw thumbnails, prices
 * or filter panels. A placeholder that imitates the page in detail is a worse
 * drawing of it than the page itself, and it is wrong the moment any of that
 * moves.
 *
 * Every fallback points the same way. No History API, no DOMParser, a fetch
 * that fails, a response that is not a page, a document without the region, a
 * cross-origin or state-changing URL, or anything at all throwing mid-swap,
 * and the browser is handed the ordinary navigation it would have done
 * anyway. The worst case is the shop exactly as it was.
 */
(function () {
    'use strict';

    var cfg = window.instantNavConfig || {};

    if (cfg.enabled === false) { return; }

    var BUILD = '2026-10-06b';

    var ORIGIN = window.location.origin;
    var here = strip(window.location.href);

    var linkSelector = cfg.links || '';
    var region = cfg.region || '#wrapper';
    var cardSelector = cfg.cardSelector ||
        '.product-miniature .thumbnail-container, #content-wrapper .card, .page-content';
    var gridSelector = cfg.gridSelector ||
        '.product-miniature, .js-product-miniature, .products > .product, #products .product';
    var listSelector = cfg.listSelector || '#js-product-list, #products, .products';
    var hoverDelay = num(cfg.hoverDelay, 60);
    var skeletonOn = cfg.skeleton !== false;
    var skeletonDelay = num(cfg.skeletonDelay, 140);
    var barOn = cfg.bar !== false;
    var prefetchOn = cfg.prefetch !== false;
    var ttl = num(cfg.ttl, 60) * 1000;
    var motion = cfg.transition || 'fade';
    var motionMs = Math.max(0, Math.min(num(cfg.transitionMs, 260), 1200));
    var debug = cfg.debug === true;

    var denyPrefixes = lower(cfg.denyPrefixes);

    var DENY_PARAM = /(^|&)(add|delete|deleteproduct|deleteaddress|update|reorder|mylogout|logout|token|submitdelete|submitaddtocart|action|ajax|id_customization)(=|&|$)/i;
    var DENY_EXT = /\.(pdf|zips?|rar|7z|docx?|xlsx?|csv|jpe?g|png|gif|webp|avif|svg|ico|mp4|webm|mp3|css|js|json|xml|rss|ics)($|\?)/i;

    /* Pages fetched ahead of the visitor, as parsed documents. Small and
     * short-lived on purpose: this is a head start, not a cache. */
    var store = Object.create(null);
    var inFlight = Object.create(null);

    var busy = false;
    var hoverTimer = null;
    var skeletonTimer = null;

    function say(message) {
        if (!debug || !window.console || !window.console.info) { return; }
        window.console.info('[instant-nav] ' + message);
    }

    function lower(list) {
        return (list || []).map(function (p) { return String(p).toLowerCase(); });
    }

    function num(value, fallback) {
        var n = parseInt(value, 10);
        return isNaN(n) ? fallback : n;
    }

    function strip(url) {
        var i = String(url).indexOf('#');
        return i === -1 ? String(url) : String(url).slice(0, i);
    }

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    function idle(fn) {
        if (window.requestIdleCallback) {
            window.requestIdleCallback(fn, { timeout: 1500 });
        } else {
            setTimeout(fn, 1);
        }
    }

    /* ---------------------------------------------------------------- *
     *  What may be swapped
     * ---------------------------------------------------------------- */

    function usable() {
        return typeof window.fetch === 'function' &&
            typeof window.DOMParser === 'function' &&
            !!(window.history && window.history.pushState) &&
            !!document.querySelector(region);
    }

    /**
     * Same shop, ordinary page, nothing that changes state.
     *
     * The deny list arrives from PHP built out of the shop's own page links,
     * because the URLs are translated: on this shop the cart is /pl/koszyk,
     * and a list of English fragments would sail straight past it.
     */
    function safe(url) {
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

    var CONTROL = '[data-toggle],[data-target],.navbar-toggler,.collapse-icons,' +
        'button,input,select,textarea,summary';

    /** Whether the tap landed on something that is a control of its own. */
    function fromControl(node) {
        while (node && node !== document.body) {
            if (node.nodeType === 1) {
                var matches = node.matches || node.msMatchesSelector;
                if (matches && matches.call(node, CONTROL)) { return true; }
            }
            node = node.parentNode;
        }
        return false;
    }

    function anchorFrom(node) {
        while (node && node !== document.body) {
            if (node.tagName === 'A' && node.href) { return node; }
            node = node.parentNode;
        }
        return null;
    }

    /** The menu link a click landed on, or null when it was not one. */
    function linkFrom(event) {
        if (event.defaultPrevented || event.button !== 0) { return null; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return null; }
        if (!linkSelector) { return null; }

        /* The phone menu puts its expand/collapse control *inside* the
         * parent category's own link:
         *
         *   <a href="/pl/23-dieta">
         *     <span data-toggle="collapse" class="navbar-toggler">+</span>
         *     Dieta dr Dabrowskiej
         *   </a>
         *
         * so a tap on the little arrow bubbles up to the anchor. Taking that
         * over meant a sub-category could never be opened on a phone: every
         * attempt navigated to the parent instead. Anything that is a control
         * in its own right is left to the theme. */
        if (fromControl(event.target)) { return null; }

        var anchor = anchorFrom(event.target);
        if (!anchor) { return null; }
        if (anchor.target && anchor.target !== '_self') { return null; }
        if (anchor.hasAttribute('download') || anchor.hasAttribute('data-no-instant')) { return null; }
        if (anchor.getAttribute('data-link-action')) { return null; }

        var matches = anchor.matches || anchor.msMatchesSelector;
        if (!matches || !matches.call(anchor, linkSelector)) { return null; }

        var url = strip(anchor.href);
        if (url === here || !safe(url)) { return null; }

        return url;
    }

    /* ---------------------------------------------------------------- *
     *  Fetching ahead
     * ---------------------------------------------------------------- */

    function fresh(entry) {
        return entry && (Date.now() - entry.at) < ttl;
    }

    function load(url) {
        var held = store[url];
        if (fresh(held)) { return Promise.resolve(held.doc); }
        if (inFlight[url]) { return inFlight[url]; }

        var job = fetch(url, { credentials: 'same-origin', redirect: 'follow' })
            .then(function (response) {
                if (!response.ok || response.redirected) { return null; }

                var type = response.headers.get('content-type') || '';
                if (type.indexOf('text/html') === -1) { return null; }

                return response.text();
            })
            .then(function (html) {
                delete inFlight[url];
                if (!html) { return null; }

                var doc = new DOMParser().parseFromString(html, 'text/html');
                if (!doc || !doc.querySelector(region)) { return null; }

                store[url] = { doc: doc, at: Date.now() };
                return doc;
            })
            .catch(function (error) {
                delete inFlight[url];
                throw error;
            });

        inFlight[url] = job;
        return job;
    }

    function warm(url) {
        if (!prefetchOn || fresh(store[url]) || inFlight[url]) { return; }

        load(url).then(function (doc) {
            if (doc) { say('ready ahead of time: ' + url); }
        }).catch(function () { /* a head start that did not happen */ });
    }

    /* ---------------------------------------------------------------- *
     *  Borrowing the theme's surfaces
     *
     *  The little the placeholder is drawn with is measured off the shop
     *  itself: the page colour and the corner radius the theme uses. Hard-
     *  coded greys look right on exactly one theme and wrong on every other.
     * ---------------------------------------------------------------- */

    function rgb(value) {
        var m = String(value).match(/rgba?\(([^)]+)\)/);
        if (!m) { return null; }

        var parts = m[1].split(',').map(function (n) { return parseFloat(n); });
        if (parts.length < 3 || parts.some(isNaN)) { return null; }
        if (parts.length > 3 && parts[3] === 0) { return null; }

        return [parts[0], parts[1], parts[2]];
    }

    function css(c) {
        return 'rgb(' + c.map(function (n) {
            return Math.max(0, Math.min(255, Math.round(n)));
        }).join(',') + ')';
    }

    /** Toward black at a negative amount, toward white at a positive one. */
    function shade(c, amount) {
        var target = amount < 0 ? 0 : 255;
        var k = Math.abs(amount);
        return c.map(function (n) { return n + (target - n) * k; });
    }

    function backdrop(el) {
        while (el && el !== document.documentElement) {
            var found = rgb(getComputedStyle(el).backgroundColor);
            if (found) { return found; }
            el = el.parentNode;
        }
        return null;
    }

    function readTokens() {
        var host = document.querySelector(region) || document.body;
        var page = backdrop(host) || [246, 246, 246];

        var radius = '4px';
        var border = '0 none';

        var card = document.querySelector(cardSelector);
        if (card) {
            var style = getComputedStyle(card);
            if (style.borderRadius && style.borderRadius !== '0px') { radius = style.borderRadius; }
            if (parseFloat(style.borderTopWidth) > 0) {
                border = style.borderTopWidth + ' solid ' + style.borderTopColor;
            }
        }

        return {
            page: css(page),
            bar: css(shade(page, -0.04)),
            barHi: css(shade(page, 0.55)),
            radius: radius,
            border: border
        };
    }

    /* ---------------------------------------------------------------- *
     *  Learning each page's layout
     *
     *  A category has a filter column and a grid of cards. A CMS page is one
     *  column of prose. The contact page is a filter-width column of shop
     *  details beside a card of form rows. One placeholder cannot stand in
     *  for all three without being wrong twice.
     *
     *  So every page the visitor lands on is measured on the way past -- how
     *  wide its side column is, how far it sits from the content, how many
     *  cards fit across, how tall they are -- and kept for the next time that
     *  page is the destination. Until then PHP's guess from the URL is used,
     *  which is right about the shape even when it cannot know the widths.
     * ---------------------------------------------------------------- */

    /* However wide the real grid is, the placeholder shows one row of at
     * most this many. It is a hint that a page is coming, not an inventory
     * of what will be on it. */
    var MAX_BLOCKS = 3;

    var MEMORY = 'instantnav:layout:';

    function keyFor(url) {
        try {
            return MEMORY + new URL(url, window.location.href).pathname;
        } catch (e) {
            return null;
        }
    }

    function recall(url) {
        var key = keyFor(url);
        if (!key) { return null; }

        try {
            var raw = window.sessionStorage.getItem(key);
            return raw ? cleanLayout(JSON.parse(raw)) : null;
        } catch (e) {
            /* Private windows and blocked storage both land here. */
            return null;
        }
    }

    /**
     * A layout read back from storage is rebuilt from numbers within limits and a shape from a
     * fixed list, so nothing stored can reach the page as anything but a size.
     */
    function cleanLayout(raw) {
        if (!raw || typeof raw !== 'object') { return null; }
        var n = function (value, low, high, fallback) {
            var v = Number(value);
            return isFinite(v) ? Math.max(low, Math.min(high, v)) : fallback;
        };
        var shapes = { grid: 'grid', form: 'form', article: 'article' };
        var layout = {
            shape: shapes[raw.shape] || 'grid',
            side: null,
            columns: Math.round(n(raw.columns, 1, MAX_BLOCKS, 3)),
            card: n(raw.card, 0, 900, 300),
            crumb: n(raw.crumb, 0, 80, 0),
            title: n(raw.title, 0, 80, 0)
        };
        if (raw.rows !== undefined) { layout.rows = Math.round(n(raw.rows, 0, 8, 5)); }
        if (raw.side && typeof raw.side === 'object') {
            layout.side = {
                width: n(raw.side.width, 0, 100, 25),
                height: n(raw.side.height, 0, 4000, 320),
                gap: n(raw.side.gap, 0, 200, 30),
                measured: raw.side.measured === true
            };
        }
        return layout;
    }

    function keep(url, layout) {
        var key = keyFor(url);
        if (!key) { return; }

        try {
            window.sessionStorage.setItem(key, JSON.stringify(layout));
        } catch (e) { /* nothing depends on it */ }
    }

    /** The shape PHP guessed from the URL, before anything has been seen. */
    function hintFor(url) {
        var path;
        try {
            path = new URL(url, window.location.href).pathname;
            try { path = decodeURIComponent(path); } catch (e) { /* raw is fine */ }
            path = path.toLowerCase();
        } catch (e) {
            return 'grid';
        }

        var shapes = cfg.shapes || [];
        for (var i = 0; i < shapes.length; i++) {
            var prefix = String(shapes[i].prefix || '').toLowerCase();
            if (prefix && path.indexOf(prefix) === 0) { return shapes[i].shape || 'article'; }
        }

        return 'grid';
    }

    function sideColumn() {
        return document.querySelector(
            region + ' #left-column, ' + region + ' #right-column, ' + region + ' .sidebar');
    }

    function container() {
        return document.querySelector(region + ' .container') || document.querySelector(region);
    }

    /** Measure the page on screen, so the next loader for it lines up. */
    function measure() {
        var host = container();
        if (!host) { return null; }

        var tiles = toArray(document.querySelectorAll(region + ' ' + gridSelector));
        var form = document.querySelector(region + ' form');

        /* A category with nothing in it is still a category, so the list
         * container counts even when no tile matched. */
        var listing = tiles.length > 0 || !!document.querySelector(region + ' ' + listSelector);

        var shape = listing ? 'grid' : (form ? 'form' : 'article');

        var layout = { shape: shape, side: null, columns: 3, card: 300 };

        /* The two lines of text above the content. Without them the
         * placeholder starts where the page does not. */
        layout.crumb = lineHeight(region + ' .breadcrumb', 14);
        layout.title = lineHeight(region + ' h1, ' + region + ' .page-header h1', 30);

        var side = sideColumn();
        var main = document.querySelector(region + ' #content-wrapper') ||
            document.querySelector(region + ' #main');

        /* A page either has a side column or it does not, and that is what
         * decides the shape. Whether it could be *measured* is a separate
         * question: mid-layout, or on a narrow window where the column has
         * wrapped underneath, the numbers are not there to take. Recording
         * "no column" in that case was poisoning the memory -- one bad
         * reading and every later placeholder for that page lost its filter
         * block for good. A column that exists always yields a side; only
         * the numbers fall back. */
        if (side) {
            var sideBox = side.getBoundingClientRect();
            var tall = Math.round(sideBox.height);

            layout.side = {
                width: 25,
                gap: 30,
                height: (tall > 60 && tall < 1200) ? tall : 320,
                measured: false
            };

            if (main && sideBox.height > 0) {
                var mainBox = main.getBoundingClientRect();

                /* Only a genuine side-by-side layout gives real numbers. */
                if (mainBox.left >= sideBox.right && mainBox.top <= sideBox.bottom) {
                    /* The share is of the span the two columns occupy
                     * together, which is exactly what the placeholder's own
                     * row will be. Measuring against the container instead
                     * would count its padding, and the column would come out
                     * narrow by it. */
                    var span = mainBox.right - sideBox.left;

                    if (span > 0) {
                        layout.side.width = Math.round(sideBox.width / span * 10000) / 100;
                        layout.side.gap = Math.max(0, Math.round(mainBox.left - sideBox.right));
                        layout.side.measured = true;
                    }
                }
            }
        }

        if (tiles.length) {
            var top = tiles[0].getBoundingClientRect().top;
            var row = tiles.filter(function (t) {
                return Math.abs(t.getBoundingClientRect().top - top) < 2;
            });
            /* Clamped: a measurement of six across came from somewhere that
             * was not this grid, and a row of six is not a placeholder. */
            layout.columns = Math.max(1, Math.min(row.length, MAX_BLOCKS));

            var height = Math.round(row[0].getBoundingClientRect().height);
            if (height > 80 && height < 900) { layout.card = height; }
        }

        if (shape === 'form') {
            var rows = toArray(document.querySelectorAll(region + ' form .form-group, ' +
                region + ' form .row')).length;
            layout.rows = Math.max(3, Math.min(8, rows || 5));
        }

        return layout;
    }

    /** The height of a piece of text on the page, or 0 if it has none. */
    function lineHeight(selector, fallback) {
        var el = document.querySelector(selector);
        if (!el) { return 0; }

        var box = el.getBoundingClientRect();
        if (box.height <= 0) { return 0; }

        return Math.min(Math.round(box.height), 80) || fallback;
    }

    function remember() {
        var layout = measure();
        if (!layout) { return; }

        /* Never let a fallback reading overwrite real numbers taken earlier. */
        var known = recall(window.location.href);
        if (known && known.side && known.side.measured &&
            layout.side && !layout.side.measured) {
            layout.side = known.side;
        }

        keep(window.location.href, layout);
    }

    /**
     * What to draw for a destination: what was measured last time, or the
     * shape PHP guessed with sensible proportions taken from this page.
     */
    function layoutFor(url) {
        var known = recall(url);
        if (known && known.shape) { return known; }

        var here_ = measure() || {};
        var shape = hintFor(url);

        return {
            shape: shape,
            /* A category keeps its filter column; prose does not. Contact and
             * stores do, and it is the same width as the filter one. */
            side: shape === 'article'
                ? null
                : (here_.side || { width: 25, gap: 30, height: 320 }),
            columns: here_.columns || 3,
            card: here_.card || 300,
            crumb: here_.crumb === undefined ? 14 : here_.crumb,
            title: here_.title === undefined ? 30 : here_.title
        };
    }

    /* ---------------------------------------------------------------- *
     *  The placeholder
     * ---------------------------------------------------------------- */

    var STYLE_ID = 'instantnav-style';

    function injectStyle() {
        if (document.getElementById(STYLE_ID)) { return; }

        var css =
            '.in-skel{position:absolute;inset:0;z-index:50;padding:22px 0 40px;' +
            'overflow:hidden;background:var(--in-page)}' +
            '.in-skel__cols{display:flex;align-items:flex-start}' +
            '.in-skel__panel{width:100%}' +
            '.in-skel__crumb{width:190px;max-width:40%;margin:0 0 20px}' +
            '.in-skel__title{width:46%;max-width:380px;margin:0 0 22px}' +
            '.in-skel__main{flex:1 1 auto;min-width:0}' +
            '.in-skel__grid{display:grid;gap:24px;' +
            'grid-template-columns:repeat(var(--in-columns,3),minmax(0,1fr))}' +
            '.in-skel__block{background:var(--in-bar);border-radius:var(--in-radius);' +
            'border:var(--in-border)}' +
            '.in-skel__sheet{height:min(62vh,620px)}' +
            '@media(max-width:768px){.in-skel__cols{display:block}' +
            '.in-skel__side{display:none}' +
            '.in-skel__grid{grid-template-columns:repeat(2,minmax(0,1fr))}' +
            '.in-skel__card:nth-child(n+3){display:none}' +
            '.in-skel__card{height:220px!important}' +
            '.in-skel__sheet{height:50vh}}' +
            '.in-skel__block{background-image:linear-gradient(90deg,' +
            'var(--in-bar) 0%,var(--in-bar-hi) 50%,var(--in-bar) 100%);' +
            'background-size:200% 100%;animation:in-shimmer 1.4s ease-in-out infinite}' +
            '@keyframes in-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}' +
            '.in-bar{position:fixed;top:0;left:0;height:2px;width:0;z-index:2147483000;' +
            'background:var(--alh-green,#3f5c3a);transition:width .2s ease,opacity .3s ease;' +
            'pointer-events:none}' +
            '.in-bar--done{width:100%;opacity:0}' +
            '@media(prefers-reduced-motion:reduce){.in-skel__block' +
            '{animation:none;background-image:none}.in-bar{transition:none}}';

        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.appendChild(document.createTextNode(css));
        document.head.appendChild(style);
    }

    function piece(tag, className) {
        var el = document.createElement('div');
        el.className = tag + (className ? ' ' + className : '');
        return el;
    }

    /* The filter column, as one block the size of the real one. Leaving it
     * empty read as something that had failed to load rather than as
     * something on its way. */
    function spacer(layout) {
        if (!layout.side) { return null; }

        var el = piece('in-skel__side');
        el.style.flex = '0 0 ' + layout.side.width + '%';
        el.style.maxWidth = layout.side.width + '%';

        var block = piece('in-skel__block', 'in-skel__panel');
        block.style.height = (layout.side.height || 320) + 'px';
        el.appendChild(block);

        return el;
    }

    function cols(layout) {
        var el = piece('in-skel__cols');
        el.style.gap = (layout.side ? layout.side.gap : 30) + 'px';
        return el;
    }

    /** The breadcrumb, as one short bar, at the width of the page. */
    function crumb(inner, layout) {
        if (!layout.crumb) { return; }

        var bar = piece('in-skel__block', 'in-skel__crumb');
        bar.style.height = layout.crumb + 'px';
        inner.appendChild(bar);
    }

    /** The heading, as one bar about as long as a heading is. */
    function title(host, layout) {
        if (!layout.title) { return; }

        var bar = piece('in-skel__block', 'in-skel__title');
        bar.style.height = layout.title + 'px';
        host.appendChild(bar);
    }

    /**
     * A listing: plain blocks where the cards will be, and nothing inside
     * them. A placeholder that draws thumbnails, titles, prices and a filter
     * panel is a worse drawing of the page than the page itself, and it is
     * wrong the moment any of those move.
     */
    function buildListing(inner, layout) {
        crumb(inner, layout);

        var row = cols(layout);

        var gap = spacer(layout);
        if (gap) { row.appendChild(gap); }

        var main = piece('in-skel__main');
        title(main, layout);

        var grid = piece('in-skel__grid');

        var blocks = Math.min(layout.columns, MAX_BLOCKS);
        grid.style.setProperty('--in-columns', String(blocks));

        for (var i = 0; i < blocks; i++) {
            var card = piece('in-skel__block', 'in-skel__card');
            card.style.height = layout.card + 'px';
            grid.appendChild(card);
        }

        main.appendChild(grid);
        row.appendChild(main);
        inner.appendChild(row);
    }

    /** A page of prose: one block, the width of the page. */
    function buildArticle(inner, layout) {
        crumb(inner, layout);
        title(inner, layout);
        inner.appendChild(piece('in-skel__block', 'in-skel__sheet'));
    }

    /** Contact and stores: one block where the card is, the column left free. */
    function buildPanel(inner, layout) {
        crumb(inner, layout);

        var row = cols(layout);

        var gap = spacer(layout);
        if (gap) { row.appendChild(gap); }

        var main = piece('in-skel__main');
        title(main, layout);
        main.appendChild(piece('in-skel__block', 'in-skel__sheet'));
        row.appendChild(main);
        inner.appendChild(row);
    }

    function showSkeleton(url) {
        var host = document.querySelector(region);
        if (!host || document.querySelector('.in-skel')) { return; }

        injectStyle();

        if (getComputedStyle(host).position === 'static') {
            host.style.position = 'relative';
        }

        var tokens = readTokens();
        var layout = layoutFor(url || window.location.href);

        var skeleton = piece('in-skel');
        skeleton.setAttribute('role', 'status');
        skeleton.setAttribute('aria-live', 'polite');
        skeleton.setAttribute('aria-label', cfg.loadingLabel || 'Ładowanie');

        skeleton.style.setProperty('--in-page', tokens.page);
        skeleton.style.setProperty('--in-bar', tokens.bar);
        skeleton.style.setProperty('--in-bar-hi', tokens.barHi);
        skeleton.style.setProperty('--in-radius', tokens.radius);
        skeleton.style.setProperty('--in-border', tokens.border);

        /* The theme's own container, so the placeholder is the same width and
         * in the same place as the content it stands in for. */
        var inner = document.createElement('div');
        inner.className = 'container in-skel__inner';

        if (layout.shape === 'article') {
            buildArticle(inner, layout);
        } else if (layout.shape === 'form') {
            buildPanel(inner, layout);
        } else {
            buildListing(inner, layout);
        }

        skeleton.appendChild(inner);
        host.appendChild(skeleton);
        say('placeholder shown as ' + layout.shape +
            (recall(url) ? ' (measured)' : ' (guessed from the URL)'));
    }

    function hideSkeleton() {
        clearTimeout(skeletonTimer);
        var existing = document.querySelector('.in-skel');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
    }

    function armSkeleton(url) {
        if (!skeletonOn) { return; }
        clearTimeout(skeletonTimer);
        skeletonTimer = setTimeout(function () { showSkeleton(url); }, skeletonDelay);
    }

    /* ---------------------------------------------------------------- *
     *  The progress line
     * ---------------------------------------------------------------- */

    var bar = null;

    function startBar() {
        if (!barOn) { return; }

        injectStyle();
        stopBar(true);

        bar = piece('in-bar');
        document.body.appendChild(bar);

        /* Two frames: the element has to be laid out at zero width before the
         * transition to 70% means anything. */
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                if (bar) { bar.style.width = '70%'; }
            });
        });
    }

    function stopBar(immediate) {
        if (!bar) { return; }

        var done = bar;
        bar = null;

        if (immediate) {
            if (done.parentNode) { done.parentNode.removeChild(done); }
            return;
        }

        done.className = 'in-bar in-bar--done';
        setTimeout(function () {
            if (done.parentNode) { done.parentNode.removeChild(done); }
        }, 400);
    }

    /* ---------------------------------------------------------------- *
     *  The transition
     *
     *  Where the browser has the View Transitions API, it takes a snapshot
     *  of the region before and after the swap and animates between them on
     *  the compositor -- which is the only way to get this genuinely smooth,
     *  since the alternative is animating a repaint of the whole listing.
     *  Only the swapped region is given a transition name, so the header is
     *  never captured and never moves.
     *
     *  Everywhere else falls back to fading the region out and in by hand.
     *  That costs one extra frame either side and looks close enough.
     *
     *  Someone who has asked their system for less motion gets none of it:
     *  the swap happens in one frame, which is what they asked for.
     * ---------------------------------------------------------------- */

    var MOTION_STYLE = 'instantnav-motion';
    var REGION_NAME = 'instantnav-region';

    function motionWanted() {
        if (motion === 'off' || motionMs === 0) { return false; }

        try {
            if (window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return false;
            }
        } catch (e) { /* no matchMedia; assume motion is fine */ }

        return true;
    }

    function injectMotionStyle() {
        if (document.getElementById(MOTION_STYLE)) { return; }

        var ms = motionMs + 'ms';
        var ease = 'cubic-bezier(.22,.61,.36,1)';

        var css =
            /* The old snapshot leaves first and a little faster, so the two
             * never both sit at half opacity, which is what reads as muddy. */
            '::view-transition-old(' + REGION_NAME + '){' +
            'animation:in-out ' + Math.round(motionMs * 0.6) + 'ms ' + ease + ' both}' +
            '::view-transition-new(' + REGION_NAME + '){' +
            'animation:in-in ' + ms + ' ' + ease + ' both}' +
            '@keyframes in-out{to{opacity:0}}' +
            '@keyframes in-in{from{opacity:0}to{opacity:1}}' +

            '[data-in-motion="slide"] ::view-transition-new(' + REGION_NAME + ')' +
            '{animation:in-in-slide ' + ms + ' ' + ease + ' both}' +
            '@keyframes in-in-slide{from{opacity:0;transform:translateY(12px)}' +
            'to{opacity:1;transform:none}}' +

            '[data-in-motion="scale"] ::view-transition-new(' + REGION_NAME + ')' +
            '{animation:in-in-scale ' + ms + ' ' + ease + ' both}' +
            '@keyframes in-in-scale{from{opacity:0;transform:scale(.985)}' +
            'to{opacity:1;transform:none}}' +

            /* The hand-rolled fallback. */
            '.in-fading{transition:opacity ' + Math.round(motionMs * 0.45) + 'ms ' + ease + '}' +
            '.in-fading.in-out{opacity:0}' +

            '@media(prefers-reduced-motion:reduce){' +
            '::view-transition-old(' + REGION_NAME + '),' +
            '::view-transition-new(' + REGION_NAME + '){animation:none}' +
            '.in-fading{transition:none}}';

        var style = document.createElement('style');
        style.id = MOTION_STYLE;
        style.appendChild(document.createTextNode(css));
        document.head.appendChild(style);
    }

    /** Fade the region out, swap, fade it back -- for browsers without the API. */
    function fadeThrough(host, mutate) {
        return new Promise(function (done) {
            var out = Math.round(motionMs * 0.45);

            host.classList.add('in-fading', 'in-out');

            setTimeout(function () {
                try {
                    mutate();
                } finally {
                    host.classList.remove('in-out');

                    setTimeout(function () {
                        host.classList.remove('in-fading');
                        done();
                    }, out);
                }
            }, out);
        });
    }

    /**
     * Apply a DOM change with whatever smoothing this browser can manage.
     * The change itself always happens; only how it is shown varies.
     */
    function commit(mutate) {
        var host = document.querySelector(region);

        if (!host || !motionWanted()) {
            mutate();
            return Promise.resolve();
        }

        injectMotionStyle();
        document.documentElement.setAttribute('data-in-motion', motion);

        if (typeof document.startViewTransition === 'function') {
            host.style.viewTransitionName = REGION_NAME;

            var run;
            try {
                run = document.startViewTransition(mutate);
            } catch (e) {
                /* Some builds throw when one is already running. */
                host.style.viewTransitionName = '';
                mutate();
                return Promise.resolve();
            }

            return run.finished.catch(function () {
                /* A transition that was skipped or interrupted is not an
                 * error worth surfacing: the DOM changed either way. */
            }).then(function () {
                host.style.viewTransitionName = '';
            });
        }

        return fadeThrough(host, mutate);
    }

    /* ---------------------------------------------------------------- *
     *  Putting the new page in
     * ---------------------------------------------------------------- */

    /** The URL as an absolute same-site http(s) address, or null. */
    function sameSite(url) {
        var parsed;
        try {
            parsed = new URL(url, window.location.href);
        } catch (e) {
            return null;
        }
        if (parsed.origin !== ORIGIN || (parsed.protocol !== 'http:' && parsed.protocol !== 'https:')) { return null; }

        return parsed.href;
    }

    /** The ordinary navigation, to this shop only. */
    function leave(url) {
        var target = sameSite(url);
        if (target) { window.location.assign(target); }
    }

    /** A script the browser would run, as opposed to a data block (JSON-LD, a template). */
    function runnable(script) {
        var type = (script.getAttribute('type') || '').toLowerCase();
        return !type || /(java|ecma)script|^module$/.test(type);
    }

    /* Elements that run, load or redirect on their own once in the page (a <meta> only with
     * http-equiv: PrestaShop's listings are full of harmless <meta itemprop> microdata). SVG
     * <set> and <animate> can rewrite a link's address after insertion. */
    var ACTIVE = /^(iframe|frame|frameset|object|embed|applet|base|portal|fencedframe|set|animate)$/i;
    /* Attributes that hold an address the browser may follow or load. */
    var URL_ATTR = /^(href|src|action|formaction|data|poster|background|xlink:href|ping)$/i;
    var BAD_URL = /^(javascript:|vbscript:|data:text\/html|data:image\/svg)/i;

    /** An attribute that would run code: an event handler, srcdoc, or a script address. */
    function activeAttribute(attr) {
        var name = attr.name.toLowerCase();
        if (name.indexOf('on') === 0 || name === 'srcdoc') { return true; }
        /* browsers ignore control characters and spaces in a scheme ("java\tscript:") */
        return URL_ATTR.test(name) && BAD_URL.test(String(attr.value).replace(/[\u0000- ]/g, ''));
    }

    /** An element that is active in itself, whatever its attributes. */
    function activeTag(el) {
        var tag = el.tagName.toLowerCase();
        if (tag === 'script') { return runnable(el); }
        if (tag === 'meta') { return el.hasAttribute('http-equiv'); }
        return ACTIVE.test(tag);
    }

    function activeElement(el) {
        return activeTag(el) || toArray(el.attributes).some(activeAttribute);
    }

    /**
     * Whether the new content brings anything that would run: scripts, event-handler attributes,
     * javascript: addresses, frames and plug-ins. Those pages load normally; nothing active from
     * a fetched page is ever put into this one. Data blocks (JSON-LD, templates) are fine, they
     * travel as inert markup.
     */
    function bringsScripts(doc) {
        return toArray(doc.querySelectorAll(region + ', ' + region + ' *')).some(activeElement);
    }

    /**
     * A copy of a node from the fetched page that cannot run anything: active elements are left
     * out and active attributes dropped. bringsScripts() already sends such pages to a normal
     * load; this is the second lock, so even content that slipped past it is inserted inert.
     */
    function inertCopy(node) {
        var copy = document.importNode(node, true);
        if (copy.nodeType === 3 || copy.nodeType === 8) { return copy; }   // text, comments
        if (copy.nodeType !== 1 || activeTag(copy)) { return null; }
        [copy].concat(toArray(copy.querySelectorAll('*'))).forEach(function (el) {
            if (el !== copy && activeTag(el)) {
                if (el.parentNode) { el.parentNode.removeChild(el); }
                return;
            }
            toArray(el.attributes).forEach(function (attr) {
                if (activeAttribute(attr)) { el.removeAttribute(attr.name); }
            });
        });
        return copy;
    }

    /**
     * The menu still shows the old page as the current one, because the
     * header is deliberately not swapped. Move the marker instead.
     */
    function markCurrent(url) {
        if (!linkSelector) { return; }

        var path;
        try {
            path = new URL(url, window.location.href).pathname;
        } catch (e) {
            return;
        }

        toArray(document.querySelectorAll(linkSelector)).forEach(function (anchor) {
            var owner = anchor.closest ? anchor.closest('li') : null;
            if (!owner) { return; }

            var mine;
            try {
                mine = new URL(anchor.href, window.location.href).pathname === path;
            } catch (e) {
                mine = false;
            }

            if (mine) {
                owner.classList.add('current');
            } else {
                owner.classList.remove('current');
            }
        });
    }

    /**
     * Close the phone menu the way the theme's own menu button would.
     *
     * Opening it, Classic (and themes built on it) hides the page itself:
     *
     *     $('#notifications, #wrapper, #footer').hide();
     *
     * and shows it again only from that button. Hiding just the menu panel
     * left the swapped-in content inside a hidden #wrapper: a blank page
     * until a reload. So the page comes back with the panel.
     */
    function closeMobileMenu() {
        var panel = document.getElementById('mobile_top_menu_wrapper');
        var header = document.getElementById('header');
        var open = (panel && panel.style.display !== 'none' && panel.offsetHeight > 0) ||
            (header && header.classList.contains('is-open'));
        if (!open) { return; }

        if (panel) { panel.style.display = 'none'; }
        if (header) { header.classList.remove('is-open'); }

        ['notifications', 'wrapper', 'footer'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.style.display === 'none') { el.style.display = ''; }
        });
        var host = document.querySelector(region);
        if (host && host.style.display === 'none') { host.style.display = ''; }
    }

    /**
     * Say the page changed, without pretending to be a facet update.
     *
     * This used to emit PrestaShop's own 'updatedProductList'. That event
     * carries the re-rendered listing with it, and the theme's handler does
     *
     *     $('#js-product-list-top').replaceWith(data.rendered_products_top)
     *
     * for each part. Emitted with an empty payload, every one of those became
     * replaceWith(undefined) -- which is jQuery for "delete this element".
     * The sort row, the facets and the listing were removed from the page
     * immediately after being swapped in.
     *
     * Nothing here carries a payload, so nothing can be replaced by one that
     * is not there.
     */
    function announce(url) {
        try {
            document.dispatchEvent(new CustomEvent('instantnav:loaded', { detail: { url: url } }));
        } catch (e) { /* older browsers; nothing depends on it */ }

        /* The one event PrestaShop fires with no payload of its own, which
         * modules use to re-bind after the DOM moves under them. */
        try {
            if (window.prestashop && typeof window.prestashop.emit === 'function') {
                window.prestashop.emit('updatedProduct', { reason: 'instantnav' });
            }
        } catch (e) {
            say('a theme listener threw: ' + e.message);
        }
    }

    function apply(doc, url) {
        var incoming = doc.querySelector(region);
        var host = document.querySelector(region);
        if (!incoming || !host) { return false; }

        document.title = doc.title || document.title;

        if (doc.body) {
            /* PrestaShop hangs page identity off the body and the theme's CSS
             * keys off it, so it has to travel with the content. */
            document.body.className = doc.body.className;
            if (doc.body.id) { document.body.id = doc.body.id; }
        }

        /* Inert copies of the parsed page's nodes, never markup re-parsed as HTML. */
        var content = document.createDocumentFragment();
        toArray(incoming.childNodes).forEach(function (node) {
            var safe = inertCopy(node);
            if (safe) { content.appendChild(safe); }
        });
        while (host.firstChild) { host.removeChild(host.firstChild); }
        host.appendChild(content);

        try {
            if (window.prestashop && window.prestashop.urls) {
                window.prestashop.urls.current_url = url;
            }
            if (window.prestashop && window.prestashop.page && doc.body && doc.body.id) {
                window.prestashop.page.page_name = doc.body.id;
            }
        } catch (e) { /* the shop's own globals; never fatal */ }

        return true;
    }

    /** Someone reading with a screen reader gets told where they landed. */
    function settle() {
        var heading = document.querySelector(region + ' h1');
        if (!heading) { return; }

        if (!heading.hasAttribute('tabindex')) {
            heading.setAttribute('tabindex', '-1');
        }
        try {
            heading.focus({ preventScroll: true });
        } catch (e) {
            heading.focus();
        }
    }

    /**
     * The page must show after a swap. A theme or a module may still hide it: Classic's phone
     * menu hides #wrapper and #footer while it is open, other menus lock the body, a transition
     * cut short can leave the region faded out. Checked when the swap is done and once more a
     * moment later (for scripts that react to it): what hides it is undone, and if the content
     * still does not show, the page loads normally, so it is never left blank.
     */
    function ensureShown(url) {
        ['notifications', 'wrapper', 'footer'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.style.display === 'none') { el.style.display = ''; }
        });
        var host = document.querySelector(region);
        if (!host) { return; }
        host.classList.remove('in-fading', 'in-out');
        if (host.style.display === 'none') { host.style.display = ''; }
        if (host.style.opacity === '0') { host.style.opacity = ''; }
        var cs = window.getComputedStyle(host);
        var r = host.getBoundingClientRect();
        var hidden = cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.05 || r.height < 24;
        var hiddenUp = false;
        for (var el = host.parentElement; el && el !== document.documentElement; el = el.parentElement) {
            var ps = window.getComputedStyle(el);
            if (ps.display === 'none' || parseFloat(ps.opacity) < 0.05) { hiddenUp = true; break; }
        }
        if (hidden || hiddenUp) {
            say('the swapped page did not show (' + (hidden ? 'region' : 'a parent') + ' hidden), loading it normally');
            window.location.reload();
        }
    }

    function land(doc, url, push) {
        hideSkeleton();

        /* Checked before the transition starts, not inside it. commit()
         * runs its callback a frame or more later, so a result read back
         * from in there would always be the value it started with. */
        if (!doc.querySelector(region) || !document.querySelector(region) || bringsScripts(doc)) {
            say('loading ' + url + ' normally (its content runs scripts, or has no region to swap)');
            leave(url);
            return;
        }

        /* Scrolling belongs inside the transition, not after it: moved
         * afterwards it reads as the page jumping once it has settled. */
        /* Told once the new content is in: with a transition the swap
         * happens a frame or more later, and listeners read the page. */
        commit(function () {
            apply(doc, url);
            window.scrollTo(0, 0);
            /* The new content is in and paints with the next frame (the
             * transition, if any, has only begun): the moment a visitor
             * sees the page, which the speed audit times. */
            try {
                document.dispatchEvent(new CustomEvent('instantnav:swapped', { detail: { url: url } }));
            } catch (e) { /* older browsers */ }
        }).then(function () {
            settle();
            announce(url);
            ensureShown(url);
            setTimeout(function () { if (strip(window.location.href) === strip(url)) { ensureShown(url); } }, 700);
        });

        var target = sameSite(url);
        if (push && target) {
            window.history.pushState({ instantNav: true }, '', target);
        }
        here = strip(window.location.href);

        markCurrent(url);
        closeMobileMenu();
        idle(remember);
        stopBar();

        say('swapped in ' + url + ' without reloading' +
            (motionWanted() ? ' (' + motion + ')' : ''));
    }

    function go(url, push) {
        if (busy) { return; }
        busy = true;

        var cached = fresh(store[url]);
        /* At the tap, so the page (and its placeholder) shows while loading. */
        closeMobileMenu();
        armSkeleton(url);
        startBar();

        load(url).then(function (doc) {
            busy = false;

            if (!doc) {
                hideSkeleton();
                stopBar(true);
                leave(url);
                return;
            }

            try {
                land(doc, url, push);
                if (cached) { say('served from the head start'); }
            } catch (e) {
                /* Half a swap is not a state to leave anyone in. */
                say('swap failed, loading normally: ' + e.message);
                leave(url);
            }
        }).catch(function (error) {
            busy = false;
            hideSkeleton();
            stopBar(true);
            say('could not read ' + url + ' (' + error.message + '), loading normally');
            leave(url);
        });
    }

    /* ---------------------------------------------------------------- *
     *  Listening
     * ---------------------------------------------------------------- */

    function onIntent(event) {
        var anchor = anchorFrom(event.target);
        if (!anchor || !linkSelector) { return; }

        var matches = anchor.matches || anchor.msMatchesSelector;
        if (!matches || !matches.call(anchor, linkSelector)) { return; }

        var url = strip(anchor.href);
        if (url === here || !safe(url)) { return; }

        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () { warm(url); }, hoverDelay);
    }

    function onLeave() {
        clearTimeout(hoverTimer);
    }

    function onClick(event) {
        var url = linkFrom(event);
        if (!url || !usable()) { return; }

        event.preventDefault();
        go(url, true);
    }

    function onPop(event) {
        if (!event.state || !event.state.instantNav) { return; }

        var url = strip(window.location.href);
        if (!usable()) { window.location.reload(); return; }

        busy = false;
        go(url, false);
    }

    function bind() {
        var passive = supportsPassive() ? { passive: true } : false;

        document.addEventListener('click', onClick, true);
        document.addEventListener('mouseover', onIntent, passive);
        document.addEventListener('mouseout', onLeave, passive);
        document.addEventListener('focusin', onIntent, passive);
        document.addEventListener('touchstart', onIntent, passive);

        window.addEventListener('popstate', onPop);
        window.addEventListener('pageshow', hideSkeleton);
        window.addEventListener('pagehide', hideSkeleton);

        /* So the first Back out of a swapped page has somewhere to return to
         * rather than leaving the shop. */
        try {
            window.history.replaceState({ instantNav: true }, '', window.location.href);
        } catch (e) { /* nothing depends on it */ }
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

    function start() {
        window.instantNav = {
            build: BUILD,
            config: cfg,
            motion: function () {
                return {
                    style: motion,
                    ms: motionMs,
                    wanted: motionWanted(),
                    native: typeof document.startViewTransition === 'function'
                };
            },
            go: function (url) { go(strip(url), true); },
            ready: function () { return Object.keys(store); },
            showSkeleton: showSkeleton,
            hideSkeleton: hideSkeleton,
            tokens: readTokens,
            layout: layoutFor,
            measure: measure
        };

        if (!linkSelector) {
            say('no links configured, standing down');
            return;
        }

        if (!usable()) {
            say('this browser or page cannot swap, links will load normally');
            return;
        }

        document.documentElement.setAttribute('data-instantnav', BUILD);
        bind();
        remember();

        say('active (' + BUILD + ') — menu links swap in place' +
            (motionWanted()
                ? ', ' + motion + ' ' + motionMs + 'ms' +
                  (typeof document.startViewTransition === 'function'
                      ? ' (view transitions)' : ' (fallback fade)')
                : '') +
            (prefetchOn ? ', fetched on hover' : '') +
            (skeletonOn ? ', placeholder after ' + skeletonDelay + 'ms' : '') +
            '. window.instantNav.ready() lists what is waiting.');
    }

    if (document.readyState === 'complete') {
        idle(start);
    } else {
        window.addEventListener('load', function () { idle(start); }, { once: true });
    }
}());
