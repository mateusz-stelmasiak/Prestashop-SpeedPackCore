<?php
/**
 * UCP shopping permalink (dev.ucp.shopping.permalink, UCP 2026-08-25): /buy/{item}:{qty},...
 *
 * An agent hands the person a link; opening it puts the products in that person's cart and sends
 * the browser on to the checkout (303), where the person signs in or enters an address and pays
 * with the shop's own payment (Paynow: BLIK, transfer, card). Loading the link never places an
 * order or takes a payment, as the specification requires.
 *
 * Items are product ids ("12") or product-combination ids ("12-45"), raw or "~" + base64url.
 * Query: continue_to (a same-origin path, validated), discounts/codes/N (voucher codes),
 * attribution/* (kept as plain parameters for analytics); other UCP fields are consumed,
 * other parameters passed through unless they look sensitive.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpeedPackCoreAgBuyModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;

    /** at most this many of one item from a link */
    public const MAX_QTY = 99;
    public const MAX_ITEMS = 30;

    /** top-level fields of the UCP Cart / Checkout objects: such query parameters are UCP fields */
    public const UCP_ROOTS = ['line_items', 'buyer', 'context', 'attribution', 'discounts', 'fulfillment', 'payment', 'currency', 'totals',
        'status', 'id', 'messages', 'links', 'order', 'expires_at', 'continue_url', 'signals', 'ucp', ];

    /** PrestaShop's own routing parameters, not part of the link's query */
    public const ROUTING = ['fc', 'module', 'controller', 'items', 'isolang', 'id_lang'];

    public function init()
    {
        parent::init();
        require_once _PS_MODULE_DIR_ . 'speedpackcore/classes/agents/SpcAgentApi.php';
        // switched off (SpeedPack, AI agents): no permalink here
        if (!SpcAgentSettings::on(SpcAgentSettings::K_UCP)) {
            header('HTTP/1.1 404 Not Found', true, 404);
            header('X-Robots-Tag: noindex');
            exit('Not found');
        }
        $errors = [];
        $items = self::parseItems((string) Tools::getValue('items'), $errors);
        $query = self::parseQuery(isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '');
        $added = 0;
        if ($items) {
            $this->ensureCart();
            foreach ($items as $item) {
                $added += $this->addItem($item[0], $item[1], $item[2], $errors) ? 1 : 0;
            }
        }
        foreach ($query['codes'] as $code) {
            $this->addVoucher($code, $errors);
        }
        if ($query['ambiguous']) {
            $errors[] = $this->module->l('Some values in the link were given twice and were left out.', 'buy');
        }

        $origin = self::origin($this->context->link->getPageLink('index', true));
        $path = $query['continue_to'] !== null ? self::continueTo($query['continue_to']) : null;
        if ($path !== null) {
            $to = $origin . $path;
        } elseif ($added > 0) {
            $to = $this->context->link->getPageLink('order', true);
        } else {
            $to = $this->context->link->getPageLink('cart', true, null, 'action=show');
        }
        if ($query['pass']) {
            $to .= (strpos($to, '?') === false ? '?' : '&') . http_build_query($query['pass'], '', '&', PHP_QUERY_RFC3986);
        }
        $this->notify($errors);
        self::send303($to);
    }

    /**
     * The compact item path: [[id_product, id_product_attribute, qty], ...]; what cannot be read
     * goes to $errors.
     */
    public static function parseItems($path, array &$errors)
    {
        $out = [];
        $path = trim($path, '/');
        if ($path === '') {
            return $out;
        }
        foreach (array_slice(explode(',', $path), 0, self::MAX_ITEMS) as $pair) {
            if (!preg_match('/^([A-Za-z0-9._-]+|~[A-Za-z0-9_-]+):([1-9][0-9]{0,5})$/', $pair, $m)) {
                $errors[] = 'item';
                continue;
            }
            $id = $m[1];
            if ($id[0] === '~') {
                $raw = base64_decode(strtr(substr($id, 1), '-_', '+/'), true);
                // canonical base64url without padding only, and valid UTF-8 without control characters
                if ($raw === false || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== substr($id, 1) || !mb_check_encoding($raw, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $raw)) {
                    $errors[] = 'item';
                    continue;
                }
                $id = $raw;
            }
            // "12", "12-45", or an identifier this shop owns with its numbers inside
            if (!preg_match('/^(?:.*[^0-9])?(\d+)(?:-(\d+))?$/', $id, $n)) {
                $errors[] = 'item';
                continue;
            }
            $out[] = [(int) $n[1], isset($n[2]) ? (int) $n[2] : 0, min(self::MAX_QTY, (int) $m[2])];
        }

        return $out;
    }

    /**
     * The query, by the permalink's rules: continue_to (null when absent or given twice),
     * voucher codes, parameters to pass on, and whether a UCP field was given twice.
     */
    public static function parseQuery($qs)
    {
        $out = ['continue_to' => null, 'codes' => [], 'pass' => [], 'ambiguous' => false];
        $seen = [];
        $continue = [];
        foreach ($qs === '' ? [] : explode('&', $qs) as $part) {
            if ($part === '') {
                continue;
            }
            $kv = explode('=', $part, 2);
            $name = rawurldecode(str_replace('+', ' ', $kv[0]));
            $value = isset($kv[1]) ? rawurldecode(str_replace('+', ' ', $kv[1])) : '';
            if (in_array($name, self::ROUTING, true)) {
                continue;
            }
            if ($name === 'continue_to') {
                // kept percent-encoded: continueTo() decodes it once itself
                $continue[] = isset($kv[1]) ? $kv[1] : '';
                continue;
            }
            $pointer = '/' . ltrim($name, '/');
            $root = explode('/', substr($pointer, 1))[0];
            if (in_array($root, self::UCP_ROOTS, true)) {
                if (isset($seen[$pointer])) {
                    $out['ambiguous'] = true;
                    continue;
                }
                $seen[$pointer] = $value;
                continue;
            }
            // not UCP: passed on, unless it looks like a secret
            if (!preg_match('/token|passw|secret|session|auth|api_?key|signature/i', $name) && !isset($out['pass'][$name])) {
                $out['pass'][$name] = $value;
            }
        }
        foreach ($seen as $pointer => $value) {
            if (preg_match('#^/discounts/codes/(\d+)$#', $pointer) && $value !== '') {
                $out['codes'][] = substr($value, 0, 254);
            } elseif (preg_match('#^/attribution/([A-Za-z0-9_.-]+)$#', $pointer, $m) && !isset($out['pass'][$m[1]])) {
                // for analytics on the destination, as a plain parameter
                $out['pass'][$m[1]] = $value;
            }
        }
        if (count($continue) === 1) {
            $out['continue_to'] = $continue[0];
        } elseif (count($continue) > 1) {
            $out['ambiguous'] = true;
        }

        return $out;
    }

    /**
     * continue_to, validated as the specification says: decoded once, a single leading "/", no
     * scheme, backslash, whitespace or control character, dot segments removed, re-encoded.
     *
     * @return string|null the canonical path (with its query), or null to use the default
     */
    public static function continueTo($raw)
    {
        $v = rawurldecode((string) $raw);
        if ($v === '' || $v[0] !== '/' || (isset($v[1]) && $v[1] === '/') || preg_match('#[\\\\\s\x00-\x1F\x7F]#', $v) || preg_match('#^/?[A-Za-z][A-Za-z0-9+.-]*:#', $v) || strpos($v, '://') !== false) {
            return null;
        }
        $hash = strpos($v, '#');
        if ($hash !== false) {
            $v = substr($v, 0, $hash);
        }
        $query = '';
        $q = strpos($v, '?');
        if ($q !== false) {
            parse_str(substr($v, $q + 1), $params);
            $query = $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
            $v = substr($v, 0, $q);
        }
        $segments = [];
        foreach (explode('/', substr($v, 1)) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }

        return '/' . implode('/', array_map('rawurlencode', $segments)) . $query;
    }

    /** scheme://host[:port] of an address */
    public static function origin($url)
    {
        $p = parse_url($url);

        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    protected function ensureCart()
    {
        $cart = $this->context->cart;
        if ($cart->id) {
            return;
        }
        // as PrestaShop's cart controller does on a first "add to cart"
        $cart->id_lang = (int) $this->context->language->id;
        $cart->id_currency = (int) $this->context->currency->id;
        $cart->id_shop = (int) $this->context->shop->id;
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->id_guest = (int) $this->context->cookie->id_guest;
        if ($this->context->customer->isLogged()) {
            $cart->id_customer = (int) $this->context->customer->id;
            $cart->secure_key = $this->context->customer->secure_key;
            $cart->id_address_delivery = $cart->id_address_invoice = (int) Address::getFirstCustomerAddressId($cart->id_customer);
        }
        $cart->add();
        if ($cart->id) {
            $this->context->cookie->id_cart = (int) $cart->id;
            $this->context->cookie->write();
        }
    }

    /** One item into the cart: what the shop sells, as much as there is. */
    protected function addItem($idProduct, $idAttribute, $qty, array &$errors)
    {
        $l = function ($s) { return $this->module->l($s, 'buy'); };
        $product = new Product($idProduct, false, (int) $this->context->language->id, (int) $this->context->shop->id);
        if (!Validate::isLoadedObject($product) || !$product->active || !$product->available_for_order || $product->visibility === 'none') {
            $errors[] = sprintf($l('Product %d is not for sale.'), $idProduct);

            return false;
        }
        $name = is_array($product->name) ? reset($product->name) : $product->name;
        if ($product->customizable && Product::getRequiredCustomizableFieldsStatic($idProduct)) {
            $errors[] = sprintf($l('%s needs a personal choice first: add it from its page.'), $name);

            return false;
        }
        if ($idAttribute === 0 && $product->hasAttributes()) {
            $idAttribute = (int) Product::getDefaultAttribute($idProduct);
        } elseif ($idAttribute > 0 && !(int) Db::getInstance()->getValue('SELECT id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . (int) $idAttribute . ' AND id_product = ' . (int) $idProduct)) {
            $errors[] = sprintf($l('%s: that version does not exist.'), $name);

            return false;
        }
        // ProductAttribute since PrestaShop 8, Attribute before
        $attrClass = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';
        $min = $idAttribute ? (int) call_user_func([$attrClass, 'getAttributeMinimalQty'], $idAttribute) : (int) $product->minimal_quantity;
        $qty = max($qty, $min, 1);
        if (!Product::isAvailableWhenOutOfStock((int) StockAvailable::outOfStock($idProduct))) {
            $stock = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttribute);
            if ($stock <= 0) {
                $errors[] = sprintf($l('%s is sold out.'), $name);

                return false;
            }
            if ($stock < $qty) {
                $errors[] = sprintf($l('%1$s: only %2$d left, so %2$d went into the cart.'), $name, $stock);
                $qty = $stock;
            }
        }
        $ok = $this->context->cart->updateQty($qty, $idProduct, $idAttribute, false, 'up', 0, null, true);
        if ($ok !== true) {
            $errors[] = sprintf($l('%s could not be added to the cart.'), $name);

            return false;
        }

        return true;
    }

    protected function addVoucher($code, array &$errors)
    {
        if (!CartRule::isFeatureActive() || !$this->context->cart->id) {
            return;
        }
        $id = (int) CartRule::getIdByCode($code);
        if (!$id) {
            $errors[] = sprintf($this->module->l('The code %s does not exist.', 'buy'), $code);

            return;
        }
        $rule = new CartRule($id, (int) $this->context->language->id);
        $error = $rule->checkValidity($this->context, false, true);
        if ($error) {
            $errors[] = $error;

            return;
        }
        $this->context->cart->addCartRule($id);
    }

    /** What went wrong, shown on the destination page as PrestaShop shows its own messages. */
    protected function notify(array $errors)
    {
        $messages = [];
        foreach ($errors as $e) {
            $messages[] = $e === 'item' ? $this->module->l('Part of the link could not be read and was left out.', 'buy') : $e;
        }
        if (!$messages) {
            return;
        }
        $json = json_encode(['error' => [], 'warning' => array_values(array_unique($messages)), 'success' => [], 'info' => []]);
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['notifications'] = $json;
        }
    }

    /** 303 See Other, never cached, never passing the link on as a referrer. */
    public static function send303($to)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('HTTP/1.1 303 See Other', true, 303);
        header('Location: ' . str_replace(["\r", "\n"], '', $to));
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        if (isset(Context::getContext()->cookie)) {
            Context::getContext()->cookie->write();
        }
        exit;
    }
}
