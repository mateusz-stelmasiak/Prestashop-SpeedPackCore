/**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * Smart Prefetch - service worker.
 *
 * The page script decides *what* is worth fetching ahead of time; this worker
 * does the fetching, off the main thread, and keeps the results in Cache
 * Storage so the browser can serve them itself on the next navigation.
 *
 * Two caches, two very different risk profiles:
 *
 *   Assets  - images, CSS, JS, fonts. Immutable in practice (PrestaShop's CCC
 *             and PageSpeed both put a content hash in the filename), so they
 *             are served cache-first and refreshed in the background.
 *
 *   Documents - HTML. Served stale-while-revalidate, but only while the page
 *             has told us the session is impersonal: no cart, not logged in.
 *             A shop page carries cart totals and customer state in its
 *             markup, and serving a stale one to someone mid-purchase is how
 *             a prefetcher turns into a support ticket.
 *
 * Anything that can change state - cart, checkout, account, logout, an
 * add-to-cart URL - is never cached and never even prefetched; the deny list
 * comes from PHP, built from PrestaShop's own link builder.
 */

var VERSION = 'v1';
var DOC_PREFIX = 'sp-docs-' + VERSION + '-';
var ASSETS = 'sp-assets-' + VERSION;

/**
 * Pages are cached per identity: one shelf for a signed-out visitor, another
 * for a signed-in one.
 *
 * A shop page carries the customer in its markup, so a page fetched while
 * signed in must never be served after signing out. Storing the two apart,
 * and sweeping the shelves that are not the current one, means that cannot
 * happen -- and it lets a signed-in shopper have prefetching at all, which a
 * blanket refusal did not.
 */
var IDENTITIES = { guest: 1, member: 1 };

function docsCache(config) {
    var who = config && IDENTITIES[config.identity] ? config.identity : 'guest';
    return DOC_PREFIX + who;
}

var MAX_DOCS = 24;
var MAX_ASSETS = 180;
/* A cached page is reusable for a minute. Long enough to cover a shopper
 * moving through the menu, short enough that stock, prices and anything else
 * the page states are never stale by much. */
var DOC_TTL = 60 * 1000;
var STAMP = 'sw-fetched-at';

var CONFIG_CACHE = 'sp-config-' + VERSION;
var CONFIG_KEY = '/__smartprefetch_config__';

var DENY_PARAM = /(^|&)(add|delete|deleteproduct|deleteaddress|update|reorder|mylogout|logout|token|submitdelete|submitaddtocart|action|ajax|id_customization)(=|&|$)/i;
var ASSET_DEST = ['image', 'style', 'script', 'font'];

/**
 * Configuration lives in Cache Storage, not in a variable.
 *
 * A service worker is terminated whenever the browser feels like it and
 * restarted on the next event, so anything held in module scope is gone by
 * the time the next navigation arrives. Keeping the deny list in memory meant
 * a restarted worker would wake up with no idea which pages were forbidden.
 */
var configPromise = null;

var EMPTY_CONFIG = { denyPrefixes: [], identity: 'guest' };

function loadConfig() {
    if (configPromise) {
        return configPromise;
    }

    configPromise = caches.open(CONFIG_CACHE).then(function (cache) {
        return cache.match(CONFIG_KEY);
    }).then(function (response) {
        return response ? response.json() : null;
    }).then(function (config) {
        return config || EMPTY_CONFIG;
    }).catch(function () {
        return EMPTY_CONFIG;
    });

    return configPromise;
}

function saveConfig(config) {
    configPromise = Promise.resolve(config);

    return caches.open(CONFIG_CACHE).then(function (cache) {
        return cache.put(CONFIG_KEY, new Response(JSON.stringify(config), {
            headers: { 'Content-Type': 'application/json' }
        }));
    }).then(function () {
        return config;
    });
}

/** Drop the shelves belonging to whoever was signed in before. */
function sweepIdentities(config) {
    var keep = docsCache(config);

    return caches.keys().then(function (keys) {
        return Promise.all(keys.map(function (key) {
            if (key.indexOf(DOC_PREFIX) === 0 && key !== keep) {
                return caches.delete(key);
            }
            return null;
        }));
    });
}

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil((function () {
        return caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                var current = key.indexOf(DOC_PREFIX) === 0 ||
                    key === ASSETS || key === CONFIG_CACHE;

                if (key.indexOf('sp-') === 0 && !current) {
                    return caches.delete(key);
                }
                return null;
            }));
        }).then(function () {
            return self.clients.claim();
        });
    }()));
});

