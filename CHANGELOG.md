# Changelog

## SpeedPack Core 1.8.0

Three things the competition has, now here too.

- **Page cache for shoppers with a cart.** Visitors who are not signed in but have something in their cart used to get every page built live. They now get the kept page, with their own cart in the header asked for on the page (PrestaShop's `updateCart`, as the cart block does after a change; sent after the page's jQuery ready handlers). Signed-in customers are still built live. A switch ("Shoppers with a cart too"), on by default; answers say `X-SpeedPack-Cache: HIT cart`.
- **The page cache warms itself again.** The pages a change clears (and those "Clear cache" empties) are queued and opened in the background after a visitor has their page, a few at a time and one warm-up at a time, as a computer and as a phone, with the picture formats browsers take (PHP-FPM; its own requests never count as visitors or start a warm-up). "Warm the whole catalogue now" on the settings page (home, CMS pages, categories, products best sellers first, in every language), and a cron address with a key that works the queue and then the catalogue, about 25 seconds a call.
- **Delay third-party scripts** (Optimize, off until switched on): scripts whose address or code holds an entry of an editable list (Google tag and Analytics, Facebook pixel, Hotjar, Clarity, Tawk, Smartsupp, LiveChat, Tidio, Crisp, HubSpot, ad tags, TikTok, Pinterest, LinkedIn, Criteo) run at the visitor's first touch, key, scroll or pointer move, or after a time limit (10 s by default, 0 to wait for the visitor only), in the page's order. A stand-in `gtag()` and `dataLayer` keep a cookie banner working before then. Never on the cart, checkout and account pages.
- SmartPrefetch keeps its stored pages when the page cache only asks for the shopper's cart.

Tests: the cart shopper's key and kept page, the warm queue (filled by a change and by emptying, campaign tags dropped, switched off), delaying in PHP and the loader in Chromium (nothing before the move, all in order at it, once, and at the time limit).

## SpeedPack Core 1.7.5

- **Fix: a blank page after a menu tap on phones.** After InstantNav swaps the page in, it now makes sure the page shows: when the swap is done and once more a moment later, it undoes what a theme or module left hiding it (the phone menu hiding `#wrapper` and `#footer`, a fade cut short), and if the content still does not show (a stylesheet keeps it hidden), the page loads normally. A swapped page is never left blank.

## SpeedPack Core 1.7.4

