/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * Checkout: what a finished step holds, under its title (window.spcCheckout, from SpcReorder). It
 * lines up with the title's text, whatever the icon in front of it, and shows only while the step
 * is closed (CSS).
 */
(function () {
    'use strict';

    var data = window.spcCheckout || {};

    /** Where the title's words start, so the summary sits right under them. */
    function indent(title) {
        var walker = document.createTreeWalker(title, NodeFilter.SHOW_TEXT, null, false);
        var node;
        while ((node = walker.nextNode())) {
            if (node.nodeValue.trim() && !(node.parentNode.closest && node.parentNode.closest('.step-number, .step-edit, .material-icons'))) {
                var range = document.createRange();
                range.selectNodeContents(node);
                var left = range.getBoundingClientRect().left - title.getBoundingClientRect().left;
                return left > 0 && left < 200 ? left : 0;
            }
        }
        return 0;
    }

    function put() {
        Object.keys(data).forEach(function (id) {
            var step = document.getElementById(id);
            var title = step && step.querySelector('.step-title');
            if (!title || !data[id] || title.querySelector('.spc-step-summary')) { return; }
            var summary = document.createElement('span');
            summary.className = 'spc-step-summary';
            summary.textContent = data[id];
            summary.title = data[id];
            title.appendChild(summary);
            var left = indent(title);
            if (left) { summary.style.paddingLeft = Math.round(left) + 'px'; }
        });
    }

    /* ---------------------------------------------------------------- *
     *  The cart's products at the top of the side column
     * ---------------------------------------------------------------- */

    var cart = window.spcCheckoutCart;
    var SHOW = 5;
    var open = false;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }

    /** The side column of the checkout (Classic: .cart-grid-right), or the summary's parent. */
    function column() {
        var summary = document.getElementById('js-checkout-summary');
        return document.querySelector('#checkout .cart-grid-right, body#checkout .cart-grid-right')
            || (summary && summary.parentNode) || null;
    }

    function draw() {
        var col = column();
        if (!cart || !col) { return; }
        var box = document.getElementById('spc-checkout-cart');
        if (!cart.items || !cart.items.length) {
            if (box) { box.parentNode.removeChild(box); }
            return;
        }
        if (!box) {
            box = el('section', 'spc-ccart');
            box.id = 'spc-checkout-cart';
            col.insertBefore(box, col.firstChild);
        }
        box.textContent = '';
        var head = el('div', 'spc-ccart-head');
        head.appendChild(el('span', 'spc-ccart-title', cart.title));
        head.appendChild(el('span', 'spc-ccart-count', cart.countText));
        box.appendChild(head);
        var list = el('ul', 'spc-ccart-list');
        cart.items.forEach(function (item, i) {
            var li = el('li', i >= SHOW && !open ? 'is-hidden' : '');
            if (item.src) {
                var img = el('img');
                img.src = item.src;
                img.alt = '';
                img.width = 56;
                img.height = 56;
                img.loading = 'lazy';
                li.appendChild(img);
            } else {
                li.appendChild(el('span', 'spc-ccart-noimg'));
            }
            var text = el('span', 'spc-ccart-text');
            text.appendChild(el('span', 'spc-ccart-name', item.name));
            if (item.attrs) { text.appendChild(el('span', 'spc-ccart-attrs', item.attrs)); }
            text.appendChild(el('span', 'spc-ccart-unit', item.qty + ' \u00d7 ' + item.unit));
            li.appendChild(text);
            li.appendChild(el('b', 'spc-ccart-total', item.total));
            list.appendChild(li);
        });
        box.appendChild(list);
        if (cart.items.length > SHOW) {
            var more = el('button', 'spc-ccart-more', open ? cart.less : cart.more.replace('%d', cart.items.length));
            more.type = 'button';
            more.addEventListener('click', function () { open = !open; draw(); });
            box.appendChild(more);
        }
    }

    /** After the cart changed on the page (a product added from the side column, a quantity). */
    function refresh() {
        if (!cart || !cart.url || !window.fetch) { return; }
        fetch(cart.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.items) {
                    cart.items = data.items;
                    cart.countText = data.countText;
                    draw();
                }
            })
            .catch(function () { /* the list stays as it was */ });
    }

    function start() {
        put();
        draw();
        if (window.prestashop && typeof window.prestashop.on === 'function') {
            window.prestashop.on('updatedCart', refresh);
            // the theme redraws the summary column after a change: put the list back on top
            window.prestashop.on('updatedCart', function () { setTimeout(draw, 300); });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
