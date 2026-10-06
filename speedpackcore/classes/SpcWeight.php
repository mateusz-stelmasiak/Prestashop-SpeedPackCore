<?php
/**
 * SpeedPack Core - module weight: what each module costs the shop's pages (devdocs: Scale >
 * Optimizations, "modules hooked on too many pages").
 *
 * Two numbers per module, both measured, not guessed:
 *   - the front-office hooks it is registered on (each runs its code on the pages that show it)
 *   - the stylesheets and scripts it puts on the home page and a product page, with their size,
 *     read from those pages as a first-time visitor gets them
 * With "combine CSS / JavaScript" on, the files are merged and can no longer be told apart; the
 * hooks are still counted.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcWeight
{
    public const MAX_ASSETS = 80;

    /** Hooks that run on front-office pages. */
    public static function frontHook($name)
    {
        $n = Tools::strtolower($name);
        if (strpos($n, 'displayadmin') === 0 || strpos($n, 'displaybackoffice') === 0 || strpos($n, 'displaydashboard') === 0) {
            return false;
        }

        return strpos($n, 'display') === 0 || in_array($n, ['header', 'top', 'footer', 'actionfrontcontrollersetmedia', 'actionfrontcontrollerinitbefore',
            'actionfrontcontrollerinitafter', 'actiondispatcher', 'actionproductsearchafter', 'actionoutputhtmlbefore', 'filterproductcontent'], true);
    }

    /** @return array module name => [hooks => [names]] for the modules on in this shop */
    public static function hooks($idShop)
    {
        $rows = Db::getInstance()->executeS('SELECT m.name AS module, h.name AS hook FROM `' . _DB_PREFIX_ . 'hook_module` hm
            INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON (h.id_hook = hm.id_hook)
            INNER JOIN `' . _DB_PREFIX_ . 'module` m ON (m.id_module = hm.id_module AND m.active = 1)
            INNER JOIN `' . _DB_PREFIX_ . 'module_shop` ms ON (ms.id_module = m.id_module AND ms.id_shop = ' . (int) $idShop . ')
            WHERE hm.id_shop = ' . (int) $idShop);
        $out = [];
        foreach ((array) $rows as $r) {
            if (self::frontHook($r['hook'])) {
                $out[$r['module']][] = $r['hook'];
            }
        }

        return $out;
    }

    /** The stylesheet and script addresses of a page. */
    public static function assets($html, $base)
    {
        $urls = [];
        if (preg_match_all('/<link\b[^>]*>/i', $html, $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('/\brel=["\']?stylesheet/i', $tag) && preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $h)) {
                    $urls[] = ['css', $h[1]];
                }
            }
        }
        if (preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
            foreach ($m[1] as $src) {
                $urls[] = ['js', $src];
            }
        }
        $out = [];
        foreach ($urls as list($type, $url)) {
            $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
            if (strpos($url, '//') === 0) {
                $url = 'https:' . $url;
            } elseif (!preg_match('#^https?://#i', $url)) {
                $url = rtrim($base, '/') . '/' . ltrim($url, '/');
            }
            $out[$url] = $type;
        }

        return $out;
    }

    /** "…/modules/ps_searchbar/ps_searchbar.js" => ps_searchbar; theme and core files get their own names. */
    public static function owner($url)
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/modules/([a-zA-Z0-9_-]+)/#', $path, $m)) {
            return $m[1];
        }
        if (preg_match('#/themes/[^/]+/assets/cache/#', $path)) {
            return ':combined';
        }
        if (preg_match('#/themes/#', $path)) {
            return ':theme';
        }
        if (!parse_url($url, PHP_URL_HOST) || parse_url($url, PHP_URL_HOST) === parse_url(Tools::getShopDomainSsl(true), PHP_URL_HOST)) {
            return ':core';
        }

        return ':external';
    }

    /**
     * Measure the home page and one product page.
     *
     * @return array ['modules' => [...sorted], 'pages' => [name => [files, bytes]], 'combined' => bool]
     */
    public static function measure($context, Module $module)
    {
        $plan = SpcAudit::plan($context, $module);
        $token = $plan['tokens']['all'];
        $pages = ['home' => $plan['home']];
        foreach ($plan['pages'] as $p) {
            if (strpos($p['url'], $plan['home']) === 0 && $p['url'] !== $plan['home'] && preg_match('/\.html(\?|$)/', $p['url'])) {
                $pages['product'] = $p['url'];
                break;
            }
        }
        $base = $context->shop->getBaseURL(true);
        $files = [];
        $perPage = [];
        foreach ($pages as $name => $url) {
            $jar = [];
            $r = SpcAudit::request($url, $token, $jar);
            if (!$r['ok']) {
                continue;
            }
            $perPage[$name] = ['files' => 0, 'bytes' => 0];
            foreach (self::assets($r['body'], $base) as $asset => $type) {
                $files[$asset]['type'] = $type;
                $files[$asset]['pages'][$name] = true;
                ++$perPage[$name]['files'];
            }
        }
        $sizes = [];
        foreach (array_slice(array_keys($files), 0, self::MAX_ASSETS) as $asset) {
            $jar = [];
            $r = SpcAudit::request($asset, $token, $jar);
            $sizes[$asset] = $r['ok'] ? strlen($r['body']) : 0;
            foreach (array_keys($files[$asset]['pages']) as $name) {
                $perPage[$name]['bytes'] += $sizes[$asset];
            }
        }

        $mods = [];
        foreach (self::hooks($context->shop->id) as $name => $hooks) {
            $mods[$name] = ['name' => $name, 'hooks' => count($hooks), 'hook_names' => array_slice($hooks, 0, 6), 'css' => 0, 'js' => 0, 'bytes' => 0];
        }
        $combined = false;
        foreach ($files as $asset => $f) {
            $owner = self::owner($asset);
            if ($owner === ':combined') {
                $combined = true;
            }
            if (!isset($mods[$owner])) {
                $mods[$owner] = ['name' => $owner, 'hooks' => 0, 'hook_names' => [], 'css' => 0, 'js' => 0, 'bytes' => 0];
            }
            ++$mods[$owner][$f['type']];
            $mods[$owner]['bytes'] += isset($sizes[$asset]) ? $sizes[$asset] : 0;
        }
        foreach ($mods as &$m) {
            $m['size'] = SpcHealth::size($m['bytes']);
            $m['heavy'] = $m['bytes'] > 150000 || $m['hooks'] > 12;
        }
        unset($m);
        usort($mods, function ($a, $b) {
            return $b['bytes'] - $a['bytes'] ?: $b['hooks'] - $a['hooks'];
        });
        foreach ($perPage as &$p) {
            $p['size'] = SpcHealth::size($p['bytes']);
        }
        unset($p);

        return ['modules' => array_values($mods), 'pages' => $perPage, 'combined' => $combined, 'measured' => count($sizes), 'found' => count($files)];
    }
}
