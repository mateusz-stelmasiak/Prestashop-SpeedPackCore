/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * Behaviour tab: asks the module for a report (spc_ajax=behaviour) with the chosen filters and
 * draws it. Everything from the shop is put in as text, never as markup.
 */
(function () {
    'use strict';

    var KEEP = 'speedpackcore:bh';

    function init() {
        var root = document.getElementById('spc-behaviour');
        if (!root || root.getAttribute('data-spc-bh-ready')) { return; }
        root.setAttribute('data-spc-bh-ready', '1');

        var T = JSON.parse(root.getAttribute('data-spc-bh-texts') || '{}');
        var url = root.getAttribute('data-spc-bh-url');
        var enabled = root.getAttribute('data-spc-bh-enabled') === '1';
        var form = root.querySelector('[data-spc-bh-filters]');
        var q = root.querySelector('[data-spc-bh-q]');
        var out = root.querySelector('[data-spc-bh-report]');
        var note = root.querySelector('[data-spc-bh-note]');
        var modal = document.querySelector('[data-spc-bh-modal]');
        var labels = {};
        var asked = 0;

        /* ------------------------------------------------------------ *
         *  Small helpers
         * ------------------------------------------------------------ */

        function el(tag, attrs, kids) {
            var n = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (k) {
                if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'on') { n.addEventListener('click', attrs[k]); } else { n.setAttribute(k, attrs[k]); }
            });
            (kids || []).forEach(function (kid) { if (kid) { n.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid); } });
            return n;
        }

        function svg(tag, attrs) {
            var n = document.createElementNS('http://www.w3.org/2000/svg', tag);
            Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
            return n;
        }

        function fmt(text, args) {
            var i = 0;
            return String(text).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
                var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
                return v === undefined ? '' : String(v);
            }).replace(/%%/g, '%');
        }

        function num(n) { return Number(n || 0).toLocaleString(); }

        function dur(ms) {
            var s = Math.round((ms || 0) / 1000);
            if (s < 60) { return fmt(T.seconds, [s]); }
            if (s < 3600) { return fmt(T.minutes, [(s / 60).toFixed(s < 600 ? 1 : 0)]); }
            return fmt(T.hours, [(s / 3600).toFixed(1)]);
        }

        function typeOf(key) { return String(key).split(':')[0]; }

        function name(key) {
            var type = typeOf(key);
            var typeName = (T.types && T.types[type]) || type;
            if (labels[key]) { return labels[key]; }
            var id = String(key).split(':')[1];
            return id ? typeName + ' #' + id : typeName;
        }

        /** A page as a clickable chip: a click searches for visits through it. */
        function chip(key) {
            var type = typeOf(key);
            return el('span', { 'class': 'spc-bh-step t-' + type, title: key, text: name(key), on: function (e) { e.stopPropagation(); search(key); } });
        }

        function path(keys) {
            var p = el('div', { 'class': 'spc-bh-path' });
            keys.forEach(function (k, i) {
                if (i) { p.appendChild(el('span', { 'class': 'spc-bh-arrow', text: '→' })); }
                p.appendChild(k === '…' ? el('span', { 'class': 'spc-bh-muted', text: '…' }) : chip(k));
            });
            return p;
        }

        function box(title, kids, wide) {
            return el('div', { 'class': 'spc-bh-box' + (wide ? ' spc-bh-wide' : '') }, [el('h4', { text: title })].concat(kids));
        }

        function list(rows, render) {
            if (!rows || !rows.length) { return el('p', { 'class': 'spc-bh-muted', text: T.none }); }
            return el('ul', { 'class': 'spc-bh-list' }, rows.map(render));
        }

        function bar(label, value, max, cls, right) {
            var w = max ? Math.max(1, Math.round(100 * value / max)) : 0;
            return el('div', { 'class': 'spc-bh-bar' + (cls ? ' ' + cls : '') }, [
                el('i', { style: 'width:' + w + '%' }),
                el('span', {}, [el('b', { text: label }), el('span', { text: right })])
            ]);
        }

        /* ------------------------------------------------------------ *
         *  Filters
         * ------------------------------------------------------------ */

        var saved = {};
        try { saved = JSON.parse(window.localStorage.getItem(KEEP) || '{}') || {}; } catch (e) { saved = {}; }

        Array.prototype.forEach.call(root.querySelectorAll('[data-spc-bh-select]'), function (select) {
            var what = select.getAttribute('data-spc-bh-select');
            var options = T[what] || {};
            var order = what === 'range' ? ['1', '7', '30', '90'] : Object.keys(options);
            // number-like keys come first in a JavaScript object: say the order
            if (what === 'bucket') { order = ['0', '900', '3600', '86400', '604800']; }
            if (what === 'returning') { order = ['', '0', '1']; }
            order.forEach(function (v) { select.appendChild(el('option', { value: v, text: options[v] })); });
            select.value = saved[what] !== undefined && options[saved[what]] !== undefined ? saved[what] : (what === 'range' ? '7' : order[0]);
            select.addEventListener('change', load);
        });
        q.setAttribute('placeholder', T.search || '');
        q.value = saved.q || '';
        var typing = null;
        q.addEventListener('input', function () { clearTimeout(typing); typing = setTimeout(load, 450); });
        form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(typing); load(); });

        function filters() {
            var f = {};
            Array.prototype.forEach.call(root.querySelectorAll('[data-spc-bh-select]'), function (s) { f[s.getAttribute('data-spc-bh-select')] = s.value; });
            f.q = q.value.trim();
            try { window.localStorage.setItem(KEEP, JSON.stringify(f)); } catch (e) { /* not kept */ }
            return f;
        }

        function search(key) {
            q.value = key;
            load();
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function ask(params) {
            var parts = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); });
            return fetch(url + '&' + parts.join('&'), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            });
        }

        function say(text, error) {
            note.hidden = !text;
            note.textContent = text || '';
            note.className = 'spc-bh-note' + (error ? ' is-error' : '');
        }

        function load() {
            var f = filters();
            var mine = ++asked;
            var params = { op: 'report', from: Math.floor(Date.now() / 1000) - parseInt(f.range, 10) * 86400 };
            ['bucket', 'device', 'source', 'outcome', 'returning', 'q'].forEach(function (k) { if (f[k] !== '' && f[k] !== '0' || k === 'returning' && f[k] === '0') { params[k] = f[k]; } });
            out.style.opacity = '.5';
            ask(params).then(function (data) {
                if (mine !== asked) { return; }
                out.style.opacity = '';
                if (data.error) { throw new Error(data.error); }
                draw(data);
            }).catch(function (e) {
                if (mine !== asked) { return; }
                out.style.opacity = '';
                say(fmt(T.error, [e.message]), true);
            });
        }

        /* ------------------------------------------------------------ *
         *  The report
         * ------------------------------------------------------------ */

        function draw(d) {
            labels = d.labels || {};
            out.textContent = '';
            say('');
            if (!enabled && (!d.kpi || !d.kpi.sessions)) { say(T.off); }
            if (d.search && d.search.terms && d.search.terms.length) {
                var terms = el('div', { 'class': 'spc-bh-terms' });
                d.search.terms.forEach(function (t, i) {
                    if (i) { terms.appendChild(document.createTextNode(' → ')); }
                    terms.appendChild(el('b', { text: t.term }));
                    if (t.keys && t.keys.length && t.as === 'name') { terms.appendChild(document.createTextNode(' (' + t.keys.length + ')')); }
                });
                out.appendChild(terms);
            }
            if (d.empty) {
                var missing = (d.search.terms || []).filter(function (t) { return t.sql === null && (!t.keys || !t.keys.length); })[0];
                say(missing ? fmt(T.noMatch, [missing.term]) : T.empty);
                return;
            }
            var k = d.kpi;
            var kpis = el('div', { 'class': 'spc-bh-kpis' });
            [
                ['sessions', num(k.sessions)], ['views', num(k.views)], ['perSession', k.perSession], ['engaged', dur(k.engaged)],
                ['bounce', k.bounce + '%'], ['cart', k.cart + '%'], ['conversion', k.conversion + '%'], ['returning', k.returning + '%'], ['live', num(k.live)]
            ].forEach(function (p) {
                kpis.appendChild(el('div', { 'class': 'spc-bh-kpi' + (p[0] === 'live' ? ' is-live' : '') }, [el('b', { text: String(p[1]) }), el('span', { text: T.kpi[p[0]] })]));
            });
            out.appendChild(kpis);
            if (!k.sessions) { say(T.empty); return; }

            out.appendChild(box(T.timeline, [timeline(d.timeline)], true));

            out.appendChild(box(T.pages, [pages(d.pages)], true));

            var g1 = el('div', { 'class': 'spc-bh-grid' });
            g1.appendChild(box(T.funnel, [funnel(d.funnel)]));
            g1.appendChild(box(T.dwell, [dwell(d.dwell)]));
            out.appendChild(g1);

            if (d.vitals) { out.appendChild(box(T.vitals, vitals(d.vitals), true)); }

            var g2 = el('div', { 'class': 'spc-bh-grid' });
            g2.appendChild(box(T.routes, [list(d.routes, function (r) {
                return el('li', {}, [path([r.from, r.to]), el('span', { 'class': 'spc-bh-count', title: fmt(T.routeShare, [r.share]), text: num(r.count) })]);
            })]));
            g2.appendChild(box(T.paths, [list(d.paths, function (p) {
                return el('li', {}, [path(p.path), el('span', { 'class': 'spc-bh-count', text: num(p.count) + ' · ' + p.conversion + '%' })]);
            })]));
            out.appendChild(g2);

            var s = d.success;
            var g3 = el('div', { 'class': 'spc-bh-grid' });
            var facts = [];
            if (s.secondsToOrder !== null) { facts.push(el('p', { 'class': 'help-block', text: fmt(T.toOrder, [dur(s.secondsToOrder * 1000), s.pagesToOrder]) })); }
            if (s.cartToOrder.new !== null || s.cartToOrder.returning !== null) {
                facts.push(el('p', { 'class': 'help-block', text: fmt(T.cartToOrder, [s.cartToOrder.new === null ? '–' : dur(s.cartToOrder.new * 1000), s.cartToOrder.returning === null ? '–' : dur(s.cartToOrder.returning * 1000)]) }));
            }
            g3.appendChild(box(T.success, [list(s.paths, function (p) {
                return el('li', {}, [path(p.path.concat(['order-confirmation'])), el('span', { 'class': 'spc-bh-count', text: num(p.count) })]);
            })].concat(facts)));
            g3.appendChild(box(T.failures, failures(d)));
            out.appendChild(g3);

            if (d.sample && d.sample.read < d.sample.of) {
                out.appendChild(el('p', { 'class': 'help-block', text: fmt(T.sample, [d.sample.read, d.sample.of]) }));
            }
            out.appendChild(box(T.sessions + ' · ' + fmt(T.sessionsOf, [d.sessions.length, k.sessions]), [sessions(d.sessions)], true));
        }

        function timeline(t) {
            var wrap = el('div', { 'class': 'spc-bh-chart' });
            var pts = t.points || [];
            var W = 1000;
            var H = 170;
            var max = Math.max.apply(null, pts.map(function (p) { return p.sessions; }).concat([1]));
            var chart = svg('svg', { viewBox: '0 0 ' + W + ' ' + (H + 20), preserveAspectRatio: 'none', role: 'img', 'aria-label': T.timeline });
            [0, 0.5, 1].forEach(function (f) {
                var y = H - f * H;
                chart.appendChild(svg('line', { x1: 0, x2: W, y1: y, y2: y, 'class': 'grid' }));
                var lab = svg('text', { x: 2, y: y - 3, 'class': 'axis' });
                lab.textContent = Math.round(f * max);
                chart.appendChild(lab);
            });
            var step = W / Math.max(1, pts.length);
            var bw = Math.max(1, step * 0.8);
            var every = Math.ceil(pts.length / 8);
            pts.forEach(function (p, i) {
                var x = i * step + (step - bw) / 2;
                [['sessions', 'bar-s'], ['carts', 'bar-c'], ['orders', 'bar-o']].forEach(function (s) {
                    var h = H * p[s[0]] / max;
                    if (h <= 0) { return; }
                    var r = svg('rect', { x: x, y: H - h, width: bw, height: h, 'class': s[1] });
                    var tip = svg('title');
                    tip.textContent = when(p.t, t.bucket) + ' · ' + T.series.sessions + ' ' + p.sessions + ' · ' + T.series.carts + ' ' + p.carts + ' · ' + T.series.orders + ' ' + p.orders;
                    r.appendChild(tip);
                    chart.appendChild(r);
                });
                if (i % every === 0) {
                    var lab = svg('text', { x: x, y: H + 14, 'class': 'axis' });
                    lab.textContent = when(p.t, t.bucket);
                    chart.appendChild(lab);
                }
            });
            wrap.appendChild(chart);
            wrap.appendChild(el('div', { 'class': 'spc-bh-legend' }, [['bar-s', '#cfe8f0', 'sessions'], ['bar-c', '#25b9d7', 'carts'], ['bar-o', '#1d2a5b', 'orders']].map(function (s) {
                return el('span', {}, [el('i', { style: 'background:' + s[1] }), T.series[s[2]]]);
            })));
            return wrap;
        }

        function when(t, bucket) {
            var d = new Date(t * 1000);
            var pad = function (n) { return ('0' + n).slice(-2); };
            var day = pad(d.getDate()) + '.' + pad(d.getMonth() + 1);
            return bucket < 86400 ? day + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) : day;
        }

        function pages(rows) {
            if (!rows.length) { return el('p', { 'class': 'spc-bh-muted', text: T.none }); }
            var head = el('tr', {}, T.pageCols.map(function (c) { return el('th', { text: c }); }));
            var body = rows.map(function (r) {
                return el('tr', {}, [
                    el('td', {}, [el('span', { 'class': 'spc-bh-type', text: (T.types[typeOf(r.page)] || typeOf(r.page)) }), el('span', { 'class': 'spc-bh-page', text: name(r.page), title: r.page, on: function () { search(r.page); } })]),
                    el('td', { text: num(r.views) }),
                    el('td', { text: r.avgMs ? dur(r.avgMs) : '–' }),
                    el('td', { text: r.scroll ? r.scroll + '%' : '–' }),
                    el('td', { text: num(r.entries) }),
                    el('td', { text: r.exitRate + '%' }),
                    el('td', { text: r.conversion + '%' })
                ]);
            });
            return el('div', { 'class': 'spc-bh-scroll' }, [el('table', { 'class': 'spc-bh-table' }, [el('thead', {}, [head]), el('tbody', {}, body)])]);
        }

        /** A Core Web Vitals figure as people read it: seconds, milliseconds, or the CLS score. */
        function vital(name, v) {
            if (v === null || v === undefined) { return '–'; }
            if (name === 'cls') { return (v / 1000).toFixed(2); }
            if (name === 'inp' || v < 1000) { return v + ' ms'; }
            return (v / 1000).toFixed(2) + ' s';
        }

        function vitals(vt) {
            if (!vt.views) { return [el('p', { 'class': 'spc-bh-muted', text: T.vitalsNone })]; }
            var names = ['lcp', 'inp', 'cls', 'ttfb', 'fcp'];
            var tiles = el('div', { 'class': 'spc-bh-vitals' }, names.filter(function (n) { return vt.all[n]; }).map(function (n) {
                var r = vt.all[n];
                return el('div', { 'class': 'spc-bh-vital r-' + r.rating }, [
                    el('span', { text: T.vitalNames[n] }),
                    el('b', { text: vital(n, r.p75) }),
                    el('em', { text: T.ratings[r.rating] }),
                    el('div', { 'class': 'spc-bh-share', title: T.ratings.good + ' ' + r.good + '% · ' + T.ratings.ni + ' ' + r.ni + '% · ' + T.ratings.poor + ' ' + r.poor + '%' }, [
                        el('i', { 'class': 'g', style: 'width:' + r.good + '%' }), el('i', { 'class': 'n', style: 'width:' + r.ni + '%' }), el('i', { 'class': 'p', style: 'width:' + r.poor + '%' })
                    ])
                ]);
            }));
            var kids = [tiles];
            var dev = Object.keys(vt.devices).filter(function (k) { return vt.devices[k].lcp || vt.devices[k].inp; }).map(function (k) {
                var x = vt.devices[k];
                return (T.device[k] || k) + ': LCP ' + vital('lcp', x.lcp && x.lcp.p75) + ', INP ' + vital('inp', x.inp && x.inp.p75) + ', CLS ' + vital('cls', x.cls && x.cls.p75);
            });
            if (dev.length) { kids.push(el('p', { 'class': 'help-block', text: dev.join(' · ') })); }
            if (vt.pages.length) {
                var cell = function (r, n) { return el('td', { 'class': r[n] ? 'r-' + r[n].rating : '', text: r[n] ? vital(n, r[n].p75) : '–' }); };
                kids.push(el('div', { 'class': 'spc-bh-scroll' }, [el('table', { 'class': 'spc-bh-table spc-bh-vtable' }, [
                    el('thead', {}, [el('tr', {}, [T.pageCols[0], T.pageCols[1]].concat(names.map(function (n) { return n.toUpperCase(); })).map(function (c) { return el('th', { text: c }); }))]),
                    el('tbody', {}, vt.pages.map(function (r) {
                        return el('tr', {}, [el('td', {}, [el('span', { 'class': 'spc-bh-page', text: name(r.page), title: r.page, on: function () { search(r.page); } })]), el('td', { text: num(r.views) })].concat(names.map(function (n) { return cell(r, n); })));
                    }))
                ])]));
            }
            kids.push(el('p', { 'class': 'help-block', text: fmt(T.vitalsNote, [vt.views]) }));
            return kids;
        }

        function funnel(f) {
            var wrap = el('div');
            var prev = null;
            ['sessions', 'product', 'cart', 'checkout', 'personal', 'addresses', 'delivery', 'payment', 'pay', 'ordered'].forEach(function (s) {
                var v = f[s];
                var pct = f.sessions ? Math.round(1000 * v / f.sessions) / 10 : 0;
                var right = num(v) + ' · ' + pct + '%';
                var drop = prev !== null && prev > 0 && v / prev < 0.5;
                wrap.appendChild(bar(T.steps[s], v, f.sessions, s === 'ordered' ? 'is-good' : (drop ? 'is-drop' : ''), right));
                prev = v;
            });
            return wrap;
        }

        function dwell(d) {
            var wrap = el('div');
            var edges = d.edges;
            var total = d.counts.reduce(function (a, b) { return a + b; }, 0);
            var max = Math.max.apply(null, d.counts.concat([1]));
            d.counts.forEach(function (c, i) {
                var lo = i ? edges[i - 1] : 0;
                var label = i < edges.length ? short(lo) + '–' + short(edges[i]) : short(lo) + '+';
                wrap.appendChild(bar(label, c, max, '', num(c) + (total ? ' · ' + Math.round(100 * c / total) + '%' : '')));
            });
            return wrap;
        }

        function short(sec) { return sec < 60 ? fmt(T.seconds, [sec]) : fmt(T.minutes, [sec / 60]); }

        function failures(d) {
            var kids = [];
            var keyList = function (title, rows, asPage) {
                kids.push(el('h5', { text: title }));
                kids.push(list(rows, function (r) {
                    var left = asPage ? chip(r.key) : el('span', { text: r.key });
                    if (!asPage && r.key.charAt(0) === '/') { left = el('span', { 'class': 'spc-bh-page', text: r.key, on: function () { search(r.key); } }); }
                    return el('li', {}, [left, el('span', { 'class': 'spc-bh-count', text: num(r.count) })]);
                }));
            };
            keyList(T.abandoned, d.abandoned.exits, true);
            keyList(T.emptySearch, d.failures.emptySearch, false);
            keyList(T.notFound, d.failures.notFound, false);
            keyList(T.errors, d.failures.errors, false);
            return kids;
        }

        function sessions(rows) {
            if (!rows.length) { return el('p', { 'class': 'spc-bh-muted', text: T.none }); }
            return el('ul', { 'class': 'spc-bh-list' }, rows.map(function (s) {
                var meta = [when(s.started, 60), T.device[s.device] || s.device, (T.source[s.source] || s.source) + (s.ref ? ' (' + s.ref + ')' : ''),
                    dur(s.activeMs), s.views + ' ' + T.kpi.views.toLowerCase()];
                if (s.returning) { meta.push(T.returningShopper); }
                if (s.customer) { meta.push(fmt(T.customer, [s.customer])); }
                var head = el('div', { 'class': 'spc-bh-meta' }, [meta.join(' · '), el('span', { 'class': 'spc-bh-pill o-' + s.outcome, text: T.outcomes[s.outcome] + (s.total ? ' ' + s.total.toFixed(2) : '') })]);
                var p = path(s.more ? s.path.concat(['…']) : s.path);
                return el('li', { 'class': 'spc-bh-session', on: function () { open(s.id); } }, [el('div', {}, [head, p])]);
            }));
        }

        /* ------------------------------------------------------------ *
         *  One visit
         * ------------------------------------------------------------ */

        function open(id) {
            var body = modal.querySelector('[data-spc-bh-session]');
            body.textContent = T.loading;
            modal.hidden = false;
            ask({ op: 'session', id: id }).then(function (s) {
                if (s.error) { throw new Error(s.error); }
                Object.keys(s.labels || {}).forEach(function (k) { labels[k] = s.labels[k]; });
                body.textContent = '';
                body.appendChild(el('h4', {}, [when(s.started, 60) + ' ', el('span', { 'class': 'spc-bh-pill o-' + s.outcome, text: T.outcomes[s.outcome] })]));
                body.appendChild(el('ol', { 'class': 'spc-bh-timeline' }, s.views.map(function (v) {
                    var offset = v.at - s.started;
                    var line = el('div', {}, [
                        el('span', { 'class': 'spc-bh-muted', text: '+' + Math.floor(offset / 60) + ':' + ('0' + offset % 60).slice(-2) + '  ' }),
                        chip(v.page),
                        el('span', { 'class': 'spc-bh-muted', text: '  ' + dur(v.activeMs) + (v.scroll ? ' · ' + v.scroll + '%' : '') + ' · ' + T.nav[v.nav]
                            + (v.lcp !== null && v.lcp !== undefined ? ' · LCP ' + vital('lcp', v.lcp) : '') + (v.inp ? ' · INP ' + vital('inp', v.inp) : '') + (v.cls !== null && v.cls !== undefined ? ' · CLS ' + vital('cls', v.cls) : '') })
                    ]);
                    var kids = [line, el('div', { 'class': 'spc-bh-muted', text: v.url })];
                    v.events.forEach(function (e) {
                        var text = e.type === 'search' ? fmt(T.events.search, [e.detail, e.value < 0 ? '?' : e.value]) : fmt(T.events[e.type] || e.type, [T.steps[e.detail] || e.detail]);
                        kids.push(el('div', { 'class': 'ev', text: '• ' + text }));
                    });
                    return el('li', {}, kids);
                })));
            }).catch(function (e) { body.textContent = fmt(T.error, [e.message]); });
        }

        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.hasAttribute('data-spc-bh-close')) { modal.hidden = true; }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { modal.hidden = true; } });

        // the report is asked for once its tab is first shown, not with every visit to the settings
        if ('IntersectionObserver' in window) {
            var seen = new IntersectionObserver(function (entries) {
                if (entries.some(function (e) { return e.isIntersecting; })) { seen.disconnect(); load(); }
            });
            seen.observe(root);
        } else {
            load();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