/* ------------------------------------------------------------------ *
 *  Talking to the page
 * ------------------------------------------------------------------ */

self.addEventListener('message', function (event) {
    var data = event.data || {};

    if (data.type === 'config' || data.type === 'state') {
        event.waitUntil(loadConfig().then(function (current) {
            return saveConfig({
                denyPrefixes: (data.denyPrefixes || current.denyPrefixes || []).map(function (p) {
                    return String(p).toLowerCase();
                }),
                identity: IDENTITIES[data.identity] ? data.identity : 'guest'
            }).then(function (saved) {
                return sweepIdentities(saved);
            });
        }));
        return;
    }

    if (data.type === 'flush') {
        /* The cart changed, so every stored page states the wrong one. */
        event.waitUntil(loadConfig().then(function (config) {
            return caches.delete(docsCache(config));
        }));
        return;
    }

    if (data.type === 'prefetch') {
        event.waitUntil(prefetchAll(data.urls || [], data.reason || 'prefetch'));
        return;
    }

    if (data.type === 'stats') {
        event.waitUntil(report());
    }
});

function tell(message) {
    return self.clients.matchAll({ includeUncontrolled: true, type: 'window' })
        .then(function (clients) {
            clients.forEach(function (client) {
                client.postMessage(message);
            });
        });
}

function report() {
    return loadConfig().then(function (config) {
        return Promise.all([count(docsCache(config)), count(ASSETS)]);
    }).then(function (sizes) {
        return tell({ type: 'stats', documents: sizes[0], assets: sizes[1] });
    });
}

function count(name) {
    return caches.open(name).then(function (cache) {
        return cache.keys().then(function (keys) { return keys.length; });
    });
}

/* ------------------------------------------------------------------ *
 *  Prefetching
 * ------------------------------------------------------------------ */

function prefetchAll(urls, reason) {
    return Promise.all(urls.map(function (url) {
        return prefetchOne(url, reason);
    }));
}

function prefetchOne(url, reason) {
    return loadConfig().then(function (config) {
        if (!allowed(url, config)) {
            return null;
        }
        return download(url, reason);
    });
}

function download(url, reason) {
    return loadConfig().then(function (config) {
        var shelf = docsCache(config);
        return caches.open(shelf).then(function (cache) {
        return cache.match(url).then(function (hit) {
            if (hit && fresh(hit)) {
                return null;
            }

            /* same-origin, credentials included so the response matches what
             * a real navigation would receive */
            return fetch(url, { credentials: 'same-origin' }).then(function (response) {
                if (!storable(response)) {
                    return tell({ type: 'skipped', url: url, status: response.status });
                }

                return put(cache, url, response).then(function () {
                    return trim(shelf, MAX_DOCS);
                }).then(function () {
                    return tell({ type: 'cached', url: url, reason: reason });
                });
            }).catch(function () {
                return tell({ type: 'failed', url: url, reason: reason });
            });
        });
        });
    });
}

/* ------------------------------------------------------------------ *
 *  Serving
 * ------------------------------------------------------------------ */

self.addEventListener('fetch', function (event) {
    var request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    var url;
    try {
        url = new URL(request.url);
    } catch (e) {
        return;
    }

    if (url.origin !== self.location.origin) {
        return;
    }

    if (isAsset(request)) {
        event.respondWith(assetFirst(request));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(handleDocument(request));
    }
});

/**
 * Serve a navigation from cache only when the stored configuration still says
 * it is safe to. Anything unexpected falls through to the network: a
 * prefetcher must never be the reason a page fails to load.
 */
function handleDocument(request) {
    return loadConfig().then(function (config) {
        if (!allowed(request.url, config)) {
            return fetch(request);
        }
        return documentSwr(request, config);
    }).catch(function () {
        return fetch(request);
    });
}

