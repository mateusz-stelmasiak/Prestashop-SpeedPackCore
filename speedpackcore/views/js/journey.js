/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/* The back-office cart page: the path panel (sent with the head, in a <template>) goes to the top
 * of the page's content, on the old page (1.7, 8) and the new one (9). */
(function () {
    'use strict';

    function put() {
        var tpl = document.getElementById('spc-journey-cart');
        if (!tpl || !tpl.content || document.getElementById('spc-journey')) { return; }
        var host = document.querySelector('#main-div .content-div > .container-fluid')
            || document.querySelector('#main-div .content-div')
            || document.getElementById('content')
            || document.body;
        var before = null;
        for (var i = 0; i < host.children.length; i++) {
            var c = host.children[i];
            if (/\b(row|panel|card)\b/.test(c.className)) { before = c; break; }
        }
        host.insertBefore(tpl.content.cloneNode(true), before);
        tpl.parentNode.removeChild(tpl);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', put);
    } else {
        put();
    }
}());
