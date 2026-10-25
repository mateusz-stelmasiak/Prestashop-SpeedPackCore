<?php
/**
 * A small MCP server (Streamable HTTP, JSON-RPC 2.0, JSON answers) over the public, read-only
 * agent API: find products, make the shopping link a person opens to buy (UCP permalink), and the
 * shop's basics. Nothing here changes the shop or anyone's cart; the link does that only when the
 * person opens it.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SpcAgentApi.php';

class SpcAgentMcp
{
    public const PROTOCOL = '2025-06-18';
    public const SUPPORTED = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public static function serverInfo()
    {
        return ['name' => self::slug() . '-shop', 'title' => (string) Configuration::get('PS_SHOP_NAME'), 'version' => '1.0.0'];
    }

    /** shop name as a lowercase identifier (skills and the server's name) */
    public static function slug()
    {
        $s = Tools::strtolower(Tools::link_rewrite((string) Configuration::get('PS_SHOP_NAME')));
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');

        return $s !== '' ? $s : 'shop';
    }

    public static function tools()
    {
        return [
            [
                'name' => 'search_products',
                'title' => 'Search products',
                'description' => 'Find products of the shop by name: price, availability, picture, page address and a buy_url the person can open to buy.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words of the product name; empty lists everything.'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ]],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'make_buy_link',
                'title' => 'Make a shopping link',
                'description' => 'A link to give the person: opening it puts these products in their cart and opens the checkout, where they pay. It does not order or pay by itself.',
                'inputSchema' => ['type' => 'object', 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Product id from search_products.'],
                        'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'default' => 1],
                    ]]],
                ]],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'shop_info',
                'title' => 'About the shop',
                'description' => 'The shop\'s name, address, currency, contact, and where delivery and terms are described.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /** One JSON-RPC message: the answer, or null for a notification. */
    public static function handle(array $msg, Context $context)
    {
        $id = array_key_exists('id', $msg) ? $msg['id'] : null;
        $method = isset($msg['method']) ? (string) $msg['method'] : '';
        $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
        if (!isset($msg['jsonrpc']) || $msg['jsonrpc'] !== '2.0' || $method === '') {
            return self::error($id, -32600, 'Invalid Request');
        }
        if ($id === null) {
            return null; // notifications (initialized, cancelled...) need no answer
        }
        switch ($method) {
            case 'initialize':
                $asked = isset($params['protocolVersion']) ? (string) $params['protocolVersion'] : self::PROTOCOL;

                return self::result($id, [
                    'protocolVersion' => in_array($asked, self::SUPPORTED, true) ? $asked : self::PROTOCOL,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => self::serverInfo(),
                    'instructions' => 'Read-only tools for ' . Configuration::get('PS_SHOP_NAME') . '. To buy, give the person the link from make_buy_link; they pay on the shop\'s checkout.',
                ]);
            case 'ping':
                return self::result($id, new stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => self::tools()]);
            case 'tools/call':
                $name = isset($params['name']) ? (string) $params['name'] : '';
                $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
                $out = self::call($name, $args, $context);
                if ($out === null) {
                    return self::error($id, -32602, 'Unknown tool: ' . $name);
                }

                return self::result($id, [
                    'content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                    'structuredContent' => $out,
                    'isError' => isset($out['error']),
                ]);
            default:
                return self::error($id, -32601, 'Method not found');
        }
    }

    /** @return array|null null for an unknown tool */
    public static function call($name, array $args, Context $context)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        switch ($name) {
            case 'search_products':
                return SpcAgentApi::products($context, $idLang, isset($args['page']) ? (int) $args['page'] : 1, isset($args['limit']) ? (int) $args['limit'] : 20, Tools::substr(trim((string) (isset($args['query']) ? $args['query'] : '')), 0, 100));
            case 'make_buy_link':
                if (!SpcAgentSettings::on(SpcAgentSettings::K_UCP)) {
                    return ['error' => 'Shopping links are switched off in this shop; send the person to the product pages.'];
                }
                $pairs = [];
                foreach (array_slice(isset($args['items']) && is_array($args['items']) ? $args['items'] : [], 0, 30) as $item) {
                    $pid = is_array($item) && isset($item['id']) ? (int) $item['id'] : 0;
                    if ($pid > 0) {
                        $pairs[] = $pid . ':' . max(1, min(99, isset($item['quantity']) ? (int) $item['quantity'] : 1));
                    }
                }
                if (!$pairs) {
                    return ['error' => 'items: give at least one product id.'];
                }

                return ['url' => SpcAgentApi::buyEndpoint($context) . '/' . implode(',', $pairs),
                    'note' => 'Give this link to the person. Opening it fills their cart and opens the checkout; nothing is ordered until they pay there.', ];
            case 'shop_info':
                $link = $context->link;

                return [
                    'name' => (string) Configuration::get('PS_SHOP_NAME'),
                    'url' => rtrim($link->getBaseLink((int) $context->shop->id, true), '/'),
                    'email' => (string) Configuration::get('PS_SHOP_EMAIL'),
                    'phone' => (string) Configuration::get('PS_SHOP_PHONE'),
                    'address' => trim(Configuration::get('PS_SHOP_ADDR1') . ', ' . Configuration::get('PS_SHOP_CODE') . ' ' . Configuration::get('PS_SHOP_CITY'), ' ,'),
                    'currency' => (string) $context->currency->iso_code,
                    'contact_page' => $link->getPageLink('contact', true, $idLang),
                    'llms_txt' => rtrim($link->getBaseLink((int) $context->shop->id, true), '/') . '/llms.txt',
                ];
        }

        return null;
    }

    public static function result($id, $result)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public static function error($id, $code, $message)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** /.well-known/mcp/server-card.json */
    public static function serverCard(Context $context)
    {
        $base = rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/');

        return [
            'serverInfo' => self::serverInfo(),
            'description' => 'Find products of ' . Configuration::get('PS_SHOP_NAME') . ' and make the shopping link a person opens to buy. Read-only, no sign-in.',
            'protocolVersion' => self::PROTOCOL,
            'transport' => ['type' => 'streamable-http', 'endpoint' => $base . '/mcp'],
            'endpoint' => $base . '/mcp',
            'capabilities' => ['tools' => ['listChanged' => false], 'resources' => new stdClass(), 'prompts' => new stdClass()],
            'tools' => array_map(function ($t) { return ['name' => $t['name'], 'description' => $t['description']]; }, self::tools()),
            'authentication' => ['required' => false],
            'documentation' => $base . '/auth.md',
        ];
    }

    /** The shopping skill (SKILL.md, Agent Skills format). */
    public static function skillMd(Context $context)
    {
        $name = self::slug() . '-shopping';
        $shop = (string) Configuration::get('PS_SHOP_NAME');
        $base = rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/');
        $products = SpcAgentApi::url($context, 'products');

        return "---\nname: " . $name . "\ndescription: Find products of " . $shop . " (" . $base . ") and help a person buy them: search the catalogue, then give them a link that fills their cart and opens the checkout, where they pay.\n---\n\n"
            . '# Shopping at ' . $shop . "\n\n"
            . "Use this when a person wants to find or buy something from " . $base . ".\n\n"
            . "## Find products\n\n"
            . "- MCP: " . $base . "/mcp (Streamable HTTP), tool `search_products` with `query`.\n"
            . "- Or HTTP: GET " . $products . "?q={words}&limit=20 (JSON, no key). Each item has `id`, `name`, `price`, `currency`, `available`, `url` and `buy_url`.\n\n"
            . "## Help the person buy\n\n"
            . "Give the person a shopping link: " . $base . "/buy/{id}:{quantity}, items joined with commas, for example " . $base . "/buy/12:2,15:1 "
            . "(or the MCP tool `make_buy_link`). Opening it puts the products in their cart and opens the checkout; they sign in or order as a guest and pay there.\n\n"
            . "## Rules\n\n"
            . "- Never place an order or pay for the person: the shop takes orders only from them, on its checkout.\n"
            . "- Never ask for or send their password or card details.\n"
            . "- Check `available` before suggesting a product; prices are in " . ($context->currency ?: Currency::getDefaultCurrency())->iso_code . " and include tax.\n";
    }
}
