<?php
/**
 * After the cache is emptied, the shop visits its own busiest pages (home, every category, the
 * best-selling products) as a first-time visitor would, so no customer gets the slow first load.
 * It runs as a string of short requests from the settings page, a few pages each.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcWarmup
{
    /** pages visited per request, so none comes near PHP's time limit */
    public const BATCH = 4;

    public const TIMEOUT = 15;

    /**
     * Home, every active category and the products people buy most.
     *
     * @return string[] URLs
     */
    public static function urls($context, $productLimit = 60)
    {
        $idShop = (int) $context->shop->id;
        $idLang = (int) $context->language->id;
        $urls = [$context->link->getPageLink('index', true)];

        $categories = Db::getInstance()->executeS(
            'SELECT c.id_category FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . ')
            WHERE c.active = 1 AND c.id_category NOT IN (' . (int) Configuration::get('PS_ROOT_CATEGORY') . ', ' . (int) Configuration::get('PS_HOME_CATEGORY') . ')
            ORDER BY c.level_depth ASC, c.position ASC
            LIMIT 200'
        );
        foreach ((array) $categories as $row) {
            $urls[] = $context->link->getCategoryLink((int) $row['id_category'], null, $idLang);
        }

        $products = Db::getInstance()->executeS(
            'SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` pss ON (pss.id_product = p.id_product AND pss.id_shop = ' . $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'product_sale` ps ON (ps.id_product = p.id_product)
            WHERE pss.active = 1 AND pss.visibility IN (\'both\', \'catalog\')
            ORDER BY IFNULL(ps.quantity, 0) DESC, p.date_add DESC
            LIMIT ' . (int) $productLimit
        );
        foreach ((array) $products as $row) {
            $urls[] = $context->link->getProductLink((int) $row['id_product'], null, null, null, $idLang);
        }

        return array_values(array_unique($urls));
    }

    /**
     * Visit the next few pages.
     *
     * @return array ['offset' => next offset, 'total' => pages, 'finished' => bool, 'failed' => int]
     */
    public static function step($context, $offset)
    {
        $urls = self::urls($context);
        $total = count($urls);
        $offset = max(0, (int) $offset);
        $failed = 0;
        foreach (array_slice($urls, $offset, self::BATCH) as $url) {
            if (!self::visit($url)) {
                ++$failed;
            }
        }
        $next = min($total, $offset + self::BATCH);

        return ['offset' => $next, 'total' => $total, 'finished' => $next >= $total, 'failed' => $failed];
    }

    /** One page, as a visitor with no cookies would load it. */
    public static function visit($url)
    {
        if (!function_exists('curl_init')) {
            $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT, 'header' => "Accept: text/html\r\n"]]);

            return Tools::file_get_contents($url, false, $context, self::TIMEOUT) !== false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'SpeedPackCore/1.1 (+cache warm-up)',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $body !== false && $code >= 200 && $code < 400;
    }
}