function assetFirst(request) {
    return caches.open(ASSETS).then(function (cache) {
        return cache.match(request).then(function (hit) {
            var network = fetch(request).then(function (response) {
                if (storable(response)) {
                    put(cache, request, response).then(function () {
                        return trim(ASSETS, MAX_ASSETS);
                    });
                }
                return response;
            }).catch(function () {
                return hit || Response.error();
            });

            return hit || network;
        });
    });
}

function documentSwr(request, config) {
    return caches.open(docsCache(config)).then(function (cache) {
        return cache.match(request.url).then(function (hit) {
            var network = fetch(request).then(function (response) {
                if (storable(response)) {
                    put(cache, request.url, response);
                }
                return response;
            });

            if (hit && fresh(hit)) {
                tell({ type: 'served', url: request.url });
                network.catch(function () { /* refresh is best effort */ });
                return hit;
            }

            return network.catch(function () {
                return hit || Response.error();
            });
        });
    });
}

/* ------------------------------------------------------------------ *
 *  Rules and housekeeping
 * ------------------------------------------------------------------ */

function isAsset(request) {
    if (ASSET_DEST.indexOf(request.destination) !== -1) {
        return true;
    }
    return /\.(css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot)($|\?)/i.test(request.url);
}

function allowed(url, config) {
    var denyPrefixes = (config && config.denyPrefixes) || [];
    var parsed;
    try {
        parsed = new URL(url, self.location.origin);
    } catch (e) {
        return false;
    }

    if (parsed.origin !== self.location.origin) {
        return false;
    }
    if (parsed.search && DENY_PARAM.test(parsed.search.slice(1))) {
        return false;
    }

    var path = parsed.pathname.toLowerCase();
    for (var i = 0; i < denyPrefixes.length; i++) {
        if (denyPrefixes[i] && path.indexOf(denyPrefixes[i]) === 0) {
            return false;
        }
    }

    return true;
}

/** Never store a partial, an error, or something the server said not to. */
/**
 * Whether a response may be kept.
 *
 * This used to refuse almost everything a PrestaShop shop sends, for two
 * reasons that both turned out to be wrong:
 *
 *   "private" was treated as a refusal. It is not one. Cache-Control: private
 *   means "no SHARED cache may keep this" -- a CDN, a corporate proxy. Cache
 *   Storage in a service worker is the opposite of shared: it is one
 *   browser profile on one device, reachable by nothing else. PrestaShop
 *   marks every front-office page private, so this alone rejected the lot.
 *   Staleness is handled where it belongs: a 60 second lifetime, an identity
 *   shelf per signed-in state, and the cart flush.
 *
 *   Set-Cookie was checked for. Fetch will not give it up -- it is a
 *   forbidden response header, so headers.get('Set-Cookie') is always null
 *   and the test never once fired. It read like a safeguard and was nothing
 *   of the kind.
 *
 * What is left is what actually means "do not keep this": no-store, an error,
 * a redirect, or a response we cannot see into.
 */
function storable(response) {
    if (!response || !response.ok || response.status !== 200) {
        return false;
    }

    /* Opaque and opaqueredirect cannot be inspected, so they cannot be aged
     * or validated; cors and basic both can. */
    if (response.type === 'opaque' || response.type === 'opaqueredirect') {
        return false;
    }

    if (response.redirected) {
        return false;
    }

    var control = response.headers.get('Cache-Control') || '';

    return !/no-store/i.test(control);
}

function put(cache, key, response) {
    var copy = response.clone();

    return copy.blob().then(function (body) {
        var headers = new Headers();
        copy.headers.forEach(function (value, name) {
            headers.set(name, value);
        });
        headers.set(STAMP, String(Date.now()));

        return cache.put(key, new Response(body, {
            status: copy.status,
            statusText: copy.statusText,
            headers: headers
        }));
    });
}

function fresh(response) {
    var stamp = parseInt(response.headers.get(STAMP), 10);
    if (!stamp) {
        return false;
    }
    return (Date.now() - stamp) < DOC_TTL;
}

/** Oldest out first, so a long browse cannot grow the cache without bound. */
function trim(name, max) {
    return caches.open(name).then(function (cache) {
        return cache.keys().then(function (keys) {
            if (keys.length <= max) {
                return null;
            }
            return Promise.all(keys.slice(0, keys.length - max).map(function (key) {
                return cache.delete(key);
            }));
        });
    });
}
