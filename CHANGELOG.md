# Changelog

## SpeedPack Core 1.3.0

**Health check**, a new section built from PrestaShop's optimization guide (Scale > Optimizations and Taking care of PrestaShop), read from the running shop:
- Server and PrestaShop: PHP version and SAPI, memory, input vars, upload sizes, realpath cache with its real fill, display_errors, session.auto_start, OPcache interned strings and revalidate_freq; debug mode, profiler, template compilation and cache, multi-front optimizations (one-click off), media servers, Composer autoloader.
- Database: version, buffer pool against the real table size, temporary tables (and how many went to disk), table_open_cache, performance_schema, query cache, MyISAM leftovers; ANALYZE TABLE in batches.
- The php.ini / my.cnf lines for the host, worked out for this shop, with a Copy button.
- Database care: log, visit statistics, abandoned guest carts, orphaned guests, 404 / search statistics, e-mail log – sizes, a preview count and batched cleanup; never younger than a week, never orders or customer carts. The configuration table's size and largest values.
- Module weight: front-office hooks and the CSS / JS each module adds to the home and product pages.
- The Cache section shows whether the database has a query cache.

## SpeedPack Core 1.2.1

Security fix from a code scan of InstantNav's content swap:
- A fetched page whose content carries anything active (an event-handler attribute such as `onerror`, a `javascript:` address, an iframe, object or embed, a `<meta http-equiv>`, SVG `<set>`/`<animate>`, or a script) now loads normally instead of being swapped in. Before, only scripts were caught.
- What is swapped in is an inert copy: active elements are left out and active attributes removed, as a second lock behind the check above. Microdata (`<meta itemprop>`) and JSON-LD still travel with the content.

## SpeedPack Core 1.2.0

- **Speed audit.** One button on the settings page (offered after install and after upgrading) measures each part without SpeedPack and with it, on the shop's own server, in about a minute: server answer time of five pages (data cache), click to page shown through the menu with no speed-ups, SmartPrefetch, InstantNav and everything (in a shop window it opens), adding to the cart (cart page against the lean endpoint) and the cart's address lookups (CartSpeed). Animated icons and before/after bars, and a chart of the last 12 audits. "Without" applies only to the audit's own requests, through a signed cookie valid for 15 minutes; with Redis, the cache class steps aside for them too.
- **SmartPrefetch 2.0.** In Chrome and Edge, prefetching goes through the browser's Speculation Rules: prefetch on hover, and a full prerender when the pointer stays 250 ms (new switch, on by default; at most 4 per visit; never on touch). In a mock-shop benchmark at a 300 ms hover the click showed the page in 321 ms instead of 570 ms, and in 35 ms after a 1 s hover. The old prefetch hint, which PrestaShop pages could not reuse, is gone.
- In other browsers the service worker no longer downloads a page twice when the click arrives while the prefetch is still running (a quick click went from 936 ms to 533 ms in the same benchmark).
- SmartPrefetch leaves the menu links InstantNav swaps in to InstantNav, instead of fetching them twice.
- PrestaShop 9 is supported.

## SpeedPack Core 1.1.1

Security fixes from the PrestaShop Addons review:
- The prefetch worker is a static file; the `sw` controller that printed it is gone (and removed on upgrade). The module's `.htaccess` sends `Service-Worker-Allowed` so it still covers the whole shop on Apache and LiteSpeed.
- InstantNav no longer runs scripts from a fetched page: content that brings scripts loads normally. New content is inserted as imported nodes, never re-parsed markup.
- Layouts read back from the browser's storage are rebuilt from bounded numbers before use.
- Every navigation the scripts start goes through a same-site http(s) check.
- InstantCart builds its button icon from elements and puts the button's own content back as nodes, with no `innerHTML`.

## AsyncCart 1.0.0

The instant cart page as a module of its own: quantity changes sent once with the final quantity, removal with Undo (also after the shop has deleted the line), totals from lean endpoints. Stands aside when SpeedPack Core's InstantCart handles the cart page.

## 1.1.0

- **Cache section:** a data cache in Redis, APCu or Memcached, switched on only after it passes a live test (connect, password, write and read). If the server goes down later, the shop keeps working without it.
- **OPcache panel:** hit rate, memory and files, with plain advice on what to ask your host for.
- **PrestaShop speed settings** in one place: template compiling, template cache, combined CSS and JavaScript, browser caching.
- **Empty the cache** (Redis keys of this shop only, templates, combined CSS/JS), **Reset OPcache**, and a **warm-up** that visits the home page, every category and the best-selling products.
- **InstantCart: instant quantity change on the cart page.** +, − and a typed number change the line at once; quick clicks reach the shop as one request with the final quantity.
- Upgrading from 1.0.0 keeps every setting.

## 1.0.0

First release: SmartPrefetch, InstantNav, InstantCart and CartSpeed in one module.
