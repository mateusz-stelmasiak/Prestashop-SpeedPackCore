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
 * is closed (CSS). A closed step opens from a click anywhere on it, not just on "edit".
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
     *  A finished step opens from a click anywhere on it, like an accordion
     * ---------------------------------------------------------------- */

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** A step that is finished (or was reached) and closed: one a click may open. */
    function openable(step) {
        return step && !step.classList.contains('-current') && !step.classList.contains('-unreachable')
            && (step.classList.contains('-complete') || step.classList.contains('-clickable'));
    }

    /** Marks the closed steps for the cursor, the hover and the keyboard. */
    function mark() {
        Array.prototype.forEach.call(document.querySelectorAll('.checkout-step'), function (step) {
            var title = step.querySelector('.step-title');
            var can = openable(step);
            step.classList.toggle('spc-step-openable', can);
            if (!title) { return; }
            if (can) {
                title.setAttribute('tabindex', '0');
                title.setAttribute('role', 'button');
                title.setAttribute('aria-expanded', 'false');
            } else {
                title.removeAttribute('tabindex');
                title.removeAttribute('role');
                if (step.classList.contains('-current')) { title.setAttribute('aria-expanded', 'true'); } else { title.removeAttribute('aria-expanded'); }
            }
        });
    }

    /**
     * Opens a step the way PrestaShop does when it handles the click itself (core checkout.js):
     * it becomes the current one, and if it has its own "continue" button the steps after it wait
     * until it is sent again.
     */
    function open(step) {
        Array.prototype.forEach.call(document.querySelectorAll('.checkout-step'), function (s) {
            s.classList.remove('-current', 'js-current-step');
        });
        step.classList.add('-current', 'js-current-step');
        if (step.querySelector('button.continue')) {
            var next = step.nextElementSibling;
            while (next) {
                if (next.classList.contains('checkout-step')) {
                    next.classList.add('-unreachable');
                    next.classList.remove('-complete');
                    var t = next.querySelector('.step-title');
                    if (t) { t.classList.add('not-allowed'); }
                }
                next = next.nextElementSibling;
            }
        }
        if (window.prestashop && typeof window.prestashop.emit === 'function') {
            window.prestashop.emit('changedCheckoutStep', { event: null });
        }
    }

    /** The opened step's content unfolds, and the step comes into view if its top is hidden. */
    function unfold(step) {
        var content = step.querySelector('.content');
        if (content && !reduced) {
            var h = content.scrollHeight;
            content.style.overflow = 'hidden';
            content.style.height = '0px';
            content.style.opacity = '0';
            content.getBoundingClientRect();
            content.style.transition = 'height .35s cubic-bezier(.2,.7,.2,1), opacity .3s ease';
            content.style.height = h + 'px';
            content.style.opacity = '1';
            var done = function () {
                content.removeEventListener('transitionend', done);
                content.style.height = content.style.overflow = content.style.transition = content.style.opacity = '';
            };
            content.addEventListener('transitionend', done);
            setTimeout(done, 500);
        }
        var top = step.getBoundingClientRect().top;
        if (top < 60) {
            window.scrollTo({ top: Math.max(0, window.pageYOffset + top - 90), behavior: reduced ? 'auto' : 'smooth' });
        }
    }

    function onClick(event) {
        var target = event.target;
        if (!target.closest) { return; }
        var step = target.closest('.checkout-step');
        // fields, links and buttons inside a step keep their own clicks
        if (!step || target.closest('a, button, input, select, textarea, label')) { return; }
        var wasOpen = step.classList.contains('-current');
        // PrestaShop's own handler (on the step) has run by now; if it opened the step, only animate
        setTimeout(function () {
            if (!wasOpen && !step.classList.contains('-current') && openable(step)) { open(step); }
            if (!wasOpen && step.classList.contains('-current')) { unfold(step); }
            mark();
        }, 0);
    }

    function onKey(event) {
        if (event.key !== 'Enter' && event.key !== ' ') { return; }
        var title = event.target.closest && event.target.closest('.spc-step-openable .step-title');
        if (!title) { return; }
        event.preventDefault();
        title.click();
    }

    /* ---------------------------------------------------------------- *
     *  The cart's products at the top of the side column
     * ---------------------------------------------------------------- */

    var cart = window.spcCheckoutCart;
    var SHOW = 5;
    var all = false;

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
            var li = el('li', i >= SHOW && !all ? 'is-hidden' : '');
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
            var more = el('button', 'spc-ccart-more', all ? cart.less : cart.more.replace('%d', cart.items.length));
            more.type = 'button';
            more.addEventListener('click', function () { all = !all; draw(); });
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
        mark();
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
        if (window.prestashop && typeof window.prestashop.on === 'function') {
            window.prestashop.on('updatedCart', refresh);
            // the theme redraws the summary column after a change: put the list back on top
            window.prestashop.on('updatedCart', function () { setTimeout(draw, 300); });
            window.prestashop.on('changedCheckoutStep', function () { setTimeout(mark, 0); });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
