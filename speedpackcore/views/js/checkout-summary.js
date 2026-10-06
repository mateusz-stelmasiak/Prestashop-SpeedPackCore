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

    var data = window.spcCheckout;
    if (!data || typeof data !== 'object') { return; }

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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', put);
    } else {
        put();
    }
}());
