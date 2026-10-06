# SpeedPack Core tests

```sh
cd tests && composer install   # Smarty, to render the module's own templates
./run.sh                        # everything that can run here
./run.sh --strict               # the same, but a skipped suite is a failure
```

`run.sh` lints every PHP and JavaScript file, starts the mock shops on free ports, runs the suites below and exits non-zero on any failure. The suites need no PrestaShop: they load the module's real classes and templates against small stand-ins for the PrestaShop classes they call.

| Suite | What it checks | Needs |
|---|---|---|
| `php/unit.php` | Install and uninstall, hooks, upgrades, the settings page: tabs, panes in order, the overview cards, one-click switches, the tab that stays open after saving, Behaviour's settings | PHP |
| `php/asynccart.php` | AsyncCart: install, script only on the cart page, standing aside when SpeedPack Core's InstantCart handles the cart, settings validation, the quantity endpoint (stock, minimum, fallbacks) and Undo | PHP |
| `php/cache.php` | The data cache against a real Redis: settings, `parameters.php` written and restored, the override, hit rate | Redis, phpredis |
| `php/audit.php` | The speed audit: the signed cookie, `SpcAudit::apply()` (data cache off, `X-SpeedPack-Audit` header), each server step against `php/mock/audit-shop.php`, the page-cache detection, saving and history | PHP, curl |
| `php/health.php` | The health check against a real MariaDB: every check, every database-care cleanup (what goes and what stays), ANALYZE, module weight against `php/mock/weight-shop.php`, and the whole settings page rendered with Smarty | MariaDB / MySQL |
| `browser/audit.e2e.js normal` | The audit's click test in Chromium: every configuration really applied, the shop window cleared before each click, warm-up and rotated rounds | Node, Playwright |
| `browser/audit.e2e.js pagecache` | The same behind a page cache: nothing counted, the warning shown | Node, Playwright |
| `browser/shop.e2e.js` | InstantNav refuses pages with active content and swaps clean ones; SmartPrefetch's prefetch and prerender rules, the cart left out | Node, Playwright |
| `php/behaviour.php` | Behaviour against a real MariaDB: recording (retried beacons, the 30-minute gap, junk), sources, and every report figure for scripted visits: KPIs, local time buckets, pages, routes, paths, paths of success, funnel, failure points, search, filters, one visit, clean-up | MariaDB / MySQL |
| `php/reorder.php` | Reorder against a real MariaDB with real Smarty: the last valid order, what goes into the cart and what is left out, addresses, the carrier as it is now, the checkout saved to open at payment (with the cart checksum), where the card shows, the card, settings | MariaDB / MySQL, Smarty |
| `browser/reorder.e2e.js` | Reorder's card and the checkout summaries in Chromium, styled like a Classic-based theme, on a computer and a phone | Node, Playwright |
| `browser/journey.e2e.js` | The path panel on an order page and on a cart page (old and new layout), placed and styled, nothing spilling out | Node, Playwright |
| `browser/cart.e2e.js` | The cart page's instant quantity change (InstantCart and AsyncCart) on Classic's markup: one request, the summary follows, each value once | Node, Playwright |
| `browser/behaviour.e2e.js` | The tracker in Chromium (views, InstantNav swaps, engaged and idle time, cart, checkout steps, pay, errors, searches, campaign tags; nothing from robots, before consent or while prerendered) and the Behaviour tab drawing a real report | Node, Playwright |
| `php/behaviour-replay.php` | What the tracker sent in Chromium, stored by the real store: every view and event kept | MariaDB / MySQL |

## Settings

| Variable | Default | |
|---|---|---|
| `SPC_SMARTY` | `tests/vendor/autoload.php` | Smarty's autoloader, if installed elsewhere |
| `SPC_ROOT` | the folder above `tests/` | The folder holding `speedpackcore/` and `asynccart/` |
| `REDIS_PORT`, `REDIS_PASS` | `6390`, `s3cret` | A throw-away Redis for `cache.php` (it writes and may flush keys) |
| `SPC_DB_DSN`, `SPC_DB_USER`, `SPC_DB_PASS` | `mysql:host=localhost;dbname=spctest`, `lp`, `lp` | An empty database for `health.php` (it creates `ps_` tables) |
| `SPC_PLAYWRIGHT` | `playwright` | Where to `require()` Playwright from |

A throw-away Redis and database:

```sh
redis-server --port 6390 --requirepass s3cret --daemonize yes
mysql -e "CREATE DATABASE spctest; CREATE USER lp@localhost IDENTIFIED BY 'lp'; GRANT ALL ON spctest.* TO lp@localhost;"
```
