<?php
/**
 * ACP carts (Agentic Commerce Protocol 2026-04-17, the "carts" service): an agent platform builds
 * a basket for a person with estimated totals and hands it over with continue_url, the shop's UCP
 * shopping link. The person opens it, the products go into their own cart and they pay on the
 * shop's checkout (Paynow). There is no checkout session, delegated payment or order here.
 *
 *   POST /acp/carts               create        (Idempotency-Key)
 *   GET  /acp/carts/{id}          read
 *   PUT  /acp/carts/{id}          full replacement
 *   POST /acp/carts/{id}/cancel   cancel        (Idempotency-Key)
 *
 * Every call needs "Authorization: Bearer <key>": keys are made by the merchant for each agent
 * platform in the back office (SpeedPack, AI agents) and stored hashed. A cart belongs to the
 * key that made it.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SpcAgentApi.php';

class SpcAgentAcp
{
    public const VERSION = '2026-04-17';
    public const SUPPORTED = ['2026-04-17'];
    public const TTL = 604800; // a cart lives a week
    public const IDEM_TTL = 86400;
    public const MAX_ITEMS = 30;
    public const MAX_QTY = 99;

    public static function table($name)
    {
        return _DB_PREFIX_ . 'spc_acp_' . $name;
    }

    /** The two tables (on install, on upgrade, and before the first write after a copy-upgrade). */
    public static function install()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';
        $db = Db::getInstance();

        return $db->execute('CREATE TABLE IF NOT EXISTS `' . self::table('cart') . '` (
                `id` VARCHAR(40) NOT NULL,
                `key_hash` CHAR(64) NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `body` MEDIUMTEXT NOT NULL,
                `created` INT UNSIGNED NOT NULL,
                `expires` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`id`), KEY `expires` (`expires`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4')
            && $db->execute('CREATE TABLE IF NOT EXISTS `' . self::table('idem') . '` (
                `key_hash` CHAR(64) NOT NULL,
                `path` VARCHAR(80) NOT NULL,
                `idem` CHAR(64) NOT NULL,
                `body_hash` CHAR(64) NOT NULL,
                `status` SMALLINT UNSIGNED NOT NULL,
                `response` MEDIUMTEXT NOT NULL,
                `created` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`key_hash`, `path`, `idem`), KEY `created` (`created`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function uninstall()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table('cart') . '`, `' . self::table('idem') . '`');
    }

    /* ------------------------------------------------------------------ *
     *  API keys
     * ------------------------------------------------------------------ */

    /** @return array[] name, hash, hint, created */
    public static function keys()
    {
        $k = json_decode((string) Configuration::get(SpcAgentSettings::K_ACP_KEYS), true);

        return is_array($k) ? array_values(array_filter($k, function ($x) { return is_array($x) && isset($x['hash'], $x['name']); })) : [];
    }

    /** A new key for one agent platform: the key itself is returned once and only its hash kept. */
    public static function newKey($name)
    {
        $key = 'acp_' . bin2hex(random_bytes(24));
        $keys = self::keys();
        $keys[] = ['name' => Tools::substr(trim(strip_tags((string) $name)), 0, 60), 'hash' => hash('sha256', $key), 'hint' => Tools::substr($key, -4), 'created' => gmdate('Y-m-d')];
        Configuration::updateValue(SpcAgentSettings::K_ACP_KEYS, json_encode($keys));

        return $key;
    }

    public static function revoke(array $hashes)
    {
        Configuration::updateValue(SpcAgentSettings::K_ACP_KEYS, json_encode(array_values(array_filter(self::keys(), function ($k) use ($hashes) {
            return !in_array($k['hash'], $hashes, true);
        }))));
    }

    /** the hash of the Bearer key, when it is one of the merchant's keys */
    public static function authenticate($header)
    {
        if (!preg_match('/^\s*Bearer\s+(\S{8,200})\s*$/i', (string) $header, $m)) {
            return null;
        }
        $hash = hash('sha256', $m[1]);
        foreach (self::keys() as $k) {
            if (hash_equals($k['hash'], $hash)) {
                return $hash;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ *
     *  Requests
     * ------------------------------------------------------------------ */

    public static function error($status, $code, $message, $param = null, $type = 'invalid_request')
    {
        $e = ['type' => $type, 'code' => $code, 'message' => $message];
        if ($param !== null) {
            $e['param'] = $param;
        }
        if ($code === 'unsupported_api_version' || $code === 'missing_api_version') {
            $e['supported_versions'] = self::SUPPORTED;
        }

        return [$status, $e];
    }

    /**
     * One request. $headers: lowercased names. Returns [status, body, extra headers].
     *
     * @param string $method
     * @param string $id cart id from the path, or ''
     * @param string $op '' or 'cancel'
     */
    public static function handle($method, $id, $op, array $headers, $raw, Context $context)
    {
        $extra = [];
        if (isset($headers['request-id'])) {
            $extra['Request-Id'] = Tools::substr((string) $headers['request-id'], 0, 200);
        }
        $keyHash = self::authenticate(isset($headers['authorization']) ? $headers['authorization'] : '');
        if ($keyHash === null) {
            // RFC 9728 section 5: where the resource's metadata is
            $extra['WWW-Authenticate'] = 'Bearer realm="acp", resource_metadata="' . self::metadataUrl($context) . '"';

            return array_merge(self::error(401, 'unauthorized', 'Invalid or missing Authorization header. Keys are issued by the shop to agent platforms: ' . Configuration::get('PS_SHOP_EMAIL')), [$extra]);
        }
        $version = isset($headers['api-version']) ? trim((string) $headers['api-version']) : '';
        if ($version === '') {
            return array_merge(self::error(400, 'missing_api_version', 'The API-Version header is required.'), [$extra]);
        }
        if (!in_array($version, self::SUPPORTED, true)) {
            return array_merge(self::error(400, 'unsupported_api_version', 'API version ' . Tools::substr($version, 0, 20) . ' is not supported.'), [$extra]);
        }
        $extra['API-Version'] = $version;
        $route = $method . ' ' . ($id === '' ? '/carts' : '/carts/{id}' . ($op !== '' ? '/' . $op : ''));
        $known = ['POST /carts', 'GET /carts/{id}', 'PUT /carts/{id}', 'POST /carts/{id}/cancel'];
        if (!in_array($route, $known, true)) {
            return array_merge(self::error(405, 'method_not_allowed', 'Not an operation of the carts service.'), [$extra]);
        }
        $body = null;
        if ($method === 'POST' || $method === 'PUT') {
            $body = trim((string) $raw) === '' ? [] : json_decode((string) $raw, true);
            if (!is_array($body)) {
                return array_merge(self::error(400, 'invalid_json', 'The request body is not a JSON object.'), [$extra]);
            }
        }
        self::install();
        self::sweep();

        // POST: Idempotency-Key, scoped to the key and the path; same body replays the answer
        if ($method === 'POST') {
            $idem = isset($headers['idempotency-key']) ? (string) $headers['idempotency-key'] : '';
            if ($idem === '' || strlen($idem) > 255) {
                return array_merge(self::error(400, 'idempotency_key_required', 'Idempotency-Key header is required on all POST requests'), [$extra]);
            }
            $extra['Idempotency-Key'] = $idem;
            $path = Tools::substr($id === '' ? '/carts' : '/carts/' . $id . '/' . $op, 0, 80);
            $bodyHash = hash('sha256', json_encode(self::canonical($body)));
            $row = Db::getInstance()->getRow('SELECT `body_hash`, `status`, `response` FROM `' . self::table('idem') . '` WHERE `key_hash` = "' . pSQL($keyHash) . '" AND `path` = "' . pSQL($path) . '" AND `idem` = "' . pSQL(hash('sha256', $idem)) . '"');
            if ($row) {
                if (!hash_equals((string) $row['body_hash'], $bodyHash)) {
                    return array_merge(self::error(422, 'idempotency_conflict', 'Idempotency-Key has already been used with a different request body'), [$extra]);
                }
                $extra['Idempotent-Replayed'] = 'true';

                return [(int) $row['status'], json_decode((string) $row['response'], true), $extra];
            }
            list($status, $out) = $id === '' ? self::create($keyHash, $body, $context) : self::cancel($keyHash, $id, $context);
            Db::getInstance()->insert('spc_acp_idem', [
                'key_hash' => pSQL($keyHash), 'path' => pSQL($path), 'idem' => pSQL(hash('sha256', $idem)), 'body_hash' => pSQL($bodyHash),
                'status' => (int) $status, 'response' => pSQL(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true), 'created' => time(),
            ], false, true, Db::INSERT_IGNORE);

            return [$status, $out, $extra];
        }
        if ($method === 'GET') {
            $cart = self::load($keyHash, $id);

            return $cart === null ? array_merge(self::notFound(), [$extra]) : [200, self::render($id, $cart, $context), $extra];
        }

        return array_merge(self::update($keyHash, $id, $body, $context), [$extra]);
    }

    protected static function notFound()
    {
        return self::error(404, 'not_found', 'Cart not found or has expired.');
    }

    protected static function create($keyHash, array $body, Context $context)
    {
        $items = self::items($body);
        if (is_array($items) && isset($items[0]) && $items[0] === 'error') {
            return [$items[1], $items[2]];
        }
        $stored = ['line_items' => $items, 'buyer' => self::buyer($body)];
        $view = self::render('', $stored, $context);
        if (!$view['line_items']) {
            return self::error(422, 'out_of_stock', 'All requested items are currently unavailable.');
        }
        $id = 'cart_' . bin2hex(random_bytes(12));
        Db::getInstance()->insert('spc_acp_cart', [
            'id' => pSQL($id), 'key_hash' => pSQL($keyHash), 'id_shop' => (int) $context->shop->id,
            'body' => pSQL(json_encode($stored, JSON_UNESCAPED_UNICODE), true), 'created' => time(), 'expires' => time() + self::TTL,
        ]);

        return [201, self::render($id, $stored + ['expires' => time() + self::TTL], $context)];
    }

    protected static function update($keyHash, $id, array $body, Context $context)
    {
        $cart = self::load($keyHash, $id);
        if ($cart === null) {
            return self::notFound();
        }
        $items = self::items($body);
        if (is_array($items) && isset($items[0]) && $items[0] === 'error') {
            return [$items[1], $items[2]];
        }
        // full replacement; a buyer left out stays as it was
        $stored = ['line_items' => $items, 'buyer' => array_key_exists('buyer', $body) ? self::buyer($body) : $cart['buyer']];
        $view = self::render($id, $stored + ['expires' => $cart['expires']], $context);
        if (!$view['line_items']) {
            return self::error(422, 'out_of_stock', 'All requested items are currently unavailable.');
        }
        Db::getInstance()->update('spc_acp_cart', ['body' => pSQL(json_encode($stored, JSON_UNESCAPED_UNICODE), true)], '`id` = "' . pSQL($id) . '"');

        return [200, $view];
    }

    protected static function cancel($keyHash, $id, Context $context)
    {
        $cart = self::load($keyHash, $id);
        if ($cart === null) {
            return self::notFound();
        }
        $view = self::render($id, $cart, $context);
        Db::getInstance()->delete('spc_acp_cart', '`id` = "' . pSQL($id) . '"');

        return [200, $view];
    }

    /** the stored cart of this key, or null (unknown, someone else's, expired) */
    protected static function load($keyHash, $id)
    {
        if (!preg_match('/^cart_[a-f0-9]{24}$/', (string) $id)) {
            return null;
        }
        $row = Db::getInstance()->getRow('SELECT `key_hash`, `body`, `expires` FROM `' . self::table('cart') . '` WHERE `id` = "' . pSQL($id) . '"');
        if (!$row || !hash_equals((string) $row['key_hash'], $keyHash) || (int) $row['expires'] < time()) {
            return null;
        }
        $body = json_decode((string) $row['body'], true);

        return is_array($body) ? $body + ['expires' => (int) $row['expires']] : null;
    }

    protected static function sweep()
    {
        if (mt_rand(1, 50) === 1) {
            Db::getInstance()->delete('spc_acp_cart', '`expires` < ' . time());
            Db::getInstance()->delete('spc_acp_idem', '`created` < ' . (time() - self::IDEM_TTL));
        }
    }

    /** request line_items as [[id, quantity], ...], or ['error', status, body] */
    protected static function items(array $body)
    {
        if (!isset($body['line_items']) || !is_array($body['line_items']) || !$body['line_items']) {
            return ['error', 400, self::error(400, 'missing_line_items', 'line_items is required and must contain at least one item', '$.line_items')[1]];
        }
        if (count($body['line_items']) > self::MAX_ITEMS) {
            return ['error', 400, self::error(400, 'too_many_items', 'At most ' . self::MAX_ITEMS . ' line items.', '$.line_items')[1]];
        }
        $out = [];
        foreach (array_values($body['line_items']) as $i => $li) {
            if (!is_array($li) || !isset($li['id']) || !is_scalar($li['id']) || (string) $li['id'] === '') {
                return ['error', 400, self::error(400, 'invalid_item', 'Each line item needs an id.', '$.line_items[' . $i . '].id')[1]];
            }
            $q = isset($li['quantity']) ? $li['quantity'] : 1;
            if (!is_int($q) && !(is_string($q) && ctype_digit($q)) || (int) $q < 1) {
                return ['error', 400, self::error(400, 'invalid_quantity', 'quantity must be a whole number of at least 1.', '$.line_items[' . $i . '].quantity')[1]];
            }
            $out[] = [Tools::substr((string) $li['id'], 0, 40), min(self::MAX_QTY, (int) $q)];
        }

        return $out;
    }

    protected static function buyer(array $body)
    {
        if (!isset($body['buyer']) || !is_array($body['buyer'])) {
            return null;
        }
        $b = [];
        foreach (['first_name', 'last_name', 'full_name', 'email', 'phone_number'] as $f) {
            if (isset($body['buyer'][$f]) && is_string($body['buyer'][$f]) && $body['buyer'][$f] !== '') {
                $b[$f] = Tools::substr(strip_tags($body['buyer'][$f]), 0, 120);
            }
        }

        return isset($b['email']) && Validate::isEmail($b['email']) ? $b : null;
    }

    /** the Cart object: products and prices as they are now, totals estimated */
    public static function render($id, array $stored, Context $context)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $currency = $context->currency;
        $precision = isset($currency->precision) ? (int) $currency->precision : 2;
        $minor = function ($amount) use ($precision) { return (int) round($amount * pow(10, $precision)); };
        $lines = [];
        $messages = [];
        $pairs = [];
        $subtotal = 0;
        foreach ($stored['line_items'] as $i => $li) {
            list($ref, $qty) = $li;
            $parts = preg_match('/^(\d+)(?:-(\d+))?$/', $ref, $m) ? [(int) $m[1], isset($m[2]) ? (int) $m[2] : 0] : [0, 0];
            $product = $parts[0] > 0 ? new Product($parts[0], false, $idLang, (int) $context->shop->id) : null;
            $visible = $product && Validate::isLoadedObject($product) && $product->active && in_array($product->visibility, ['both', 'catalog', 'search'], true)
                && ($parts[1] === 0 || (int) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . (int) $parts[1] . ' AND id_product = ' . (int) $parts[0]));
            if (!$visible) {
                $messages[] = ['type' => 'error', 'code' => 'not_found', 'severity' => 'medium', 'resolution' => 'recoverable', 'param' => '$.line_items[' . $i . ']',
                    'content_type' => 'plain', 'content' => 'Item ' . $ref . ' is not sold in this shop; it was left out of the cart.', ];
                continue;
            }
            $unit = $minor(Product::getPriceStatic($parts[0], true, $parts[1] ?: null));
            $available = StockAvailable::getQuantityAvailableByProduct($parts[0], $parts[1] ?: null) >= $qty
                || Product::isAvailableWhenOutOfStock((int) StockAvailable::outOfStock($parts[0]));
            $name = is_array($product->name) ? (string) reset($product->name) : (string) $product->name;
            $cover = Image::getCover($parts[0]);
            $line = [
                'id' => 'li_' . ($i + 1),
                'item' => ['id' => $ref, 'name' => $name, 'unit_amount' => $unit, 'url' => $context->link->getProductLink($product, null, null, null, $idLang, null, $parts[1])],
                'quantity' => $qty,
                'name' => $name,
                'unit_amount' => $unit,
                'product_id' => (string) $parts[0],
                'totals' => [['type' => 'subtotal', 'display_text' => 'Subtotal', 'amount' => $unit * $qty]],
            ];
            if ($cover) {
                $line['images'] = [$context->link->getImageLink((string) (is_array($product->link_rewrite) ? reset($product->link_rewrite) : $product->link_rewrite), $parts[0] . '-' . (int) $cover['id_image'], 'large_default')];
            }
            if (!$available) {
                $messages[] = ['type' => 'error', 'code' => 'out_of_stock', 'severity' => 'medium', 'resolution' => 'recoverable', 'param' => '$.line_items[' . $i . ']',
                    'content_type' => 'plain', 'content' => $name . ' is out of stock in this quantity.', ];
            } else {
                $subtotal += $unit * $qty;
                $pairs[] = $ref . ':' . $qty;
            }
            $lines[] = $line;
        }
        if ($lines && !$pairs) {
            $lines = []; // all unavailable: the caller answers 422
        }
        $cart = [
            'id' => $id,
            'line_items' => $lines,
            'currency' => Tools::strtolower((string) $currency->iso_code),
            'totals' => [
                ['type' => 'subtotal', 'display_text' => 'Subtotal', 'amount' => $subtotal],
                ['type' => 'total', 'display_text' => 'Estimated total', 'amount' => $subtotal, 'description' => 'Prices include VAT. Delivery is chosen and paid at checkout.'],
            ],
        ];
        if (!empty($stored['buyer'])) {
            $cart['buyer'] = $stored['buyer'];
        }
        $messages[] = ['type' => 'info', 'severity' => 'info', 'resolution' => 'requires_buyer_input', 'content_type' => 'plain',
            'content' => 'To buy, give the person continue_url: it puts these products in their cart at ' . Configuration::get('PS_SHOP_NAME') . ' and opens the checkout, where they choose delivery and pay.', ];
        $cart['messages'] = $messages;
        if ($pairs && SpcAgentSettings::on(SpcAgentSettings::K_UCP)) {
            $cart['continue_url'] = SpcAgentApi::buyEndpoint($context) . '/' . implode(',', $pairs);
        }
        if (isset($stored['expires'])) {
            $cart['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $stored['expires']);
        }

        return $cart;
    }

    /** semantic JSON equality: object keys sorted, arrays kept in order, 1.0 == 1 */
    public static function canonical($v)
    {
        if (is_array($v)) {
            $assoc = array_keys($v) !== range(0, count($v) - 1);
            $v = array_map([__CLASS__, 'canonical'], $v);
            if ($assoc) {
                ksort($v, SORT_STRING);
            }

            return $v;
        }
        if (is_float($v) && floor($v) == $v && abs($v) < 1e15) {
            return (int) $v;
        }

        return $v;
    }

    /** the protected resource (RFC 9728): the carts API */
    public static function resource(Context $context)
    {
        return rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/') . '/acp';
    }

    /** where its metadata is: /.well-known/oauth-protected-resource inserted before the path */
    public static function metadataUrl(Context $context)
    {
        return rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/') . '/.well-known/oauth-protected-resource/acp';
    }

    /**
     * OAuth 2.0 Protected Resource Metadata (RFC 9728) for the carts API: Bearer keys in the
     * Authorization header. No authorization server is named, because keys are issued by the
     * merchant, not through OAuth (authorization_servers is optional).
     */
    public static function resourceMetadata(Context $context)
    {
        $base = rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/');

        return [
            'resource' => self::resource($context),
            'resource_name' => Configuration::get('PS_SHOP_NAME') . ' ACP carts',
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => $base . '/auth.md',
        ];
    }

    /** /.well-known/acp.json for the carts service above */
    public static function discovery(Context $context)
    {
        return [
            'protocol' => ['name' => 'acp', 'version' => self::VERSION, 'supported_versions' => self::SUPPORTED],
            'api_base_url' => rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/') . '/acp',
            'transports' => ['rest'],
            'capabilities' => [
                'services' => ['carts'],
                'supported_currencies' => [Tools::strtolower((string) ($context->currency ?: Currency::getDefaultCurrency())->iso_code)],
                'supported_locales' => [str_replace('_', '-', (string) $context->language->locale)],
            ],
        ];
    }
}
