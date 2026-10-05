<p align="center"><img src="media/cover.png" alt="SpeedPack Core – five speed-ups for PrestaShop in one module" width="100%"></p>

<p align="center">
  <a href="dist/speedpackcore-1.1.1.zip"><img alt="Download 1.1.0" src="https://img.shields.io/badge/download-speedpackcore--1.1.1.zip-1f7a72?style=for-the-badge"></a>
</p>
<p align="center">
  <img alt="PrestaShop 1.7.6 – 8.x" src="https://img.shields.io/badge/PrestaShop-1.7.6%20%E2%80%93%208.x-df0067">
  <img alt="PHP 7.1+" src="https://img.shields.io/badge/PHP-7.1%2B-777bb4">
  <img alt="Version 1.1.1" src="https://img.shields.io/badge/version-1.1.1-17201e">
  <img alt="License MIT" src="https://img.shields.io/badge/license-MIT-17201e">
  <img alt="No dependencies" src="https://img.shields.io/badge/dependencies-0-17201e">
</p>

# SpeedPack Core

**Make your PrestaShop shop feel instant.** Database results come from memory, the next page loads before the click, menu clicks swap the content without a reload, the cart reacts at once and the cart page runs 95% fewer repeated queries. Five speed-ups, one module, each with its own switch.

<p align="center"><img src="media/speedpack.gif" alt="SpeedPack Core in 13 seconds" width="100%"></p>
<p align="center"><a href="media/speedpack.mp4">▶ Watch in full quality (mp4, 13 s)</a></p>

| Part | What it removes | The number |
|---|---|---|
| **Cache** | Waiting for the database | Query results kept in **Redis, APCu or Memcached** |
| **SmartPrefetch** | Waiting for the next page | Starts downloading **65 ms** after a hover |
| **InstantNav** | The white flash between pages | **0** white screens; the header never reloads |
| **InstantCart** | Waiting after "Add to cart" and +/− | **20 clicks → 1 request** (4.75 s → 1.73 s on a slow server) |
| **CartSpeed** | Repeated queries on the cart page | **73 → 4** address lookups per cart page |

<sub>InstantCart and CartSpeed figures were measured on the Alhambra shop. SmartPrefetch and InstantNav figures are the modules' default settings.</sub>

## Why merchants use it

- **Shoppers browse further** when every page answers at once: no white flash between pages, no waiting after "Add to cart".
- **Less work for your server:** database results come from memory, quick cart clicks are merged into one request, and the cart page skips dozens of identical queries.
- **Install and go:** sensible defaults, one settings page, a separate switch for each part, no theme files to edit.
- **Safe by design:** a cache is switched on only after it passes a live test, pages that change something (cart, checkout, account, log out) are never fetched ahead, and any error falls back to the normal page load.

## What shoppers notice

- Pages open the moment they click: the next page is already downloaded while the pointer rests on the link.
- The header, menu and cart stay in place between pages, with no white flash.
- "Add to cart", removing a line and changing a quantity all respond instantly, even on a slow phone connection.

## Features

### Cache – database results from memory · *new in 1.1*

- Data cache in **Redis**, **APCu** or **Memcached**, whichever your server has
- Switched on only after a live test: connect, password, database, write and read. A wrong host or password is refused and the shop stays as it was
- If the cache server goes down later, the shop keeps working without it, slower but never an error page
- Every Redis key carries a prefix of its own, so **Empty the cache** never touches another site on the same server
- **OPcache panel:** hit rate, memory and files, with plain advice on what to ask your host for
- **PrestaShop speed settings** in one place: template compiling, template cache, combined CSS and JavaScript, browser caching
- **Warm-up:** after emptying, the module visits the home page, every category and the best-selling products, so no customer gets the slow first load

### SmartPrefetch – the next page before the click

<img src="media/scene-smartprefetch.png" alt="SmartPrefetch: the next page is fetched on hover" width="100%">

- Starts downloading a page 65 ms after the pointer rests on its link, or a finger touches it
- At most 12 fetches per page, and up to 3 main-menu or slider links warmed up on the first page of a visit
- Never fetches cart, checkout, account or log-out pages, or links that add, delete or carry a token
- Stands down when the visitor has Data Saver on or a 2G connection
- Fetched pages are kept 60 s in a service-worker cache, separately for signed-in and signed-out shoppers

