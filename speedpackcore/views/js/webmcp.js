/**
 * WebMCP: the shop's own actions offered to an AI agent working in this browser, on the person's
 * own session: search the catalogue, put a product in the cart, read the cart, go to checkout.
 * Nothing here orders or pays; the person does that on the checkout.
 */
(function () {
    'use strict';

    var cfg = window.spcWebMcp;
    var mc = (document.modelContext || navigator.modelContext);
    if (!cfg || !mc || typeof mc.registerTool !== 'function') { return; }

    var controller = new AbortController();
    window.addEventListener('pagehide', function () { controller.abort(); }, { once: true });

    function shop() { return window.prestashop || {}; }

    function text(obj) { return JSON.stringify(obj); }

    function post(url, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'Accept': 'application/json, text/javascript, */*; q=0.01', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); });
    }

    function summary(cart) {
        if (!cart) { return null; }
        return {
            products: (cart.products || []).map(function (p) {
                return { id: Number(p.id_product), name: p.name, quantity: Number(p.quantity || p.cart_quantity), price: p.price };
            }),
            total: cart.totals && cart.totals.total ? cart.totals.total.value : null,
            count: cart.products_count
        };
    }

    var tools = [
        {
            name: 'search_products',
            title: 'Search products',
            description: 'Find products of this shop by name. Returns id, name, price, availability and page address.',
            inputSchema: { type: 'object', properties: { query: { type: 'string', description: 'Words of the product name' }, limit: { type: 'integer', minimum: 1, maximum: 50 } } },
            annotations: { readOnlyHint: true },
            execute: function (input) {
                var u = new URL(cfg.products, location.href);
                if (input && input.query) { u.searchParams.set('q', String(input.query).slice(0, 100)); }
                u.searchParams.set('limit', String(Math.min(50, Math.max(1, (input && input.limit) || 20))));
                return fetch(u.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                    return text((d.items || []).map(function (p) { return { id: p.id, name: p.name, price: p.price, currency: p.currency, available: p.available, url: p.url }; }));
                });
            }
        },
        {
            name: 'add_to_cart',
            title: 'Add to cart',
            description: 'Put a product in the person\'s cart in this shop (by product id from search_products). It does not order or pay.',
            inputSchema: { type: 'object', required: ['id'], properties: { id: { type: 'integer' }, quantity: { type: 'integer', minimum: 1, maximum: 99 } } },
            execute: function (input) {
                var qty = Math.min(99, Math.max(1, (input && input.quantity) || 1));
                return post(cfg.cart, {
                    token: shop().static_token || cfg.token, id_product: String(input.id), id_customization: '0',
                    qty: String(qty), add: '1', action: 'update', ajax: '1'
                }).then(function (resp) {
                    if (resp && resp.hasError) { return text({ ok: false, errors: resp.errors }); }
                    try {
                        if (shop().emit) { shop().emit('updateCart', { reason: { idProduct: input.id, idProductAttribute: 0, linkAction: 'add-to-cart' }, resp: resp }); }
                    } catch (e) { /* the theme refreshes on its own */ }
                    return text({ ok: true, cart: summary(resp && resp.cart) });
                });
            }
        },
        {
            name: 'view_cart',
            title: 'View cart',
            description: 'What is in the person\'s cart in this shop, with the total.',
            inputSchema: { type: 'object', properties: {} },
            annotations: { readOnlyHint: true },
            execute: function () {
                return post(cfg.cart, { ajax: '1', action: 'refresh', token: shop().static_token || cfg.token }).then(function (resp) {
                    return text(summary((resp && resp.cart) || shop().cart));
                }, function () { return text(summary(shop().cart)); });
            }
        },
        {
            name: 'go_to_checkout',
            title: 'Go to checkout',
            description: 'Open the checkout, where the person enters delivery details and pays. The agent does not pay.',
            inputSchema: { type: 'object', properties: {} },
            execute: function () {
                setTimeout(function () { location.href = cfg.order; }, 50);
                return Promise.resolve(text({ ok: true, url: cfg.order }));
            }
        }
    ];

    tools.forEach(function (tool) {
        try {
            var p = mc.registerTool(tool, { signal: controller.signal });
            if (p && p.catch) { p.catch(function () { /* already registered or refused */ }); }
        } catch (e) { /* an older implementation */ }
    });
})();
