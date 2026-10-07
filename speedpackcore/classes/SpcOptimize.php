<?php
/**
 * SpeedPack Core - Optimize: pictures, scripts, CSS and the server's headers.
 *
 *   WebP / AVIF   copies of product, category and brand pictures (classes/SpcImages.php), used
 *                 for browsers that take them
 *   Lazy loading  native, below the top of the page; the main product picture asked for first
 *   Defer scripts scripts at the end of the page wait for it (not on the cart and checkout)
 *   Critical CSS  made in the back office browser from real pages of the shop (views/js/optimize.js),
 *                 used while the theme's stylesheets are the ones it was made from
 *   Minify HTML   comments and runs of spaces out
 *   Headers       a marked block in .htaccess: browser caching for WebP, AVIF and fonts, combined
 *                 CSS and JS kept a year, gzip and Brotli for text (Apache, LiteSpeed)
 *
 * Every step runs on the page PrestaShop built (actionOutputHTMLBefore, classes/SpcHtml.php), so
 * the page cache keeps the result.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcOptimize extends SpcFeature
{
    public $id = 'optimize';

    public const K_ENABLED = 'SPC_OPT_ENABLED';
    public const K_WEBP = 'SPC_OPT_WEBP';
    public const K_AVIF = 'SPC_OPT_AVIF';
    public const K_QUALITY = 'SPC_OPT_QUALITY';
    public const K_LAZY = 'SPC_OPT_LAZY';
    public const K_DEFER = 'SPC_OPT_DEFER';
    public const K_CRITICAL = 'SPC_OPT_CRITICAL';
    public const K_MINIFY = 'SPC_OPT_MINIFY';
    public const K_HEADERS = 'SPC_OPT_HEADERS';

    /** pages with critical CSS of their own */
    public const CRITICAL_PAGES = ['index', 'category', 'product', 'cms'];

    /** pages whose scripts are left as they are: payment and sign-in modules are particular */
    public const NO_DEFER = ['cart', 'order', 'order-confirmation', 'authentication', 'registration', 'password', 'my-account', 'identity', 'address', 'addresses', 'module-'];

    public const HTACCESS_START = '# BEGIN SpeedPack Core (written by the module, removed when switched off)';
    public const HTACCESS_END = '# END SpeedPack Core';

    protected static function defaults()
    {
        return [self::K_ENABLED => 0, self::K_WEBP => 1, self::K_AVIF => 0, self::K_QUALITY => 82, self::K_LAZY => 1, self::K_DEFER => 0, self::K_CRITICAL => 1, self::K_MINIFY => 1, self::K_HEADERS => 0];
    }

    public function install()
    {
        foreach (self::defaults() as $k => $v) {
            if (!Configuration::updateValue($k, $v)) {
                return false;
            }
        }

        return $this->registerHooks();
    }

    public function registerHooks()
    {
        return $this->registerHook('actionOutputHTMLBefore')
            && $this->registerHook('actionWatermark')
            && $this->registerHook('actionObjectImageDeleteAfter');
    }

    public function uninstall()
    {
        self::headers(false);
        foreach (array_keys(self::defaults()) as $k) {
            Configuration::deleteByName($k);
        }
        foreach (self::CRITICAL_PAGES as $p) {
            Configuration::deleteByName(self::criticalKey($p));
            Configuration::deleteByName(self::criticalKey($p) . '_FP');
            Configuration::deleteByName(self::criticalKey($p) . '_AT');
        }

        return true;
    }

    /** A setting, with its default when it was never saved. */
    public static function on($key)
    {
        $v = Configuration::get($key);
        $d = self::defaults();

        return (int) ($v === false ? $d[$key] : $v) === 1;
    }

    public static function enabled()
    {
        return self::on(self::K_ENABLED);
    }

    /* ------------------------------------------------------------------ *
     *  The page on its way out
     * ------------------------------------------------------------------ */

    /** The picture formats this browser takes, best first, that are switched on. */
    public static function formatsFor($accept)
    {
        if (!self::enabled() || !self::on(self::K_WEBP)) {
            return [];
        }
        $can = SpcImages::formats();
        $out = [];
        if (self::on(self::K_AVIF) && $can['avif'] && stripos((string) $accept, 'image/avif') !== false) {
            $out[] = 'avif';
        }
        if (stripos((string) $accept, 'image/webp') !== false) {
            $out[] = 'webp';
        }

        return $out;
    }

    /** For the page cache: which picture format this browser gets ('' for the originals). */
    public static function imageFormat()
    {
        $f = self::formatsFor(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '');

        return $f ? $f[0] : '';
    }

    /** Every switched-on step, in order, on a page PrestaShop built. */
    public function transform($html, $controller)
    {
        if (!self::enabled() || !is_string($html) || $html === '') {
            return $html;
        }
        $formats = self::formatsFor(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '');
        if ($formats) {
            $base = $this->context->shop->getBaseURL(true);
            $host = (string) parse_url($base, PHP_URL_HOST);
            $html = SpcHtml::images($html, function ($url) use ($formats, $base, $host) {
                return SpcImages::resolve($url, $formats, $base, $host);
            });
        }
        if (self::on(self::K_LAZY)) {
            $html = SpcHtml::lazy($html);
        }
        $page = (string) $controller;
        $noDefer = false;
        foreach (self::NO_DEFER as $p) {
            $noDefer = $noDefer || $page === $p || ($p === 'module-' && strpos($page, 'module-') === 0);
        }
        if (self::on(self::K_DEFER) && !$noDefer) {
            $html = SpcHtml::defer($html);
        }
        if (self::on(self::K_CRITICAL) && in_array($page, self::CRITICAL_PAGES, true) && !Tools::getValue('spc_nocrit')) {
            $html = SpcHtml::critical($html, self::criticalCss($page), (string) Configuration::get(self::criticalKey($page) . '_FP'));
        }
        if (self::on(self::K_MINIFY)) {
            $html = SpcHtml::minify($html);
        }

        return $html;
    }

    /* ------------------------------------------------------------------ *
     *  Pictures
     * ------------------------------------------------------------------ */

    /** The formats to make, as switched on and as the server can. */
    public static function formatsToMake()
    {
        $can = SpcImages::formats();
        $out = [];
        if (self::on(self::K_WEBP) && $can['webp']) {
            $out[] = 'webp';
        }
        if (self::on(self::K_AVIF) && $can['avif']) {
            $out[] = 'avif';
        }

        return $out;
    }

    /** New product pictures (after PrestaShop made their sizes): their copies at once. */
    public function hookActionWatermark($params)
    {
        if (!self::enabled() || empty($params['id_image'])) {
            return;
        }
        foreach (SpcImages::productFiles((int) $params['id_image']) as $file) {
            foreach (self::formatsToMake() as $format) {
                SpcImages::convert($file, $format, (int) Configuration::get(self::K_QUALITY) ?: 82);
            }
        }
    }

    public function hookActionObjectImageDeleteAfter($params)
    {
        if (!empty($params['object']) && !empty($params['object']->id)) {
            SpcImages::forget((int) $params['object']->id);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Critical CSS
     * ------------------------------------------------------------------ */

    /** A page's critical CSS as made (older ones, kept as HTML, have their entities undone). */
    public static function criticalCss($page)
    {
        $v = (string) Configuration::get(self::criticalKey($page));
        if (strpos($v, 'b64:') === 0) {
            return (string) base64_decode(substr($v, 4));
        }

        return html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function criticalKey($page)
    {
        return 'SPC_OPT_CRIT_' . strtoupper(str_replace('-', '_', $page));
    }

    /** Critical CSS made in the back office browser for one kind of page. */
    public static function saveCritical($page, $css, array $hrefs)
    {
        if (!in_array($page, self::CRITICAL_PAGES, true) || !$hrefs) {
            return ['error' => 'page'];
        }
        $css = trim((string) $css);
        if ($css === '') {
            return ['error' => 'empty'];
        }
        // kept encoded (base64 fits in the configuration's text column up to about 46 kB)
        if (strlen($css) > 46000) {
            return ['error' => 'too large', 'bytes' => strlen($css)];
        }
        // encoded, so PrestaShop's HTML cleaning never touches it (it turned ">" into "&gt;")
        Configuration::updateValue(self::criticalKey($page), 'b64:' . base64_encode($css));
        Configuration::updateValue(self::criticalKey($page) . '_FP', SpcHtml::fingerprint($hrefs));
        Configuration::updateValue(self::criticalKey($page) . '_AT', date('Y-m-d H:i'));

        return ['ok' => true, 'bytes' => strlen($css)];
    }

    /** Pages to make critical CSS from: the home page, a category, a product, a CMS page. */
    public function criticalPlan()
    {
        $ctx = $this->context;
        $idLang = (int) $ctx->language->id;
        $idShop = (int) $ctx->shop->id;
        $db = Db::getInstance();
        $urls = ['index' => $ctx->link->getPageLink('index', true)];
        $cat = (int) $db->getValue('SELECT c.id_category FROM `' . _DB_PREFIX_ . 'category` c INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . ')
            INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp ON cp.id_category = c.id_category
            WHERE c.active = 1 AND c.id_category NOT IN (' . (int) Configuration::get('PS_ROOT_CATEGORY') . ', ' . (int) Configuration::get('PS_HOME_CATEGORY') . ')
            GROUP BY c.id_category ORDER BY COUNT(cp.id_product) DESC');
        if ($cat) {
            $urls['category'] = $ctx->link->getCategoryLink($cat, null, $idLang);
        }
        $product = (int) $db->getValue('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product_shop` p WHERE p.id_shop = ' . $idShop . ' AND p.active = 1 AND p.visibility IN (\'both\', \'catalog\') ORDER BY p.id_product');
        if ($product) {
            $urls['product'] = $ctx->link->getProductLink($product, null, null, null, $idLang);
        }
        $cms = (int) $db->getValue('SELECT c.id_cms FROM `' . _DB_PREFIX_ . 'cms` c INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON (cs.id_cms = c.id_cms AND cs.id_shop = ' . $idShop . ') WHERE c.active = 1 ORDER BY c.id_cms');
        if ($cms) {
            $urls['cms'] = $ctx->link->getCMSLink($cms, null, null, $idLang);
        }
        foreach ($urls as $k => $u) {
            $urls[$k] = $u . (strpos($u, '?') === false ? '?' : '&') . 'spc_nocrit=1&spc_nocache=1';
        }

        return $urls;
    }

    /* ------------------------------------------------------------------ *
     *  Headers (.htaccess)
     * ------------------------------------------------------------------ */

    public static function htaccessBlock()
    {
        return self::HTACCESS_START . "\n" . <<<'HT'
<IfModule mod_mime.c>
    AddType image/webp .webp
    AddType image/avif .avif
    AddType font/woff2 .woff2
</IfModule>
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/webp "access plus 1 month"
    ExpiresByType image/avif "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/svg+xml "access plus 1 month"
    ExpiresByType font/woff2 "access plus 1 year"
    ExpiresByType font/woff "access plus 1 year"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
    ExpiresByType text/javascript "access plus 1 week"
</IfModule>
<IfModule mod_headers.c>
    # combined CSS and JS change name with their content: kept a year
    <FilesMatch "^(theme|bottom|head)-[0-9a-f]+\.(css|js)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
<IfModule mod_brotli.c>
    AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/xml application/xml application/json application/ld+json application/javascript text/javascript image/svg+xml font/ttf font/otf
</IfModule>
<IfModule mod_deflate.c>
    <IfModule mod_filter.c>
        AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml application/xml application/json application/ld+json application/javascript text/javascript image/svg+xml font/ttf font/otf application/vnd.ms-fontobject
    </IfModule>
</IfModule>
HT
            . "\n" . self::HTACCESS_END;
    }

    public static function nginxSnippet()
    {
        return <<<'NG'
# SpeedPack Core: in the server { } block of the shop
location ~* \.(webp|avif|jpe?g|png|gif|svg|ico)$ { expires 30d; add_header Cache-Control "public"; }
location ~* /assets/cache/.+\.(css|js)$ { expires 1y; add_header Cache-Control "public, immutable"; }
location ~* \.(woff2?|ttf|otf|eot)$ { expires 1y; add_header Cache-Control "public, immutable"; }
gzip on;
gzip_types text/css application/javascript application/json application/ld+json image/svg+xml text/xml application/xml font/ttf font/otf;
# with the ngx_brotli module:
# brotli on; brotli_types text/css application/javascript application/json image/svg+xml text/xml application/xml font/ttf font/otf;
NG;
    }

    public static function htaccessPath()
    {
        return SpcImages::root() . '.htaccess';
    }

    /** Whether the block is in .htaccess. */
    public static function headersWritten()
    {
        $f = self::htaccessPath();

        return is_file($f) && strpos((string) file_get_contents($f), self::HTACCESS_START) !== false;
    }

    /**
     * The block in or out of .htaccess, before PrestaShop's own part (which PrestaShop keeps when
     * it writes the file again). The first change keeps a copy as .htaccess.speedpackcore.bak.
     *
     * @return bool
     */
    public static function headers($on)
    {
        $f = self::htaccessPath();
        $content = is_file($f) ? (string) file_get_contents($f) : '';
        $clean = preg_replace('#\n?' . preg_quote(self::HTACCESS_START, '#') . '.*?' . preg_quote(self::HTACCESS_END, '#') . "\n*#s", '', $content);
        $new = $on ? self::htaccessBlock() . "\n\n" . ltrim($clean) : ltrim($clean);
        if ($new === $content) {
            return true;
        }
        if ((is_file($f) && !is_writable($f)) || (!is_file($f) && !is_writable(dirname($f)))) {
            return false;
        }
        if (is_file($f) && !is_file($f . '.speedpackcore.bak')) {
            @copy($f, $f . '.speedpackcore.bak');
        }

        return @file_put_contents($f, $new) !== false;
    }

    public static function server()
    {
        $s = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';
        if (strpos($s, 'nginx') !== false) {
            return 'nginx';
        }
        if (strpos($s, 'litespeed') !== false) {
            return 'litespeed';
        }

        return strpos($s, 'apache') !== false ? 'apache' : 'other';
    }

    /* ------------------------------------------------------------------ *
     *  Back office
     * ------------------------------------------------------------------ */

    public function summary()
    {
        $on = self::enabled();
        $parts = [];
        foreach ([self::K_WEBP => 'WebP', self::K_LAZY => $this->l('lazy loading'), self::K_DEFER => $this->l('deferred scripts'), self::K_MINIFY => $this->l('minified HTML')] as $k => $name) {
            if (self::on($k)) {
                $parts[] = $name;
            }
        }
        $crit = 0;
        foreach (self::CRITICAL_PAGES as $p) {
            $crit += Configuration::get(self::criticalKey($p)) ? 1 : 0;
        }
        if (self::on(self::K_CRITICAL) && $crit) {
            $parts[] = sprintf($this->l('critical CSS for %d kinds of page'), $crit);
        }

        return ['on' => $on, 'status' => $on ? $this->l('On') : $this->l('Off'),
            'fact' => $on && $parts ? ucfirst(implode(', ', $parts)) . '.' : $this->l('Smaller pictures, lazy loading, critical CSS, deferred scripts.'), ];
    }

    public function ajax($op)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        @set_time_limit(120);
        switch ($op) {
            case 'images':
                $answer = SpcImages::step((int) Tools::getValue('offset'), 15, self::formatsToMake(), (int) Configuration::get(self::K_QUALITY) ?: 82);
                break;
            case 'critical_plan':
                $answer = ['pages' => $this->criticalPlan()];
                break;
            case 'critical_save':
                $hrefs = json_decode((string) Tools::getValue('hrefs'), true);
                $answer = self::saveCritical((string) Tools::getValue('page'), (string) Tools::getValue('css'), is_array($hrefs) ? $hrefs : []);
                if (!empty($answer['ok'])) {
                    SpcPageCache::flush();
                }
                break;
            default:
                $answer = ['error' => 'unknown'];
        }
        echo json_encode($answer);
        exit;
    }

    public function getContent()
    {
        $out = '';
        if (!$this->isRegisteredInHook('actionOutputHTMLBefore') || !$this->isRegisteredInHook('actionWatermark')) {
            $this->registerHooks();
        }
        $keys = [self::K_ENABLED, self::K_WEBP, self::K_AVIF, self::K_LAZY, self::K_DEFER, self::K_CRITICAL, self::K_MINIFY, self::K_HEADERS];
        if (Tools::isSubmit('submitSpcOptimize')) {
            $q = (int) Tools::getValue(self::K_QUALITY);
            if ($q < 40 || $q > 95) {
                $out .= $this->displayError($this->l('Picture quality from 40 to 95.'));
            } else {
                foreach ($keys as $k) {
                    Configuration::updateValue($k, Tools::getValue($k) ? 1 : 0);
                }
                Configuration::updateValue(self::K_QUALITY, $q);
                $headers = self::enabled() && self::on(self::K_HEADERS);
                if (!self::headers($headers)) {
                    Configuration::updateValue(self::K_HEADERS, self::headersWritten() ? 1 : 0);
                    $out .= $this->displayError($this->l('.htaccess could not be written, so the server headers were left as they were. The other settings were saved.'));
                } else {
                    $out .= $this->displayConfirmation($this->l('Settings updated; the page cache was emptied.'));
                }
                SpcPageCache::flush();
            }
        }
        if (Tools::isSubmit('submitSpcCriticalClear')) {
            foreach (self::CRITICAL_PAGES as $p) {
                Configuration::deleteByName(self::criticalKey($p));
                Configuration::deleteByName(self::criticalKey($p) . '_FP');
                Configuration::deleteByName(self::criticalKey($p) . '_AT');
            }
            SpcPageCache::flush();
            $out .= $this->displayConfirmation($this->l('Critical CSS removed.'));
        }

        $this->context->controller->addCSS($this->module->getPathUri() . 'views/css/optimize.css');
        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/optimize.js');
        $can = SpcImages::formats();
        $critical = [];
        $names = ['index' => $this->l('Home page'), 'category' => $this->l('Category'), 'product' => $this->l('Product'), 'cms' => $this->l('CMS page')];
        foreach (self::CRITICAL_PAGES as $p) {
            $css = self::criticalCss($p);
            $critical[] = ['page' => $p, 'name' => $names[$p], 'kb' => $css !== '' ? round(strlen($css) / 1024, 1) : 0, 'at' => (string) Configuration::get(self::criticalKey($p) . '_AT')];
        }
        $out .= $this->render('admin/optimize.tpl', ['spc_opt' => [
            'url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&spc_ajax=optimize',
            'webp' => $can['webp'], 'avif' => $can['avif'],
            'critical' => $critical,
            'server' => self::server(),
            'headers' => self::headersWritten(),
            'nginx' => self::nginxSnippet(),
            'texts' => json_encode([
                'converting' => $this->l('Converting: %1$d of %2$d images'),
                'converted' => $this->l('Done: %1$d copies made, %2$s smaller.'),
                'none' => $this->l('Nothing to convert: every picture has its copies.'),
                'generating' => $this->l('Reading %s...'),
                'generated' => $this->l('Critical CSS made for %d kinds of page.'),
                'blocked' => $this->l('The shop pages could not be read from the back office (is the back office on another address than the shop?).'),
                'failed' => $this->l('Stopped: %s'),
                'names' => $names,
            ]),
        ]]);

        $switch = function ($name, $label, $desc) {
            return ['type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]], ];
        };
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitSpcOptimize';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach ($keys as $k) {
            $helper->fields_value[$k] = self::on($k) ? 1 : 0;
        }
        $helper->fields_value[self::K_QUALITY] = (int) Configuration::get(self::K_QUALITY) ?: 82;

        return $out . $helper->generateForm([['form' => [
            'id_form' => 'spc-optimize',
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-picture'],
            'input' => [
                $switch(self::K_ENABLED, $this->l('Optimize pages'), $this->l('The switches below act only while this is on.')),
                $switch(self::K_WEBP, $this->l('WebP pictures'), $this->l('Browsers that take WebP get the WebP copy of a picture (about a third smaller). Make the copies with "Convert pictures" above; new product pictures get theirs at once.')),
                $switch(self::K_AVIF, $this->l('AVIF pictures'), $can['avif'] ? $this->l('Smaller still, for browsers that take AVIF; slower to make.') : $this->l('This server cannot write AVIF (PHP 8.1 with GD built with AVIF is needed).')),
                ['type' => 'text', 'name' => self::K_QUALITY, 'label' => $this->l('Picture quality'), 'class' => 'fixed-width-sm', 'desc' => $this->l('40 to 95; 82 looks the same as the original to most eyes.')],
                $switch(self::K_LAZY, $this->l('Lazy loading'), $this->l('Pictures and videos below the top of the page load as they come into view; the main product picture is asked for first.')),
                $switch(self::K_CRITICAL, $this->l('Critical CSS'), $this->l('The CSS for the top of the page inline, the rest loaded without holding up the first paint. Made with "Make critical CSS" above; used only while the theme stylesheets stay as they were.')),
                $switch(self::K_DEFER, $this->l('Defer scripts'), $this->l('Scripts at the end of the page wait for it, in their order. Not on the cart, checkout and account pages. Check the shop after switching it on: a module that writes into the page while it loads may need it off.')),
                $switch(self::K_MINIFY, $this->l('Minify HTML'), $this->l('Comments and runs of spaces out of the page (never in scripts, styles, pre or text areas).')),
                $switch(self::K_HEADERS, $this->l('Server headers'), self::server() === 'nginx' ? $this->l('This shop runs on nginx: copy the lines shown above into its configuration instead.') : $this->l('Writes a marked block to .htaccess (Apache, LiteSpeed): browser caching for WebP, AVIF and fonts, combined CSS and JS kept a year, gzip and Brotli. A copy of the file is kept as .htaccess.speedpackcore.bak.')),
            ],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }
}
