# Changelog

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