### InstantNav – menu clicks without a reload

<img src="media/before-after.gif" alt="Regular PrestaShop flashes white on every menu click; with InstantNav only the products change" width="100%">

- Swaps only the page content for the links you choose (default: the main menu, desktop and mobile)
- The header, menu and cart never reload, so there is no white flash
- A loading outline in your theme's own colours, shown only if a page takes longer than 140 ms
- Transitions: none, fade, fade and rise, fade and settle; uses the View Transitions API where the browser has it, and respects "reduced motion"
- Back and forward buttons, page title, focus and scroll all work; scripts in the new content run as usual

### InstantCart – the cart without waiting

<img src="media/scene-instantcart.png" alt="InstantCart: 20 clicks, 1 request" width="100%">

- The cart count goes up the moment the shopper clicks; a lean endpoint saves the product in the background
- Quick clicks are merged: 20 clicks reach the shop as 1 request
- "Add to cart" buttons on category lists, search results and the home page, for products that need no choice
- Instant removal on the cart page, with Undo
- **Instant quantity change on the cart page** · *new in 1.1*: +, − and a typed number change the line at once; the shop hears the final quantity once, and refusals (stock, minimum) come back with the shop's own message
- Anything uncertain, such as a required customisation, falls back to PrestaShop's own add to cart, so nothing gets lost

### CartSpeed – a lighter cart page

<img src="media/scene-cartspeed.png" alt="CartSpeed: 73 to 4 queries" width="100%">

- PrestaShop checks "does this address exist?" for every price and tax in the cart; CartSpeed remembers the answer for the rest of the page
- 73 identical queries → 4 on one cart page (measured), with an on/off switch

## Also in the pack: AsyncCart

<img src="media/asynccart-logo.png" alt="" width="64" align="left">

**Just the instant cart page, as a module of its own.** For shops that want only this part, without the rest of SpeedPack Core. **[asynccart-1.0.0.zip](dist/asynccart-1.0.0.zip)** · source in [`asynccart/`](asynccart/)

<br clear="left">

- **Quantity:** +, − and a typed number change the line and the header count at once; clicks within the wait (400 ms by default, 100–2000 ms) reach the shop as one request with the final quantity
- **Remove:** the line slides away at once, with **Undo** for 5 seconds, even after the shop has deleted it; typing 0 removes too
- **Totals** come back from lean endpoints and are updated in place; the page re-renders only when vouchers change or the cart empties
- **Refusals** (stock, minimum quantity) put the number back with the shop's own message
- The theme's own +/− handlers never see these clicks, so nothing runs twice
- **Stands aside** when SpeedPack Core's InstantCart already handles the cart page, so two modules never answer one click

<p align="center"><img src="media/asynccart-cards.png" alt="AsyncCart's message cards: Removed from cart with Undo, and Quantity not changed" width="70%"></p>

## Installation

1. Download **[speedpackcore-1.1.1.zip](dist/speedpackcore-1.1.1.zip)**.
2. In the back office, go to **Modules > Module Manager > Upload a module** and choose the zip.
3. Click **Install**. SmartPrefetch, InstantNav, InstantCart and CartSpeed are switched on with their defaults; the data cache stays off until you choose one.
4. Click **Configure**: each part has a status panel, its settings and its switch. To use Redis, enter its host and password under **Cache** and press **Save and test**.
5. Open your shop and check: hover a menu link and click it (no white flash), add a product from a category page (the count goes up at once), change a quantity in the cart.

> [!NOTE]
> - If you had the separate SmartPrefetch, InstantNav, InstantCart or CartSpeed modules, uninstall them first.
> - CartSpeed overrides `Address::addressExists()`. If another module already overrides it, PrestaShop shows a conflict on install.
> - Redis needs overrides: leave "Disable all overrides" off under Advanced Parameters > Performance.
> - On a theme that is not based on Classic, set InstantNav's "Links swapped" and "Region replaced" selectors to match your theme.

## Technical details

