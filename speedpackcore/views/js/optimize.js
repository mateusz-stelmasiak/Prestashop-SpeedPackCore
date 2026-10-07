/**
 * SpeedPack Core - Optimize settings: picture copies in steps, and critical CSS made in this browser.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * Critical CSS: each kind of page (home, category, product, CMS) is opened in a hidden frame of
 * this page, at computer width and at phone width. Every rule of the page's stylesheets is kept
 * when an element it matches sits in the first screen (a hidden element counts where its parent
 * is, so the rules that hide things stay too); @media blocks are judged at the width they apply
 * to. The two widths are merged in the stylesheets' own order, addresses in url() made absolute,
 * and the result is saved with the list of stylesheets it was made from (classes/SpcHtml.php
 * uses it only while the page has those same stylesheets).
 */
(function () {
    'use strict';

    var WIDTHS = [[1366, 900], [390, 800]];
    // what goes inline at most: past it, the rules for what sits lowest on the first screen go first
    var BUDGET = 42000;

    function fmt(s) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(s).replace(/%(\d\$)?[sd]/g, function (m, pos) {
            var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
            return v === undefined ? m : v;
        });
    }

    function size(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' kB';
    }

    function post(url, data) {
        var body = new FormData();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        });
    }

    /* ---------------------------------------------------------------- *
     *  Pictures
     * ---------------------------------------------------------------- */

    function convert(box, t) {
        var btn = box.querySelector('[data-spc-convert]');
        var state = box.querySelector('[data-spc-convert-state]');
        var bar = box.querySelector('[data-spc-convert-bar]');
        var files = 0;
        var saved = 0;
        btn.disabled = true;
        bar.hidden = false;
        function step(offset) {
            return post(box.getAttribute('data-url'), { op: 'images', offset: offset }).then(function (a) {
                if (a.error) { throw new Error(a.error); }
                files += a.files;
                saved += a.saved;
                bar.firstChild.style.width = Math.min(100, Math.round(100 * a.offset / Math.max(1, a.total))) + '%';
                state.textContent = fmt(t.converting, Math.min(a.offset, a.total), a.total);
                return a.done ? null : step(a.offset);
            });
        }
        step(0).then(function () {
            state.textContent = files ? fmt(t.converted, files, size(saved)) : t.none;
        }).catch(function (e) {
            state.textContent = fmt(t.failed, e.message);
        }).then(function () {
            btn.disabled = false;
        });
    }

    /* ---------------------------------------------------------------- *
     *  Critical CSS
     * ---------------------------------------------------------------- */

    /** A selector the page can be asked about: hover, focus and pseudo-elements left out. */
    /** a selector that only applies on interaction: not needed for the first paint */
    var INTERACTIVE = /:(hover|focus-within|focus-visible|focus|active|visited|target|checked)\b/i;

    /** The selector as querySelectorAll can ask it: pseudo-elements and states taken off. */
    function askable(selector) {
        var s = selector
            .replace(/::?(before|after|first-line|first-letter|placeholder|selection|marker|backdrop|file-selector-button|-webkit-[\w-]+|-moz-[\w-]+|-ms-[\w-]+)(\([^)]*\))?/gi, '')
            .trim();
        return s && !/[>+~,]\s*$/.test(s) ? s : '';
    }

    /** the declarations that keep an element hidden or closed */
    var HIDING = /^(display|visibility|opacity|position|top|left|right|bottom|height|max-height|width|max-width|overflow|overflow-x|overflow-y|clip|clip-path|transform|z-index)$/;

    /**
     * Where a selector's elements are on the first screen: top = how high the first shown one sits
     * (px, -1 when none shows there), hidden = whether one that is hidden (a closed menu, a select
     * for phones, a collapsed list) sits inside something shown there. A hidden one needs only the
     * declarations that hide it, or it would show until the full stylesheet arrives.
     */
    function firstScreen(doc, selector, height) {
        var out = { top: -1, hidden: false };
        if (INTERACTIVE.test(selector)) { return out; }
        var s = askable(selector);
        if (!s) { return out; }
        var list;
        try { list = doc.querySelectorAll(s); } catch (e) { return out; }
        for (var i = 0; i < list.length && i < 400; i++) {
            var el = list[i];
            if (!el.getClientRects().length) {
                if (!out.hidden) {
                    var up = el.parentElement;
                    while (up && !up.getClientRects().length) { up = up.parentElement; }
                    if (up && up.getBoundingClientRect().top < height) { out.hidden = true; }
                }
                continue;
            }
            var r = el.getBoundingClientRect();
            if (r.top < height && r.bottom > -1) {
                var top = Math.max(0, r.top);
                if (out.top < 0 || top < out.top) { out.top = top; }
                if (out.top === 0 && out.hidden) { break; }
            }
        }
        return out;
    }

    /** Only the declarations of a rule that hide or size an element. */
    function hidingPart(style) {
        var out = '';
        for (var i = 0; i < style.length; i++) {
            var prop = style[i];
            if (HIDING.test(prop)) {
                out += (out ? ' ' : '') + prop + ': ' + style.getPropertyValue(prop) + (style.getPropertyPriority(prop) ? ' !important' : '') + ';';
            }
        }
        return out;
    }

    /** url(...) relative to the stylesheet, made absolute (the CSS moves into the page). */
    function absolute(css, base) {
        if (!base) { return css; }
        return css.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, function (m, q, u) {
            if (/^(data:|https?:|\/\/|#)/i.test(u)) { return m; }
            try { return 'url("' + new URL(u, base).href + '")'; } catch (e) { return m; }
        });
    }

    /**
     * The rules of one stylesheet that the first screen needs, into keep: path -> {order, wraps, text}.
     */
    function walk(rules, path, wraps, ctx, keep) {
        for (var i = 0; i < rules.length; i++) {
            var r = rules[i];
            var p = path.concat([i]);
            var key = p.join('.');
            if (r.type === 1) { // style rule: only the selectors the first screen shows
                var parts = splitSelectors(r.selectorText);
                var used = [];
                var top = -1;
                parts.forEach(function (sel) {
                    var f = firstScreen(ctx.doc, sel, ctx.height);
                    if (f.top >= 0 || f.hidden) {
                        used.push(sel);
                        if (f.top >= 0) { top = top < 0 ? f.top : Math.min(top, f.top); }
                    }
                });
                if (used.length) {
                    // shown elements need the whole rule; hidden ones only what keeps them hidden
                    var full = top >= 0;
                    var body = full ? r.style.cssText : hidingPart(r.style);
                    if (!full) { top = ctx.height; }
                    var prev = keep[key];
                    // the same rule from another page width: the selectors of both
                    if (prev) {
                        prev.sels = prev.sels.concat(used.filter(function (x) { return prev.sels.indexOf(x) < 0; }));
                        prev.top = Math.min(prev.top, top);
                        if (full && !prev.full) { prev.full = true; prev.body = absolute(body, ctx.base); }
                        prev.text = prev.sels.join(',') + '{' + prev.body + '}';
                    } else if (body) {
                        keep[key] = { order: p, wraps: wraps, sels: used, body: absolute(body, ctx.base), top: top, full: full, text: '' };
                        keep[key].text = used.join(',') + '{' + keep[key].body + '}';
                    }
                }
            } else if (r.type === 4) { // @media
                if (ctx.win.matchMedia(r.media.mediaText).matches) {
                    walk(r.cssRules, p, wraps.concat(['@media ' + r.media.mediaText]), ctx, keep);
                }
            } else if (r.type === 12) { // @supports
                walk(r.cssRules, p, wraps.concat(['@supports ' + r.conditionText]), ctx, keep);
            } else if (r.type === 5) { // @font-face: kept only if a kept rule uses its family
                keep[key] = { order: p, wraps: wraps, text: absolute(r.cssText, ctx.base), top: 0, font: String(r.style.getPropertyValue('font-family')).replace(/["']/g, '').trim().toLowerCase() };
            } else if (r.type === 3 && r.styleSheet) { // @import
                try { walk(r.styleSheet.cssRules, p, wraps, { doc: ctx.doc, win: ctx.win, height: ctx.height, base: r.styleSheet.href || ctx.base }, keep); } catch (e) { /* another origin */ }
            } else if (r.cssRules && /^@layer\b/.test(r.cssText)) {
                walk(r.cssRules, p, wraps.concat(['@layer ' + (r.name || '')]), ctx, keep);
            }
        }
    }

    /** "a, b:not(.x, .y), c" into its selectors, commas inside brackets kept. */
    function splitSelectors(text) {
        var out = [], depth = 0, cur = '';
        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            if (ch === '(' || ch === '[') { depth++; } else if (ch === ')' || ch === ']') { depth--; }
            if (ch === ',' && depth === 0) { out.push(cur.trim()); cur = ''; } else { cur += ch; }
        }
        if (cur.trim()) { out.push(cur.trim()); }
        return out;
    }

    /** The stylesheets PrestaShop puts in the head (the ones the page will load later). */
    function headSheets(doc) {
        var out = [];
        Array.prototype.forEach.call(doc.querySelectorAll('head link[rel~="stylesheet"]'), function (l) {
            if (/print/i.test(l.getAttribute('media') || '')) { return; }
            out.push(l);
        });
        return out;
    }

    function read(frame, url, width, height, keep) {
        return new Promise(function (resolve, reject) {
            var f = document.createElement('iframe');
            f.width = width;
            f.height = height;
            f.style.width = width + 'px';
            f.style.height = height + 'px';
            var timer = setTimeout(function () { finish(new Error('timeout')); }, 45000);
            function finish(err, hrefs) {
                clearTimeout(timer);
                if (f.parentNode) { f.parentNode.removeChild(f); }
                if (err) { reject(err); } else { resolve(hrefs); }
            }
            f.onload = function () {
                var doc;
                try { doc = f.contentDocument; doc.querySelector('head'); } catch (e) { finish(new Error('origin')); return; }
                if (!doc) { finish(new Error('origin')); return; }
                var ready = doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve();
                ready.then(function () {
                    setTimeout(function () {
                        try {
                            f.contentWindow.scrollTo(0, 0);
                            var links = headSheets(doc);
                            links.forEach(function (link, n) {
                                var sheet = link.sheet;
                                if (!sheet) { return; }
                                var rules;
                                try { rules = sheet.cssRules; } catch (e) { return; } // another origin: loads as it is
                                walk(rules, [n], [], { doc: doc, win: f.contentWindow, height: height, base: sheet.href }, keep);
                            });
                            finish(null, links.map(function (l) { return l.getAttribute('href'); }));
                        } catch (e) {
                            finish(e);
                        }
                    }, 700);
                });
            };
            f.src = url;
            frame.appendChild(f);
        });
    }

    /** The kept rules in the stylesheets' order, each run of the same @media opened once. */
    function stitch(keep, budget) {
        var all = Object.keys(keep).map(function (k) { return keep[k]; });
        var rules = all.filter(function (x) { return !x.font; });
        var families = rules.map(function (x) { return x.text; }).join(' ').toLowerCase();
        // fonts: only the families the kept rules name
        var list = rules.concat(all.filter(function (x) { return x.font && families.indexOf(x.font) >= 0; }));
        var total = list.reduce(function (n, x) { return n + x.text.length; }, 0);
        if (budget && total > budget) {
            // over the budget: what sits lowest on the first screen waits for the full stylesheet
            var byTop = list.slice().sort(function (a, b) { return b.top - a.top; });
            var cut = {};
            for (var i = 0; i < byTop.length && total > budget; i++) {
                cut[byTop[i].order.join('.')] = true;
                total -= byTop[i].text.length;
            }
            list = list.filter(function (x) { return !cut[x.order.join('.')]; });
        }
        list.sort(function (a, b) {
            for (var i = 0; i < Math.max(a.order.length, b.order.length); i++) {
                var x = a.order[i] === undefined ? -1 : a.order[i];
                var y = b.order[i] === undefined ? -1 : b.order[i];
                if (x !== y) { return x - y; }
            }
            return 0;
        });
        var out = '';
        var open = [];
        list.forEach(function (item) {
            var same = 0;
            while (same < open.length && same < item.wraps.length && open[same] === item.wraps[same]) { same++; }
            while (open.length > same) { out += '}'; open.pop(); }
            while (open.length < item.wraps.length) { out += item.wraps[open.length] + '{'; open.push(item.wraps[open.length]); }
            out += item.text.replace(/\s*\n\s*/g, ' ');
        });
        while (open.length) { out += '}'; open.pop(); }
        return out;
    }

    function critical(box, t) {
        var btn = box.querySelector('[data-spc-critical]');
        var state = box.querySelector('[data-spc-critical-state]');
        var frame = box.querySelector('[data-spc-frame]');
        // laid out wherever the settings tab is: a frame inside a hidden tab is never laid out
        if (frame && frame.parentNode !== document.body) { document.body.appendChild(frame); }
        var url = box.getAttribute('data-url');
        var made = 0;
        btn.disabled = true;
        post(url, { op: 'critical_plan' }).then(function (plan) {
            var pages = Object.keys(plan.pages || {});
            var chain = Promise.resolve();
            pages.forEach(function (page) {
                chain = chain.then(function () {
                    state.textContent = fmt(t.generating, t.names[page] || page);
                    var keep = {};
                    var hrefs = null;
                    var widths = Promise.resolve();
                    WIDTHS.forEach(function (w) {
                        widths = widths.then(function () {
                            return read(frame, plan.pages[page], w[0], w[1], keep).then(function (h) { hrefs = hrefs || h; });
                        });
                    });
                    return widths.then(function () {
                        var css = stitch(keep, BUDGET);
                        if (!css) { throw new Error((t.names[page] || page) + ': 0 kB'); }
                        return post(url, { op: 'critical_save', page: page, css: css, hrefs: JSON.stringify(hrefs || []) });
                    }).then(function (a) {
                        if (!a.ok) { throw new Error(a.error || 'save'); }
                        made++;
                        var row = box.querySelector('[data-spc-crit="' + page + '"]');
                        if (row) {
                            row.querySelector('[data-spc-crit-kb]').textContent = (a.bytes / 1024).toFixed(1) + ' kB';
                            row.querySelector('[data-spc-crit-at]').textContent = new Date().toISOString().slice(0, 16).replace('T', ' ');
                        }
                    });
                });
            });
            return chain;
        }).then(function () {
            state.textContent = fmt(t.generated, made);
        }).catch(function (e) {
            state.textContent = e.message === 'origin' ? t.blocked : fmt(t.failed, e.message);
        }).then(function () {
            btn.disabled = false;
        });
    }

    function start() {
        var box = document.getElementById('spc-optimize');
        if (!box) { return; }
        var t = {};
        try { t = JSON.parse(box.getAttribute('data-texts')) || {}; } catch (e) { /* the defaults below */ }
        var c = box.querySelector('[data-spc-convert]');
        if (c) { c.addEventListener('click', function () { convert(box, t); }); }
        var k = box.querySelector('[data-spc-critical]');
        if (k) { k.addEventListener('click', function () { critical(box, t); }); }
    }

    // exposed for the tests
    window.spcCritical = { askable: askable, stitch: stitch, walk: walk, read: read };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
