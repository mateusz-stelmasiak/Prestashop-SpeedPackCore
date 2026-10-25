<?php
/**
 * SpeedPack Core - AI agents: /llms.txt, the shop described for language models (llmstxt.org),
 * when no other module makes one. A module that does (one offering the displayLlmsTxt hook, or a
 * file already there that SpeedPack did not write) is left alone.
 *
 * The file: the shop's name and description, then its categories, products (with price) and
 * information pages as Markdown links. Made again once a day, on the first visit after it is due.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcAgentLlms
{
    /** last line of a file SpeedPack wrote (so it knows it may replace it); the file starts with its "# " title, as llmstxt.org asks */
    public const MARK = '<!-- llms.txt by SpeedPack Core -->';
    public const MAX_AGE = 86400;
    public const MAX_PRODUCTS = 500;

    public static function file()
    {
        return rtrim(_PS_ROOT_DIR_, '/') . '/llms.txt';
    }

    /**
     * another module makes llms.txt: SpeedPack's own is not needed (modules that only add a
     * section to it, through displayLlmsTxt, do not count)
     */
    public static function otherMaker()
    {
        foreach (['ps_llms_generator', 'llmstxt', 'llms_txt'] as $name) {
            if (Module::isInstalled($name) && Module::isEnabled($name)) {
                return $name;
            }
        }
        $f = self::file();
        if (is_file($f) && !self::ours()) {
            return 'a file already there';
        }

        return null;
    }

    public static function ours()
    {
        $f = self::file();
        if (!is_file($f)) {
            return false;
        }
        $size = (int) filesize($f);

        return strpos((string) @file_get_contents($f, false, null, max(0, $size - 200)), self::MARK) !== false;
    }

    /** made again when it is SpeedPack's, switched on and older than a day (cheap: one stat) */
    public static function due()
    {
        $f = self::file();

        return SpcAgentSettings::on(SpcAgentSettings::K_LLMS) && (!is_file($f) || filemtime($f) < time() - self::MAX_AGE) && self::otherMaker() === null;
    }

    /** @return bool written */
    public static function write(Context $context)
    {
        if (self::otherMaker() !== null) {
            return false;
        }
        $f = self::file();
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, self::build($context)) === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0644);

        return @rename($tmp, $f);
    }

    public static function remove()
    {
        if (self::ours()) {
            @unlink(self::file());
        }
    }

    protected static function line($s)
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8')));
    }

    protected static function cut($s, $n)
    {
        $s = self::line($s);

        return Tools::strlen($s) > $n ? rtrim(Tools::substr($s, 0, $n - 1)) . '…' : $s;
    }

    public static function build(Context $context)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $idShop = (int) $context->shop->id;
        $link = $context->link;
        $db = Db::getInstance();
        $shop = (string) Configuration::get('PS_SHOP_NAME');
        $meta = Meta::getMetaByPage('index', $idLang);
        $desc = self::line(isset($meta['description']) ? $meta['description'] : '');
        $currency = $context->currency ?: Currency::getDefaultCurrency();
        $out = '# ' . $shop . "\n\n" . '> ' . ($desc !== '' ? $desc : 'Online shop ' . $shop . '.') . "\n\n";
        $out .= '- Shop: ' . $link->getPageLink('index', true, $idLang) . "\n";
        $out .= '- Prices in ' . $currency->iso_code . ", with tax.\n";
        if ((string) Configuration::get('PS_SHOP_EMAIL') !== '') {
            $out .= '- Contact: ' . Configuration::get('PS_SHOP_EMAIL') . "\n";
        }
        if (SpcAgentSettings::on(SpcAgentSettings::K_UCP)) {
            $out .= '- To buy for a person, give them a shopping link: ' . SpcAgentApi::buyEndpoint($context) . "/{product id}:{quantity} (it fills their cart and opens the checkout, where they pay).\n";
        }
        if (SpcAgentSettings::on(SpcAgentSettings::K_CATALOG)) {
            $out .= '- Catalogue API (JSON): ' . SpcAgentApi::url($context, 'products') . "\n";
        }

        $cats = $db->executeS('SELECT c.id_category, cl.name, cl.description FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . '
            WHERE c.active = 1 AND c.level_depth > 1 ORDER BY c.nleft') ?: [];
        if ($cats) {
            $out .= "\n## Categories\n\n";
            foreach ($cats as $c) {
                $d = self::cut($c['description'], 160);
                $out .= '- [' . self::line($c['name']) . '](' . $link->getCategoryLink((int) $c['id_category'], null, $idLang) . ')' . ($d !== '' ? ': ' . $d : '') . "\n";
            }
        }

        $rows = $db->executeS('SELECT p.id_product, pl.name, pl.description_short FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
            WHERE ps.active = 1 AND ps.visibility IN ("both", "catalog") ORDER BY ps.id_category_default, pl.name LIMIT ' . self::MAX_PRODUCTS) ?: [];
        if ($rows) {
            $out .= "\n## Products\n\n";
            foreach ($rows as $r) {
                $id = (int) $r['id_product'];
                // formatted here: Tools::displayPrice needs Symfony's container, absent after the page is sent
                $price = number_format((float) Product::getPriceStatic($id, true), 2, ',', ' ') . ' ' . $currency->iso_code;
                $d = self::cut($r['description_short'], 200);
                $out .= '- [' . self::line($r['name']) . '](' . $link->getProductLink($id, null, null, null, $idLang) . '): ' . self::line($price) . ($d !== '' ? '. ' . $d : '') . "\n";
            }
        }

        $cms = $db->executeS('SELECT c.id_cms, cl.meta_title FROM `' . _DB_PREFIX_ . 'cms` c
            INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON cs.id_cms = c.id_cms AND cs.id_shop = ' . $idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'cms_lang` cl ON cl.id_cms = c.id_cms AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . '
            WHERE c.active = 1 ORDER BY c.position') ?: [];
        if ($cms) {
            $out .= "\n## Information\n\n";
            foreach ($cms as $c) {
                $out .= '- [' . self::line($c['meta_title']) . '](' . $link->getCMSLink((int) $c['id_cms'], null, null, $idLang) . ")\n";
            }
        }
        $out .= '- [Contact](' . $link->getPageLink('contact', true, $idLang) . ")\n";

        return $out . "\n" . self::MARK . "\n";
    }
}
