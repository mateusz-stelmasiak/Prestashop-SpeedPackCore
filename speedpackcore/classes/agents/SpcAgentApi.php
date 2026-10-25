<?php
/**
 * A small public, read-only API for AI agents and shopping assistants, and its discovery:
 *
 *   /module/speedpackcore/agproducts  the catalogue: name, price, availability, picture, address
 *   /module/speedpackcore/agstatus  health (application/health+json)
 *   /module/speedpackcore/agopenapi its OpenAPI 3.1 description (service-desc)
 *   /module/speedpackcore/agapidocs its documentation as a page (service-doc)
 *   /.well-known/api-catalog            RFC 9727 linkset pointing at all of the above
 *
 * Only what any visitor sees on the shop: active, visible products with their public price. The
 * catalog is a static file (the web server answers it, with its media type set in a .htaccess
 * next to it), written whenever llms.txt is made and on install.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SpcAgentSettings.php';

class SpcAgentApi
{
    const VERSION = '1.0.0';
    const MAX_LIMIT = 100;

    /** Absolute address of one of the API's endpoints, as the shop's own links make it. */
    public static function url(Context $context, $endpoint, array $params = [])
    {
        // always in the shop's default language, so the catalog does not change with who made it
        return $context->link->getModuleLink('speedpackcore', 'ag' . $endpoint, $params, true, (int) Configuration::get('PS_LANG_DEFAULT'), (int) $context->shop->id);
    }

    /** RFC 9727 linkset: the API with its description, its documentation and its health. */
    public static function catalog(Context $context)
    {
        $products = self::url($context, 'products');

        return ['linkset' => [[
            'anchor' => $products,
            'service-desc' => [['href' => self::url($context, 'openapi'), 'type' => 'application/openapi+json']],
            'service-doc' => [['href' => self::url($context, 'apidocs'), 'type' => 'text/html']],
            'status' => [['href' => self::url($context, 'status'), 'type' => 'application/health+json']],
        ]]];
    }

    /**
     * The Link header values for shop pages (RFC 8288, RFC 9727 section 3): the API catalog, the
     * API's description and documentation, and llms.txt as the site's description for agents.
     */
    public static function linkHeaders(Context $context)
    {
        if (!SpcAgentSettings::on(SpcAgentSettings::K_LINKS)) {
            return [];
        }
        $out = SpcAgentSettings::on(SpcAgentSettings::K_CATALOG) ? ['</.well-known/api-catalog>; rel="api-catalog"'] : [];
        $out = array_merge($out, [
            '<' . self::url($context, 'openapi') . '>; rel="service-desc"; type="application/openapi+json"',
            '<' . self::url($context, 'apidocs') . '>; rel="service-doc"; type="text/html"',
        ]);
        if (is_file(rtrim(_PS_ROOT_DIR_, '/') . '/llms.txt')) {
            $out[] = '</llms.txt>; rel="describedby"; type="text/markdown"';
        }

        return $out;
    }

    /**
     * /auth.md: how agents get access. The agent API is public and read-only, so it says so and
     * that no registration, key or OAuth flow exists; it promises nothing the shop does not do.
     */
    public static function authMd(Context $context)
    {
        $shop = (string) Configuration::get('PS_SHOP_NAME');
        $base = rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/');
        $lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $register = $context->link->getPageLink('authentication', true, $lang, 'create_account=1');
        $email = (string) Configuration::get('PS_SHOP_EMAIL');

        return '# ' . $shop . " auth.md\n\n"
            . "How AI agents and assistants get access to " . $base . ".\n\n"
            . "## Audience\n\n"
            . "Agents, crawlers and assistants that read this shop's catalogue for a person: prices, availability, product pages.\n\n"
            . "## Registration\n\n"
            . "Reading needs no registration: the agent API, the MCP server and the A2A agent are public and read-only, with no key and no OAuth flow. "
            . "This shop runs no OAuth authorization server and publishes no Authorization Server metadata.\n\n"
            . (SpcAgentSettings::acpCartsReady()
                ? "Agent platforms that build carts through ACP (" . $base . "/acp/carts, see " . $base . "/.well-known/acp.json) register with the shop by email"
                . ($email !== '' ? " (" . $email . ")" : '') . ": the merchant makes one API key per platform in the shop's back office and sends it to them. "
                . "The key goes in \"Authorization: Bearer <key>\"; a platform sees only the carts made with its own key, and the merchant can revoke it at any time. "
                . "The carts API's OAuth Protected Resource Metadata (RFC 9728) is at " . $base . "/.well-known/oauth-protected-resource/acp; it names no authorization server, because keys are issued by the merchant, not through OAuth.\n\n" : '')
            . "## Supported methods\n\n"
            . "- `anonymous`: plain HTTPS requests, no credentials (catalogue, MCP, A2A, shopping links).\n"
            . (SpcAgentSettings::acpCartsReady() ? "- `api_key`: Bearer key issued by the merchant, only for ACP carts.\n" : '')
            . "\n"
            . "## Endpoints\n\n"
            . '- API catalog (RFC 9727): ' . $base . "/.well-known/api-catalog\n"
            . '- OpenAPI description: ' . self::url($context, 'openapi') . "\n"
            . '- Documentation: ' . self::url($context, 'apidocs') . "\n"
            . '- Products: ' . self::url($context, 'products') . "\n"
            . '- Status: ' . self::url($context, 'status') . "\n"
            . (SpcAgentSettings::on(SpcAgentSettings::K_MCP) ? '- MCP server: ' . $base . "/mcp\n" : '')
            . (SpcAgentSettings::on(SpcAgentSettings::K_A2A_AGENT) ? '- A2A agent: ' . $base . "/a2a (card: " . $base . "/.well-known/agent-card.json)\n" : '')
            . (is_file(rtrim(_PS_ROOT_DIR_, '/') . '/llms.txt') ? '- Site description for agents: ' . $base . "/llms.txt\n" : '')
            . "\n## Credentials\n\n"
            . "Apart from ACP carts keys, no credentials are issued to agents and none are needed. Never send a customer's password or payment details to these endpoints.\n\n"
            . "## Buying for a person\n\n"
            . (SpcAgentSettings::on(SpcAgentSettings::K_UCP) ? "Give the person a shopping link (UCP permalink, see " . $base . "/.well-known/ucp): " . self::buyEndpoint($context) . "/{product id}:{quantity}, items joined with commas, "
            . "for example " . self::buyEndpoint($context) . "/12:2,15:1. Every product in the API has its own `buy_url`. "
            . "Opening it puts the products in that person's cart and opens the checkout, where they sign in (" . $register . ") or order as a guest, and pay. "
            . "The link never places an order or takes a payment; the shop does not take orders or payments from agents directly.\n"
                : "Orders are placed by the person on the shop's website (an account: " . $register . ", or guest checkout). The shop does not take orders or payments from agents.\n")
            . ($email !== '' ? "\n## Contact\n\n" . $email . "\n" : '');
    }

    /** the UCP release whose permalink capability is implemented */
    public const UCP_VERSION = '2026-08-25';

    /** /buy at the shop's root: the UCP permalink endpoint (no trailing slash) */
    public static function buyEndpoint(Context $context)
    {
        return rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/') . '/buy';
    }

    /**
     * The UCP business profile (/.well-known/ucp): one capability, the shopping permalink. No
     * REST, MCP or A2A service and no payment handler is declared, because the shop has none:
     * payment happens on the shop's own checkout, by the person (the registries stay present
     * and empty, as the profile schema requires).
     */
    public static function ucpProfile(Context $context)
    {
        $v = self::UCP_VERSION;

        return ['ucp' => [
            'version' => $v,
            'services' => new stdClass(),
            'capabilities' => [
                'dev.ucp.shopping.permalink' => [[
                    'version' => $v,
                    'spec' => 'https://ucp.dev/' . $v . '/specification/permalink',
                    'schema' => 'https://ucp.dev/' . $v . '/schemas/shopping/permalink.json',
                    'config' => ['endpoint' => self::buyEndpoint($context)],
                ]],
            ],
            'payment_handlers' => new stdClass(),
        ]];
    }

    public const CHARSET_START = '# BEGIN SpeedPack charset';
    public const CHARSET_END = '# END SpeedPack charset';

    /**
     * Apache sends .txt and .md without a charset, and a browser then guesses (Windows-1250 for
     * Polish: "wegańska" reads "wegaĹ„ska") although the file is valid UTF-8. A marked block
     * after PrestaShop's own part of the root .htaccess (PrestaShop keeps what is outside it when
     * it writes the file again) names the charset; the rest of the file is left as it is.
     *
     * @return bool|null null when there is no .htaccess (nginx or another server)
     */
    public static function writeCharset()
    {
        $ht = rtrim(_PS_ROOT_DIR_, '/') . '/.htaccess';
        if (!is_file($ht)) {
            return null;
        }
        $current = (string) @file_get_contents($ht);
        $block = self::CHARSET_START . "\n"
            . "<IfModule mod_mime.c>\n"
            . "  AddCharset UTF-8 .txt .md\n"
            . "  AddType text/markdown .md\n"
            . "</IfModule>\n"
            // PHP behind FastCGI only sees Authorization (ACP carts keys) when it is passed on
            . "<IfModule mod_setenvif.c>\n"
            . "  SetEnvIf Authorization \"(.+)\" HTTP_AUTHORIZATION=\$1\n"
            . "</IfModule>\n"
            . self::CHARSET_END . "\n";
        if (strpos($current, $block) !== false) {
            return true;
        }
        $clean = preg_replace('/' . preg_quote(self::CHARSET_START, '/') . '.*?' . preg_quote(self::CHARSET_END, '/') . '\n?/s', '', $current);
        $tmp = $ht . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, rtrim($clean) . "\n\n" . $block) === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, @fileperms($ht) & 0777 ?: 0644);

        return @rename($tmp, $ht);
    }

    /** Writes /.well-known/api-catalog and the .htaccess that gives it its media type. */
    public static function writeCatalog(Context $context)
    {
        $dir = rtrim(_PS_ROOT_DIR_, '/') . '/.well-known';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        $json = json_encode(self::catalog($context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $ok = @file_put_contents($dir . '/api-catalog', $json) !== false;
        @chmod($dir . '/api-catalog', 0644);
        $ucp = json_encode(self::ucpProfile($context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $ok = @file_put_contents($dir . '/ucp', $ucp) !== false && $ok;
        @chmod($dir . '/ucp', 0644);

        // the media type, without touching anything else a .htaccess there may hold (ACME and others)
        $ht = $dir . '/.htaccess';
        $block = "# BEGIN SpeedPack api-catalog\n"
            . "<Files \"api-catalog\">\n"
            . "  ForceType application/linkset+json\n"
            . "  <IfModule mod_headers.c>\n"
            . "    Header set Access-Control-Allow-Origin \"*\"\n"
            . "    Header set Cache-Control \"public, max-age=3600\"\n"
            . "  </IfModule>\n"
            . "</Files>\n"
            . "<Files \"ucp\">\n"
            . "  ForceType application/json\n"
            . "  <IfModule mod_headers.c>\n"
            . "    Header set Access-Control-Allow-Origin \"*\"\n"
            . "    Header set Cache-Control \"public, max-age=3600\"\n"
            . "  </IfModule>\n"
            . "</Files>\n"
            . "<Files \"acp\">\n"
            . "  ForceType application/json\n"
            . "  <IfModule mod_headers.c>\n"
            . "    Header set Access-Control-Allow-Origin \"*\"\n"
            . "  </IfModule>\n"
            . "</Files>\n"
            . "# END SpeedPack api-catalog\n";
        $current = is_file($ht) ? (string) @file_get_contents($ht) : '';
        $clean = preg_replace('/# BEGIN SpeedPack api-catalog.*?# END SpeedPack api-catalog\n?/s', '', $current);
        $ok = $ok && @file_put_contents($ht, rtrim($clean) . ($clean !== '' ? "\n\n" : '') . $block) !== false;
        @chmod($ht, 0644);

        return $ok;
    }

    /** One page of the catalogue. */
    public static function products(Context $context, $idLang, $page, $limit, $q)
    {
        $idShop = (int) $context->shop->id;
        $limit = max(1, min(self::MAX_LIMIT, (int) $limit));
        $page = max(1, (int) $page);
        $where = 'p.active = 1 AND ps.active = 1 AND ps.visibility IN ("both", "catalog", "search")';
        if ($q !== '') {
            $where .= ' AND pl.name LIKE "%' . pSQL(str_replace(['%', '_'], ['\\%', '\\_'], $q)) . '%"';
        }
        $from = ' FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . ')
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = ' . $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.id_category = ps.id_category_default AND cl.id_lang = ' . (int) $idLang . ' AND cl.id_shop = ' . $idShop . ')
            WHERE ' . $where;
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*)' . $from);
        $rows = Db::getInstance()->executeS('SELECT p.id_product, pl.name, pl.link_rewrite, pl.description_short, cl.name AS category' . $from
            . ' ORDER BY ps.id_product ASC LIMIT ' . (int) (($page - 1) * $limit) . ', ' . (int) $limit) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = self::item($context, $r, $idLang);
        }

        return ['page' => $page, 'limit' => $limit, 'total' => $total, 'items' => $items];
    }

    /** One product as the API shows it ($r: id_product, name, link_rewrite, description_short, category). */
    public static function item(Context $context, array $r, $idLang)
    {
        $id = (int) $r['id_product'];
        $cover = Image::getCover($id);
        $qty = (int) StockAvailable::getQuantityAvailableByProduct($id);

        return [
            'id' => $id,
            'name' => (string) $r['name'],
            'url' => $context->link->getProductLink($id, null, null, null, (int) $idLang),
            'price' => round((float) Product::getPriceStatic($id, true), 2),
            'currency' => $context->currency ? $context->currency->iso_code : '',
            'available' => $qty > 0 || Product::isAvailableWhenOutOfStock((int) StockAvailable::outOfStock($id)),
            'category' => (string) $r['category'],
            'description' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $r['description_short']), ENT_QUOTES, 'UTF-8'))),
            'image' => $cover ? $context->link->getImageLink((string) $r['link_rewrite'], $id . '-' . (int) $cover['id_image'], 'home_default') : null,
            // UCP permalink: opened by the person, it fills their cart and goes to checkout
            'buy_url' => SpcAgentSettings::on(SpcAgentSettings::K_UCP) ? self::buyEndpoint($context) . '/' . $id . ':1' : null,
        ];
    }

    /**
     * Products for a sentence ("something spicy for chicken"): every word of three letters or more
     * is looked for in the name, category and short description, word endings left off (Polish
     * inflects); best matches first.
     */
    public static function search(Context $context, $idLang, $text, $limit = 8)
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', Tools::strtolower((string) $text)) as $w) {
            if (Tools::strlen($w) >= 3 && !in_array($w, self::STOPWORDS, true)) {
                // "mugs" finds "Mug", "przyprawy" finds "przyprawa"
                $words[] = Tools::strlen($w) > 5 ? Tools::substr($w, 0, Tools::strlen($w) - 2) : (Tools::strlen($w) > 3 && substr($w, -1) === 's' ? substr($w, 0, -1) : $w);
            }
        }
        $words = array_slice(array_values(array_unique($words)), 0, 8);
        if (!$words) {
            return [];
        }
        $idShop = (int) $context->shop->id;
        $score = [];
        foreach ($words as $w) {
            $like = '"%' . pSQL(str_replace(['%', '_'], ['\\%', '\\_'], $w)) . '%"';
            $score[] = '(pl.name LIKE ' . $like . ') * 3 + (cl.name LIKE ' . $like . ') * 2 + (pl.description_short LIKE ' . $like . ')';
        }
        $rows = Db::getInstance()->executeS('SELECT p.id_product, pl.name, pl.link_rewrite, pl.description_short, cl.name AS category, (' . implode(' + ', $score) . ') AS score
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . ')
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = ' . $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.id_category = ps.id_category_default AND cl.id_lang = ' . (int) $idLang . ' AND cl.id_shop = ' . $idShop . ')
            WHERE p.active = 1 AND ps.active = 1 AND ps.visibility IN ("both", "catalog", "search")
            HAVING score > 0 ORDER BY score DESC, ps.id_product ASC LIMIT ' . max(1, min(20, (int) $limit))) ?: [];

        return array_map(function ($r) use ($context, $idLang) { return SpcAgentApi::item($context, $r, $idLang); }, $rows);
    }

    /** words that say nothing about a product (English and Polish) */
    public const STOPWORDS = ['the', 'and', 'for', 'with', 'want', 'need', 'buy', 'some', 'something', 'find', 'show', 'have', 'you', 'any', 'please', 'looking',
        'dla', 'chce', 'chcę', 'szukam', 'jakieś', 'jakiś', 'coś', 'czy', 'macie', 'mam', 'kupić', 'proszę', 'oraz', 'albo', 'jest', 'się', ];

    /** OpenAPI 3.1 description of the API. */
    public static function openapi(Context $context)
    {
        $base = preg_replace('#/agproducts(\?.*)?$#', '', self::url($context, 'products'));
        $shop = (string) Configuration::get('PS_SHOP_NAME');
        $product = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'price' => ['type' => 'number', 'description' => 'Price with tax, in the shop currency'],
                'currency' => ['type' => 'string', 'example' => 'PLN'],
                'available' => ['type' => 'boolean'],
                'category' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'image' => ['type' => ['string', 'null'], 'format' => 'uri'],
                'buy_url' => ['type' => ['string', 'null'], 'format' => 'uri', 'description' => 'UCP shopping permalink: the person opens it, the product goes into their cart and the checkout opens. Change ":1" for another quantity; join items with commas (/buy/12:2,15:1). Loading it never places an order or takes a payment.'],
            ],
        ];

        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $shop . ' – catalogue API',
                'version' => self::VERSION,
                'description' => 'Read-only public API of ' . $shop . ': the products a visitor can buy, with price, availability, picture and address. No key needed.',
            ],
            'servers' => [['url' => $base]],
            'paths' => [
                '/agproducts' => ['get' => [
                    'summary' => 'List products',
                    'operationId' => 'listProducts',
                    'parameters' => [
                        ['name' => 'q', 'in' => 'query', 'description' => 'Part of the product name', 'schema' => ['type' => 'string']],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                        ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => 50]],
                    ],
                    'responses' => ['200' => ['description' => 'A page of products', 'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => [
                            'page' => ['type' => 'integer'], 'limit' => ['type' => 'integer'], 'total' => ['type' => 'integer'],
                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Product']],
                        ],
                    ]]]]],
                ]],
                '/agstatus' => ['get' => [
                    'summary' => 'Health of the API',
                    'operationId' => 'status',
                    'responses' => ['200' => ['description' => 'The API works', 'content' => ['application/health+json' => ['schema' => [
                        'type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['pass', 'warn', 'fail']]],
                    ]]]]],
                ]],
            ],
            'components' => ['schemas' => ['Product' => $product]],
        ];

        return $spec;
    }

    /** 404 and stop, when the part is switched off (or the AI agents section never was on) */
    public static function guard($key)
    {
        if (!SpcAgentSettings::on($key)) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            http_response_code(404);
            header('Cache-Control: no-store');
            exit('Not found');
        }
    }

    /** Sends a JSON answer and stops. */
    public static function send($data, $type = 'application/json', $status = 200)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: ' . $type . '; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('X-Robots-Tag: noindex');
        @ini_set('serialize_precision', '-1'); // 35.33, not 35.329999999999998
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