| | |
|---|---|
| **Compatibility** | PrestaShop 1.7.6.0 to 8.x, PHP 7.1 or newer, multistore |
| **Hooks** | `actionFrontControllerSetMedia`, `displayHeader`, `displayProductListReviews` |
| **Overrides** | `Address::addressExists()`, installed and removed with the module. With Redis on, the module also writes `override/classes/cache/CacheRedis.php` |
| **Files it changes** | With a data cache on, `app/config/parameters.php` (cache entries only; the original is kept as `parameters.php.speedpackcore.bak`) |
| **Front controllers** | `add`, `remove`, `qty` (InstantCart endpoints) |
| **Database** | No new tables; its configuration values are all removed on uninstall |
| **Privacy** | No cookies and no personal data stored by the module. In the browser: two `sessionStorage` keys and a cache of shop pages kept 60 seconds, separate for each signed-in shopper |
| **Requirements** | HTTPS for the service worker, and the `Service-Worker-Allowed` header that the module's `.htaccess` sends on Apache and LiteSpeed (without them, SmartPrefetch uses plain prefetch hints). The Redis, APCu or Memcached PHP extension for the data cache. Plain JavaScript, about 22 KB gzipped in total |

## Source

SpeedPack Core lives in [`speedpackcore/`](speedpackcore/) and AsyncCart in [`asynccart/`](asynccart/); installable zips are in [`dist/`](dist/). See the [changelog](CHANGELOG.md).

## Other languages

<details>
<summary><b>Français</b></summary>

**Votre boutique devient instantanée.** Les résultats de la base de données viennent de la mémoire, la page suivante se charge avant le clic, le menu change de page sans rechargement, le panier réagit tout de suite et la page panier fait 95 % de requêtes répétées en moins.

- **Cache :** Redis, APCu ou Memcached, activé seulement après un test réel (connexion, mot de passe, écriture et lecture) ; panneau OPcache, réglages de performance de PrestaShop, vidage et préchauffage du cache.
- **SmartPrefetch :** télécharge la page 65 ms après le survol du lien ; ne précharge jamais le panier, la commande, le compte ni la déconnexion.
- **InstantNav :** le menu remplace seulement le contenu ; l'en-tête, le menu et le panier restent en place, sans écran blanc.
- **InstantCart :** ajout, suppression et changement de quantité immédiats ; 20 clics rapides = 1 requête.
- **CartSpeed :** 73 requêtes identiques → 4 sur une page panier.

**Installation :** Modules > Gestionnaire de modules > Installer un module, choisissez `speedpackcore-1.1.1.zip`, puis Configurer.
</details>

<details>
<summary><b>Polski</b></summary>

**Sklep działa od ręki.** Wyniki z bazy danych przychodzą z pamięci, następna strona ładuje się przed kliknięciem, menu przełącza strony bez przeładowania, koszyk reaguje natychmiast, a strona koszyka wysyła o 95% mniej powtarzanych zapytań.

- **Cache:** Redis, APCu lub Memcached, włączany dopiero po prawdziwym teście (połączenie, hasło, zapis i odczyt); panel OPcache, ustawienia szybkości PrestaShop, czyszczenie i rozgrzewanie cache.
- **SmartPrefetch:** pobiera stronę 65 ms po najechaniu na link; nigdy nie pobiera koszyka, zamówienia, konta ani wylogowania.
- **InstantNav:** menu podmienia tylko treść; nagłówek, menu i koszyk zostają na miejscu, bez białego ekranu.
- **InstantCart:** dodawanie, usuwanie i zmiana ilości od razu; 20 szybkich kliknięć = 1 zapytanie.
- **CartSpeed:** 73 identyczne zapytania → 4 na stronie koszyka.

**Instalacja:** Moduły > Menedżer modułów > Załaduj moduł, wybierz `speedpackcore-1.1.1.zip`, potem Konfiguruj.
</details>

---

<sub>Made by **Alhambra** for our own PrestaShop store · MIT License · See also [Prestashop-InstantNav](https://github.com/mateusz-stelmasiak/Prestashop-InstantNav) and [Prestashop-SmartPrefetch](https://github.com/mateusz-stelmasiak/Prestashop-SmartPrefetch)</sub>