- **The footer credit sits at the very bottom of the footer**, across its whole width, instead of among the footer's columns (displayFooter is one of them; the line moves itself to the end).
- **The numbers in llms.txt:** under the speed-ups, the last speed audit as "without → with" (click to page shown, server answer with the page cache and with the data cache, add to cart, the cart's address queries), Optimize's effect on the home and a product page (scripts holding the page up, pictures loaded at once, pictures in WebP/AVIF, HTML weight) and the page cache today (pages kept, share of visits served from it). Only what got better is said.

## SpeedPack Core 1.7.3

- **Critical CSS fixed and made small.** It stopped at "came out too large (61 kB)" because every element counted as in the first screen, hidden ones too (closed menus, dropdowns, phone-only parts), and every rule for hovers and focus was kept. Now only what shows in the first screen counts, each rule keeps only the selectors that match there, hover/focus/active rules wait for the full stylesheet, and fonts are kept only when a kept rule uses them. Elements hidden there keep just the declarations that hide them, so nothing flashes open. Typical pages come out at 20–30 kB; past 42 kB the rules for what sits lowest on the first screen go first, instead of stopping.
- **Fix: critical CSS lost its child selectors.** PrestaShop's HTML cleaning turned `>` into `&gt;` when it was saved, so rules like `#header .menu > ul > li` did nothing and the first paint fell apart. It is kept encoded now; CSS saved by 1.7.0–1.7.2 is read back with its entities undone.
- The pages are read in a frame laid out whatever tab of the settings page is open; an empty result is never saved as done.

## SpeedPack Core 1.7.2

- **Fix: the page cache could not be switched on** where PrestaShop kept a value for one shop (multistore, or left by another tool): that value won over the one saved, so the switch said "Switched on" and stayed off. Its settings and every part's switch are now saved for the whole installation, and the page says so if PrestaShop still does not keep it.
- **Page cache: "Test as a visitor".** The home page opened twice from the server as a first-time visitor, and the answer in words: it works (with both times), it is off, a cache in front answered, which reason keeps pages from being kept (a module making a cart on every first visit, viewed products, a parameter...), or that the cache folder cannot be written. A warning on the page when that folder cannot be written.
- **"Save and update llms.txt now"** next to "Mention in llms.txt". A llms.txt module that generates on demand (ps_llms_generator and others with generateNow) makes the file again; the "Site performance" section then goes in at the end of the file, once, and the rest of the file is kept, never overwritten. Without such a module the file in the shop's root gets just the section; switched off, just the section is taken out. The section now names the page cache's gain and the author.
- **The footer credit names the author:** "Fast pages: SpeedPack Core by Mateusz Stelmasiak", with the click speed-up or, when there is none, the pages' one.
- **Reorder repeats an order that can be bought:** when something in the latest order is switched off, no longer for sale, its combination gone or sold out (and not orderable without stock), the latest earlier order (of the last ten) that can be bought whole takes its place; when none is whole, the one with the most products still there.

## SpeedPack Core 1.7.1

- **The speed audit compares the new parts too.** Page cache: the same five pages answered from it against built without SpeedPack (a page it does not keep shows no figure, not a made-up one). Optimize: the home page and a product page without it and with it, counting the scripts that hold the page up, the pictures loaded at once, the pictures in WebP or AVIF and the HTML's weight. Both saved with the audit; the chart of earlier audits shows the server's best answer.
- **Fix:** the audit never measured InstantCart: the query choosing a product to add asked for a column PrestaShop does not have, so every shop got "no product can be added from a list". It now follows the product's own out-of-stock choice and the shop's default.
- **Fix:** InstantNav was timed to the end of its transition, a new page to its first paint, so InstantNav could come out slower than no speed-up. Both are now timed to the frame that shows the new content (InstantNav says `instantnav:swapped` at the swap).
- A part clearly slower with SpeedPack is now said so ("1.8x slower") instead of "About the same".

## SpeedPack Core 1.7.0

Two new parts, each off until switched on.

- **Page cache:** catalogue pages kept ready for visitors who are not signed in and have nothing in their cart, sent at the dispatcher before PrestaShop builds anything. The key holds the shop, address, language, currency, country, device and picture format, not campaign tags. Product, stock and price changes clear the product's page, its categories, its brand, the home page and the listings; category, CMS, brand, supplier, price rule changes, "Clear cache" and module installs clear everything. Gzipped files with an index table, an `X-SpeedPack-Cache` header on every answer, hits and pages kept on the settings page. The speed audit's requests never use it.
- **Optimize:** WebP and AVIF copies of the shop's pictures (made in steps from the settings page, at once for new product pictures, kept only when smaller), native lazy loading below the first screen with the main product picture first, critical CSS made in the admin's browser from real shop pages at computer and phone width, scripts at the end of the page deferred while keeping their order, minified HTML, and a `.htaccess` block for browser caching and gzip/Brotli (the nginx lines shown for nginx).
- Tests: the page cache through a real web server (built, kept, sent ready and gzipped, bypassed for carts and the audit), picture copies with GD, `.htaccess` written and restored, the critical CSS generator and the deferred scripts' order in a real Chromium.

## SpeedPack Core 1.6.3

- **A finished checkout step opens from a click anywhere on it**, not only on "edit": the title, the summary under it, an empty corner. It unfolds smoothly and comes into view; the whole step shows the hand and lights up on hover; the keyboard reaches it too (Tab, then Enter). Works with PrestaShop's own handler and without it. Part of the checkout summaries, so on by default.

## SpeedPack Core 1.6.2

- **The cart's products at the top of the checkout's side column:** picture, name, options, quantity × price and line total, five shown and "show all"; asked for again when the cart changes on the page (a product added from the side column).
- **Reorder's card:** the last order's products listed on the right (picture, name, quantity), the text and the button on the left; its icons carry their own size, so they stay small even when a stylesheet is late.
- **Fix:** after a quantity change on the cart page, the shipping line showed its price twice ("Za darmo! Za darmo!"): the small text PrestaShop's Classic keeps under the shipping price was overwritten. Only the line's own value changes now (InstantCart, and AsyncCart 1.0.1).
- **Fix:** after an update, a changed stylesheet of the module did not reach the shop while PrestaShop's "combine CSS" was on (its file is named after the list of files, not their content). The module now has PrestaShop make its combined CSS and JS again once after each update, and empties its page cache with it.
- tests/run.sh: a broken quote from 1.6.1 fixed; a new browser test for the cart quantities.

## SpeedPack Core 1.6.1

- **The path on every order and cart:** the back-office order page (displayAdminOrderMain from 1.7.7, displayAdminOrder before) and the cart page (placed at the top by a small script, on the old and the new page) show every visit behind them: summary tiles (visits, days to decide, pages, engaged time, where the shopper first came from, devices) and each visit page by page, with the time on each, cart additions, checkout steps, errors shown and the pay button. Visits now remember their cart; existing tables get the column on upgrade. A switch in Behaviour.
- **Checkout summaries:** each finished checkout step shows what it holds under its title (name and e-mail, the address and the invoice one when it differs, the carrier and its price), lined up with the title's words, hidden while the step is open. On by default, on every checkout.
- **Reorder's card restyled** to sit in the theme: a white card with an accent edge, the last order's product pictures, the theme's own button with a turning arrow, "same address and delivery · straight to payment", a full-width button on phones.

## SpeedPack Core 1.6.0

**Reorder**, a new part: "Order the same as last time?" for signed-in shoppers who have ordered before – a card on the home page and in an empty cart, a tile in the account. One tap puts the last order's products in the cart (leaving out, and naming, what is no longer sold or in stock), uses the same addresses and carrier, and saves the checkout steps as done the way PrestaShop's checkout does (with its cart checksum), so the checkout opens at payment. Off until switched on.

**Core Web Vitals in Behaviour:** LCP, INP, CLS, TTFB and FCP measured in shoppers' browsers (InstantNav swaps get INP and CLS; prerendered pages count from when they were shown), shown as the 75th percentile with good / needs-improvement / poor shares, by page and by device, and for each page of a visit. Tables from 1.5.0 get the new columns on upgrade.

Behaviour also records a tap on "Order the same as last time".

## SpeedPack Core 1.5.0

**Behaviour**, a new part: what shoppers do on the shop, page by page.
- A small script (2.9 KB gzipped) reports each page shown – InstantNav swaps included, prerendered pages only once shown – with engaged time and scroll depth, and add to cart, checkout steps, the pay button, errors and empty searches. The order hook marks the visit an order came from, with its total.
- The Behaviour tab: KPIs, visits over time in 15-minute, hour, day or week buckets, pages with engaged time, exits and conversion, time on a page, most taken routes, most common paths, paths of success (with time and pages to an order, and cart to order for new against returning shoppers), a funnel through every checkout step, and failure points (where carts were left, empty searches, 404s, errors). Filters for range, device, source, outcome and shopper; a search by product, category, page, address, sequence (`Kimchi > koszyk`) or customer; any visit page by page.
- No cookie of its own, no IP address, no browser string. Off until switched on; optional "only after analytics consent" and customer linking; visits kept 90 days by default.
- InstantNav tells listeners about a swapped page once its content is in (with a transition it used to be a frame early).

**The speed audit runs by itself** the first time the settings page opens after an install or an update, so the effect shows at once. The click test needs a shop window, which a browser opens only on a click: one press on "Measure the clicks too" adds it to the same audit.

**Ask for a custom audit of my site:** a button in the head of the settings page writes an e-mail to the author with the shop, its versions, the last audit and the health check.

**Share the speed** (opt-in, off by default): a small visible footer credit with the shop's measured speed-up (a nofollow link), and a "Site performance" section for llms.txt through the new `displayLlmsTxt` hook. Links carry UTM tags naming where they were placed.

## SpeedPack Core 1.4.1

- **InstantNav on phones:** a tap on a link in the phone menu left a blank page until a reload. Classic (and themes built on it) hides the page while its phone menu is open and shows it again only from its own menu button; InstantNav closed the menu panel but left the page hidden. The page, footer and notifications now come back with the menu closing, at the tap, so the loading placeholder shows too. Covered by a new phone-sized browser test.

## SpeedPack Core 1.4.0

**The speed audit now really compares configurations:**
- "Without SpeedPack" switches the data cache off for any backend (Redis, APCu, Memcached or PrestaShop's own), at the very start of the request (new `actionDispatcherBefore` hook), not only with Redis.
- Every audit answer carries an `X-SpeedPack-Audit` header with the parts the shop actually ran; the server test checks it on every request and the click test checks which scripts loaded in the shop window. Nothing is counted for a configuration that was not applied.
- Page caches in front of PrestaShop (cache modules, LiteSpeed, CDNs) are detected and reported instead of producing equal numbers on both sides; every audit address carries a fresh query string and audit answers are sent `no-store`.
- The click test clears the shop window's service worker, Cache Storage and session storage before every click, starts with an uncounted warm-up round and rotates the order of the configurations each round.

**A new settings page:** an Overview with every part's status, a one-click switch and the last audit's gains, and a tab for each part, the speed audit and the health check. The tab stays open after saving.

**Tests:** `tests/run.sh` runs the module's test suite: install and settings, AsyncCart, the data cache against Redis, the speed audit against a mock shop, the health check against MariaDB, and the audit and shop scripts in Chromium.

Polish translations for the new texts.

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
