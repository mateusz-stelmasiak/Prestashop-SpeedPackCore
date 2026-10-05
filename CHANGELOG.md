# Changelog

## 1.1.0

- **Cache section:** a data cache in Redis, APCu or Memcached, switched on only after it passes a live test (connect, password, write and read). If the server goes down later, the shop keeps working without it.
- **OPcache panel:** hit rate, memory and files, with plain advice on what to ask your host for.
- **PrestaShop speed settings** in one place: template compiling, template cache, combined CSS and JavaScript, browser caching.
- **Empty the cache** (Redis keys of this shop only, templates, combined CSS/JS), **Reset OPcache**, and a **warm-up** that visits the home page, every category and the best-selling products.
- **InstantCart: instant quantity change on the cart page.** +, − and a typed number change the line at once; quick clicks reach the shop as one request with the final quantity.
- Upgrading from 1.0.0 keeps every setting.

## 1.0.0

First release: SmartPrefetch, InstantNav, InstantCart and CartSpeed in one module.
