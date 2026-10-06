<?php
require __DIR__ . '/bootstrap.php';
// Reorder against a real MariaDB (the orders and cart tables) with real Smarty for its card: which
// order counts as the last one, what goes into the cart and what is left out, addresses, carrier,
// the checkout saved to open at payment (with PrestaShop's checksum), where the card shows, and
// the card itself.
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'rot_');
define('_PS_MODULE_DIR_', SPC_ROOT . '/');
define('_COOKIE_KEY_', 'abc');
require spc_smarty_autoload();

class Db
{
    static $i; public $pdo;
    static function getInstance($master = true) { if (!self::$i) { self::$i = new Db(); self::$i->pdo = new PDO(getenv('SPC_DB_DSN') ?: 'mysql:host=localhost;dbname=spctest;charset=utf8mb4', getenv('SPC_DB_USER') ?: 'lp', getenv('SPC_DB_PASS') ?: 'lp', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } return self::$i; }
    function execute($sql) { return $this->pdo->exec($sql) !== false; }
    function executeS($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function getRow($sql) { $r = $this->executeS($sql); return $r ? $r[0] : false; }
    function getValue($sql) { $r = $this->getRow($sql); return $r ? reset($r) : false; }
    function Insert_ID() { return (int) $this->pdo->lastInsertId(); }
    function escape($s) { return substr($this->pdo->quote((string) $s), 1, -1); }
}
class Configuration { static $v = []; static function get($k) { return isset(self::$v[$k]) ? self::$v[$k] : false; } static function updateValue($k, $x) { self::$v[$k] = $x; return true; } static function deleteByName($k) { unset(self::$v[$k]); return true; } }
class Tools
{
    static $post = [];
    static function getValue($k, $d = false) { return isset(self::$post[$k]) ? self::$post[$k] : $d; }
    static function isSubmit($k) { return isset(self::$post[$k]); }
    static function strtolower($s) { return mb_strtolower((string) $s); }
    static function getToken($x = true) { return 'tok123'; }
    static function getAdminTokenLite($x) { return 'adm'; }
    static function displayDate($d) { return date('d.m.Y', strtotime($d)); }
}
class Validate { static function isLoadedObject($o) { return is_object($o) && !empty($o->id); } }
class Link { function getModuleLink($m, $c, $p = [], $s = null) { return "https://shop.test/module/$m/$c"; } function getImageLink($rw, $ids, $type = null) { return "https://shop.test/" . explode('-', $ids)[1] . "-$type/$rw.jpg"; } }
class ImageType { static function getFormattedName($n) { return $n . '_default'; } }
class Media { static $defs = []; static function addJsDef($a) { self::$defs = array_merge(self::$defs, $a); } }
class FakeLocale { function formatPrice($a, $iso) { return number_format($a, 2, ',', ' ') . ($iso === 'PLN' ? ' zł' : ' ' . $iso); } }
class Cookie { public $v = ['id_guest' => 9]; function __get($k) { return isset($this->v[$k]) ? $this->v[$k] : null; } function __set($k, $x) { $this->v[$k] = $x; } }
class Customer { public $id = 5, $firstname = 'Anna', $lastname = '', $email = '', $secure_key = 'sk', $logged = true; function isLogged() { return $this->logged; } }
class Ctl { public $php_self = 'index'; public $css = [], $js = []; function registerStylesheet($i, $p, $o = []) { $this->css[$i] = $p; } function registerJavascript($i, $p, $o = []) { $this->js[$i] = $p; } }
class Context
{
    public $link, $customer, $cart, $language, $currency, $shop, $cookie, $smarty, $controller; static $c;
    static function getContext()
    {
        if (!self::$c) {
            $c = self::$c = new Context(); $c->link = new Link(); $c->customer = new Customer(); $c->cart = new Cart();
            $c->language = (object) ['id' => 1]; $c->currency = (object) ['id' => 1, 'iso_code' => 'PLN']; $c->shop = new SpcShopStub(); $c->cookie = new Cookie(); $c->controller = new Ctl();
            $s = $c->smarty = new Smarty(); $s->setCompileDir(SPC_TMP . '/smarty');
            $s->registerPlugin('function', 'l', function ($p) { $t = $p['s']; if (isset($p['sprintf'])) { $t = vsprintf($t, (array) $p['sprintf']); } return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); });
        }
        return self::$c;
    }
    function getCurrentLocale() { return new FakeLocale(); }
    function getTranslator() { return new class { function trans($s, $p = [], $d = '') { return strtr($s === '1 item' ? '1 sztuka' : '%count% sztuk', $p); } }; }
}
class Currency { public $iso_code; function __construct($id) { $this->iso_code = $id == 2 ? 'EUR' : 'PLN'; } }

/** The shop's products: id => [active, available_for_order, stock] */
class Product
{
    static $all = [];
    public $id, $active, $available_for_order;
    static function getTaxCalculationMethod($idCustomer = null) { return 0; }
    function __construct($id, $full = false, $lang = null) { if (isset(self::$all[$id])) { $this->id = $id; $this->active = self::$all[$id][0]; $this->available_for_order = self::$all[$id][1]; } }
}
class Address
{
    static $all = [];  // id => [id_customer, deleted, fields]
    public $id, $id_customer, $deleted, $address1 = '', $address2 = '', $postcode = '', $city = '';
    function __construct($id = null) { if (isset(self::$all[$id])) { $this->id = $id; $this->id_customer = self::$all[$id][0]; $this->deleted = self::$all[$id][1]; foreach (isset(self::$all[$id][2]) ? self::$all[$id][2] : [] as $k => $v) { $this->$k = $v; } } }
    static function getFirstCustomerAddressId($c) { return 0; }
}
class Carrier
{
    static $all = [];  // id => [id_reference, deleted, active]
    public $id, $id_reference, $deleted, $active, $name;
    function __construct($id = null, $lang = null) { if (isset(self::$all[$id])) { $this->id = $id; list($this->id_reference, $this->deleted, $this->active) = self::$all[$id]; $this->name = 'Kurier ' . $id; } }
    static function getCarrierByReference($ref) { foreach (self::$all as $id => $c) { if ($c[0] == $ref && !$c[1]) { return new Carrier($id); } } return false; }
}
/** A cart kept in the real cart_product table, with PrestaShop's stock and minimum checks. */
class Cart
{
    static $deliverable = [];  // carriers that deliver
    public $id, $id_customer, $id_lang, $id_currency, $id_shop, $id_shop_group, $id_guest, $secure_key, $id_address_delivery = 0, $id_address_invoice = 0, $delivery_option = '';
    public $moved = [];
    function add() { Db::getInstance()->execute('INSERT INTO rot_cart (id_customer) VALUES (' . (int) $this->id_customer . ')'); $this->id = Db::getInstance()->Insert_ID(); return true; }
    function update() { return true; }
    function updateAddressId($a, $b) { $this->moved[] = [$a, $b]; }
    function isVirtualCart() { return false; }
    function updateQty($q, $p, $a = null, $c = false, $op = 'up', $addr = 0)
    {
        $stock = Product::$all[$p][2];
        $have = (int) Db::getInstance()->getValue("SELECT SUM(quantity) FROM rot_cart_product WHERE id_cart = {$this->id} AND id_product = $p AND id_product_attribute = " . (int) $a);
        if ($have + $q > $stock) { return false; }
        Db::getInstance()->execute("INSERT INTO rot_cart_product (id_cart, id_product, id_product_attribute, quantity) VALUES ({$this->id}, $p, " . (int) $a . ", $q) ON DUPLICATE KEY UPDATE quantity = quantity + $q");
        return true;
    }
    function getProducts($refresh = false)
    {
        $rows = Db::getInstance()->executeS("SELECT 1 id_shop, id_product, id_product_attribute, quantity cart_quantity, quantity * 10 total_wt FROM rot_cart_product WHERE id_cart = {$this->id} ORDER BY id_product");
        foreach ($rows as &$r) {
            $r += ['name' => 'Produkt ' . $r['id_product'], 'attributes_small' => $r['id_product_attribute'] ? 'Słoik 1 l' : '', 'price_wt' => 10, 'price' => 8, 'total' => $r['cart_quantity'] * 8,
                'link_rewrite' => 'p' . $r['id_product'], 'id_image' => $r['id_product'] == 7 ? '7-70' : $r['id_product'] . '-0'];
        }

        return $rows;
    }
    function getDeliveryOptionList() { $o = []; foreach (self::$deliverable as $c) { $o[$this->id_address_delivery][$c . ','] = []; } return $o; }
    function setDeliveryOption($o) { $this->delivery_option = json_encode($o); }
    public $shipping = 0.0;
    function getDeliveryOption($a = null, $b = false) { return $this->delivery_option ? json_decode($this->delivery_option, true) : false; }
    function getTotalShippingCost($a = null, $b = true) { return $this->shipping; }
}
class AddressChecksum { function generateChecksum($a) { return sha1('a' . $a->id); } }
class CartChecksum
{
    private $a; function __construct(AddressChecksum $a) { $this->a = $a; }
    function generateChecksum($cart) { $s = $cart->id_customer . '|' . $this->a->generateChecksum(new Address($cart->id_address_delivery)); foreach ($cart->getProducts(true) as $p) { $s .= '|' . implode(',', $p); } return sha1($s); }
}
class HelperForm { public $module, $name_controller, $token, $currentIndex, $submit_action, $default_form_language, $fields_value = []; function generateForm($f) { foreach ($f[0]['form']['input'] as $i) { if (!array_key_exists($i['name'], $this->fields_value)) { throw new Exception('no value for ' . $i['name']); } } return '<form:' . $this->submit_action . ':' . count($f[0]['form']['input']) . '>'; } }
class AdminController { static $currentIndex = 'index.php?controller=AdminModules'; }
class Module
{
    public $name = 'speedpackcore', $version = '1.6.0'; static $hooks = [];
    function l($s, $x = null) { return $s; }
    function registerHook($h) { self::$hooks[$h] = 1; return true; } function isRegisteredInHook($h) { return isset(self::$hooks[$h]); }
    function displayConfirmation($s) { return "[ok:$s]"; } function displayError($s) { return "[err:$s]"; }
    function display($file, $tpl) { return Context::getContext()->smarty->fetch(dirname($file) . '/' . $tpl); }
}
require SPC_MODULE . '/classes/SpcFeature.php';
require SPC_MODULE . '/classes/SpcAudit.php';
require SPC_MODULE . '/classes/SpcCartAnswer.php';
require SPC_MODULE . '/classes/SpcReorder.php';

$db = Db::getInstance();
foreach (['orders', 'order_detail', 'product_attribute', 'cart', 'cart_product', 'image_shop', 'product_lang'] as $t) { $db->execute("DROP TABLE IF EXISTS rot_$t"); }
$db->execute('CREATE TABLE rot_orders (id_order INT PRIMARY KEY, id_customer INT, id_shop INT, valid TINYINT, date_add DATETIME, total_paid_tax_incl DECIMAL(20,6), id_currency INT, id_carrier INT, id_address_delivery INT, id_address_invoice INT)');
$db->execute('CREATE TABLE rot_order_detail (id_order_detail INT AUTO_INCREMENT PRIMARY KEY, id_order INT, product_id INT, product_attribute_id INT, product_quantity INT, product_quantity_refunded INT DEFAULT 0, product_name VARCHAR(255), id_customization INT DEFAULT 0)');
$db->execute('CREATE TABLE rot_product_attribute (id_product_attribute INT, id_product INT)');
$db->execute('CREATE TABLE rot_cart (id_cart INT AUTO_INCREMENT PRIMARY KEY, id_customer INT, checkout_session_data MEDIUMTEXT)');
$db->execute('CREATE TABLE rot_image_shop (id_image INT, id_product INT, id_shop INT, cover TINYINT)');
$db->execute('CREATE TABLE rot_product_lang (id_product INT, id_lang INT, id_shop INT, link_rewrite VARCHAR(128))');
$db->execute("INSERT INTO rot_image_shop VALUES (70, 7, 1, 1), (71, 7, 1, 0), (80, 8, 1, 1)");
$db->execute("INSERT INTO rot_product_lang VALUES (7, 1, 1, 'kimchi'), (8, 1, 1, 'zakwas')");
$db->execute('CREATE TABLE rot_cart_product (id_cart INT, id_product INT, id_product_attribute INT, quantity INT, PRIMARY KEY (id_cart, id_product, id_product_attribute))');

// customer 5: an older order, a cancelled newer one (not valid), and the last valid one
$db->execute("INSERT INTO rot_orders VALUES (100, 5, 1, 1, '2026-09-01 10:00:00', 50, 1, 3, 11, 11), (101, 5, 1, 0, '2026-10-04 10:00:00', 99, 1, 3, 11, 11), (102, 5, 1, 1, '2026-10-02 12:00:00', 189.5, 1, 3, 11, 12), (103, 6, 1, 1, '2026-10-05 10:00:00', 10, 1, 3, 13, 13)");
$db->execute("INSERT INTO rot_order_detail (id_order, product_id, product_attribute_id, product_quantity, product_quantity_refunded, product_name, id_customization) VALUES
    (102, 7, 0, 2, 0, 'Kimchi klasyczne', 0), (102, 8, 40, 1, 0, 'Zakwas <b>buraczany</b> - 1 l', 0), (102, 9, 0, 3, 3, 'Refunded', 0),
    (102, 10, 0, 1, 0, 'Grawerowany słoik', 77), (102, 11, 0, 1, 0, 'Pierogi (sold out)', 0), (102, 12, 0, 1, 0, 'Old product', 0), (102, 13, 0, 4, 0, 'Kefir', 0)");
$db->execute('INSERT INTO rot_product_attribute VALUES (40, 8)');
Product::$all = [7 => [1, 1, 10], 8 => [1, 1, 10], 9 => [1, 1, 10], 10 => [1, 1, 10], 11 => [1, 1, 0], 12 => [0, 1, 10], 13 => [1, 1, 10]];
Address::$all = [11 => [5, 0, ['address1' => 'ul. Długa 5', 'postcode' => '00-001', 'city' => 'Warszawa']], 12 => [5, 0, ['address1' => 'Firma Sp. z o.o.', 'address2' => 'ul. Krótka 1', 'postcode' => '30-002', 'city' => 'Kraków']], 13 => [6, 0], 14 => [5, 1]];
Carrier::$all = [3 => [3, 1, 1], 8 => [3, 0, 1], 9 => [9, 0, 1]];  // carrier 3 was edited: now 8
Cart::$deliverable = [8, 9];

// ---------- the last order
ok(SpcReorder::lastOrder(0, 1) === null && SpcReorder::lastOrder(77, 1) === null, 'no customer, or no orders: nothing to repeat');
$o = SpcReorder::lastOrder(5, 1);
ok($o['id_order'] == 102, 'the last valid order (a newer cancelled one does not count)');
ok(array_column($o['lines'], 'name') === ['Kimchi klasyczne', 'Zakwas <b>buraczany</b> - 1 l', 'Pierogi (sold out)', 'Old product', 'Kefir'], 'its lines, without what was refunded or customised');
ok(SpcReorder::lastOrder(5, 2) === null, 'another shop: its own orders only');

// ---------- into the cart
$ctx = Context::getContext();
Configuration::$v[SpcReorder::K_ENABLED] = 1;
$r = SpcReorder::fill($ctx, $o, true);
echo '    ', json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
$cart = $ctx->cart;
ok($cart->id > 0 && $ctx->cookie->v['id_cart'] === $cart->id && $cart->id_customer === 5 && $cart->id_guest === 9 && $cart->secure_key === 'sk', 'no cart yet: one is made for the customer and kept in the cookie');
ok($r['added'] === ['Kimchi klasyczne', 'Zakwas <b>buraczany</b> - 1 l', 'Kefir'] && $r['skipped'] === ['Pierogi (sold out)', 'Old product'], 'available products added; sold out and inactive ones left out, by name');
ok((int) $db->getValue("SELECT quantity FROM rot_cart_product WHERE id_cart = {$cart->id} AND id_product = 8 AND id_product_attribute = 40") === 1 && SpcCartAnswer::count($cart) === 7, 'the same quantities and combinations (7 items)');
ok($r['address'] && $cart->id_address_delivery === 11 && $cart->id_address_invoice === 12, 'the same delivery and invoice addresses');
ok($r['carrier'] && $cart->delivery_option === '{"11":"8,"}', 'the same carrier, as it is now (edited carrier 3 became 8)');
$data = json_decode((string) $db->getValue("SELECT checkout_session_data FROM rot_cart WHERE id_cart = {$cart->id}"), true);
$cs = new CartChecksum(new AddressChecksum());
ok($r['payment'] && $data['checkout-delivery-step']['step_is_complete'] === true && $data['checkout-addresses-step']['use_same_address'] === false && $data['checkout-payment-step']['step_is_complete'] === false, 'checkout saved with personal details, address and delivery done: it opens at payment');
ok($data['checksum'] === $cs->generateChecksum($cart), 'with the checksum of the cart as it is now (PrestaShop restores the steps only when it matches)');

// an existing cart, an address that is no longer the customer's, a carrier that does not deliver
$cart2 = new Cart(); $cart2->id_customer = 5; $cart2->add(); $cart2->id_address_delivery = 11; $cart2->id_address_invoice = 11; $ctx->cart = $cart2;
$o2 = $o; $o2['id_address_delivery'] = 14; $o2['id_address_invoice'] = 13; $o2['id_carrier'] = 9;
Cart::$deliverable = [8];
$r2 = SpcReorder::fill($ctx, $o2, true);
ok($ctx->cart === $cart2 && $cart2->id_address_delivery === 11 && !$r2['address'] && !$r2['carrier'] && !$r2['payment'] && count($r2['added']) === 3, 'a deleted or foreign address and a carrier that no longer delivers: products added, the checkout starts as usual');
$o3 = $o; $o3['id_address_delivery'] = 12; $o3['id_address_invoice'] = 12;
$cart3 = new Cart(); $cart3->id_customer = 5; $cart3->add(); $cart3->id_address_delivery = 11; $ctx->cart = $cart3;
SpcReorder::fill($ctx, $o3, false);
ok($cart3->moved === [[11, 12]] && $cart3->id_address_delivery === 12 && $db->getValue("SELECT checkout_session_data FROM rot_cart WHERE id_cart = {$cart3->id}") === null, 'an existing cart moves to the order\'s address; "straight to payment" off leaves the checkout alone');
Product::$all[7][2] = 0; Product::$all[8][0] = 0; Product::$all[13][1] = 0;
$cart4 = new Cart(); $cart4->id_customer = 5; $cart4->add(); $ctx->cart = $cart4;
$r4 = SpcReorder::fill($ctx, $o, true);
ok($r4['added'] === [] && count($r4['skipped']) === 5 && !$r4['carrier'] && !$r4['payment'], 'nothing available any more: nothing added, nothing set');
Product::$all[7][2] = 10; Product::$all[8][0] = 1; Product::$all[13][1] = 1;

// ---------- the card
$ctx->cart = new Cart();
$part = new SpcReorder(new Module(), $ctx, 'Reorder');
Configuration::$v += [SpcReorder::K_HOME => 1, SpcReorder::K_CART => 1, SpcReorder::K_ACCOUNT => 1, SpcReorder::K_PAYMENT => 1];
$card = $part->card('home');
ok($card['id_order'] === 102 && $card['total'] === '189,50 zł' && $card['date'] === '02.10.2026' && $card['count'] === 5 && $card['more'] === 1 && count($card['list']) === 4 && $card['token'] === 'tok123', 'the card: the last order, its total and date, four products and "1 more"');
$html = $part->show('home');
echo '    ', trim(preg_replace('/\s+/', ' ', strip_tags($html))), "\n";
ok(strpos($html, 'action="https://shop.test/module/speedpackcore/reorder" method="post"') !== false && strpos($html, 'name="token" value="tok123"') !== false && strpos($html, 'data-spc-reorder') !== false, 'a form posting to the reorder endpoint with the shop token');
ok(strpos($html, 'Zakwas &lt;b&gt;buraczany&lt;/b&gt; - 1 l') !== false && strpos($html, '<b>buraczany') === false, 'product names shown as text');
ok(strpos($html, 'Welcome back, Anna!') !== false && strpos($html, 'and 1 more') !== false && strpos($html, 'Straight to payment') !== false, 'greeting, the rest counted, what the tap does');
ok($card['list'][0] === ['name' => 'Kimchi klasyczne', 'qty' => 2, 'src' => 'https://shop.test/70-small_default/kimchi.jpg'] && $card['list'][1]['src'] === 'https://shop.test/80-small_default/zakwas.jpg' && $card['list'][2]['src'] === '' && $card['items'] === 9, 'the list: each product with its picture (its cover) and quantity');
ok(substr_count($html, '<img src="https://shop.test/') === 2 && substr_count($html, 'spc-reorder-noimg') === 2 && strpos($html, '&times;&nbsp;2') !== false, 'pictures where there are some, a quiet square where not, the quantity');
ok(preg_match_all('#<svg width="(\d+)" height="\1"[^>]*fill="none" stroke="currentColor"#', $html) === 2, 'the icons carry their own size and colours (small even before the stylesheet arrives)');
if (getenv('SPC_REORDER_HTML')) { file_put_contents(getenv('SPC_REORDER_HTML'), $html); }
$tile = $part->show('account');
ok(strpos($tile, 'link-item') !== false && strpos($tile, 'Order the same as last time') !== false && strpos($tile, 'spc-reorder-tile') !== false, 'the account tile');
$full = new Cart(); $full->id_customer = 5; $full->add(); $full->updateQty(1, 7); $ctx->cart = $full;
ok($part->show('cart') === '', 'a cart with products: no card');
$ctx->cart = new Cart();
ok(strpos($part->show('cart'), 'spc-reorder--cart') !== false, 'an empty cart: the card');
Configuration::$v[SpcReorder::K_HOME] = 0;
ok($part->show('home') === '' && $part->show('account') !== '', 'each place has its own switch');
Configuration::$v[SpcReorder::K_HOME] = 1;
$ctx->customer->logged = false;
ok($part->card('home') === null, 'signed-out shoppers see nothing');
$ctx->customer->logged = true;
Configuration::$v[SpcReorder::K_ENABLED] = 0;
ok($part->card('home') === null, 'switched off: nothing');
Configuration::$v[SpcReorder::K_ENABLED] = 1;
$ctx->controller->php_self = 'index'; $part->hookActionFrontControllerSetMedia();
ok(isset($ctx->controller->css['spc-reorder']), 'its stylesheet on the home page');
$ctx->controller->css = []; $ctx->controller->php_self = 'product'; $part->hookActionFrontControllerSetMedia();
ok(!$ctx->controller->css, 'and not on a product page');

// ---------- summaries of finished checkout steps
$sc = new Cart(); $sc->id_customer = 5; $sc->add(); $sc->id_address_delivery = 11; $sc->id_address_invoice = 12; $sc->setDeliveryOption([11 => '8,']); $sc->shipping = 30.0; $ctx->cart = $sc;
$ctx->customer->lastname = 'Kowalska'; $ctx->customer->email = 'anna@example.com';
$sum = SpcReorder::summaries($ctx, ['invoice' => 'faktura: %s', 'free' => 'gratis']);
echo '    ', json_encode($sum, JSON_UNESCAPED_UNICODE), "\n";
ok($sum['checkout-personal-information-step'] === 'Anna Kowalska · anna@example.com', 'personal details: name and e-mail');
ok($sum['checkout-addresses-step'] === 'ul. Długa 5, 00-001 Warszawa · faktura: Firma Sp. z o.o. ul. Krótka 1, 30-002 Kraków', 'addresses: delivery, and the invoice one when it differs');
ok($sum['checkout-delivery-step'] === 'Kurier 8 · 30,00 zł', 'delivery: the carrier and its price');
$sc->shipping = 0.0; $sc->id_address_invoice = 11;
$sum = SpcReorder::summaries($ctx, ['invoice' => 'faktura: %s', 'free' => 'gratis']);
ok($sum['checkout-delivery-step'] === 'Kurier 8 · gratis' && $sum['checkout-addresses-step'] === 'ul. Długa 5, 00-001 Warszawa', 'free delivery said so; one address once');
$ctx->controller->php_self = 'order'; Media::$defs = []; $ctx->controller->css = [];
$part->hookActionFrontControllerSetMedia();
ok(isset(Media::$defs['spcCheckout']['checkout-delivery-step'], $ctx->controller->css['spc-checkout']), 'on the checkout: the summaries and their stylesheet (even with the reorder card off)');
$sc->updateQty(2, 7); $sc->updateQty(1, 8, 40); $sc->updateQty(6, 13);
$cl = SpcReorder::cartList($ctx);
echo '    cart list ', json_encode($cl, JSON_UNESCAPED_UNICODE), "\n";
ok($cl['count'] === 9 && $cl['countText'] === '9 sztuk' && count($cl['items']) === 3, 'the checkout list: every line, 9 items in all');
ok($cl['items'][0]['name'] === 'Produkt 7' && $cl['items'][0]['qty'] === 2 && $cl['items'][0]['total'] === '20,00 zł' && $cl['items'][0]['unit'] === '10,00 zł' && $cl['items'][0]['src'] === 'https://shop.test/70-cart_default/p7.jpg', 'a line: name, quantity, price each and in all (with tax), its picture');
ok($cl['items'][1]['attrs'] === 'Słoik 1 l' && $cl['items'][1]['src'] === '', 'its options; no picture when the product has none');
ok(SpcReorder::cartList((object) ['cart' => new Cart()])['items'] === [], 'no cart yet: an empty list');
ok(isset(Media::$defs['spcCheckoutCart']['url'], Media::$defs['spcCheckoutCart']['items']) && strpos(Media::$defs['spcCheckoutCart']['url'], '/cartlist') !== false, 'the checkout gets the list and where to ask again');
if (getenv('SPC_CHECKOUT_CART_JSON')) { file_put_contents(getenv('SPC_CHECKOUT_CART_JSON'), json_encode(['items' => array_merge($cl['items'], $cl['items'], $cl['items']), 'count' => 27, 'countText' => '27 sztuk', 'title' => 'W koszyku', 'more' => 'Pokaż wszystkie (%d)', 'less' => 'Pokaż mniej', 'url' => '/__cartlist'], JSON_UNESCAPED_UNICODE)); }
if (getenv('SPC_CHECKOUT_JSON')) { file_put_contents(getenv('SPC_CHECKOUT_JSON'), json_encode(SpcReorder::summaries($ctx, ['invoice' => 'faktura: %s', 'free' => 'gratis']), JSON_UNESCAPED_UNICODE)); }

// ---------- settings
ok($part->install() && Configuration::$v[SpcReorder::K_ENABLED] === 0 && isset(Module::$hooks['displayHome'], Module::$hooks['displayShoppingCartFooter'], Module::$hooks['displayCustomerAccount']), 'install: off until switched on, three hooks');
Tools::$post = ['submitSpcReorder' => 1, SpcReorder::K_ENABLED => 1, SpcReorder::K_HOME => 0, SpcReorder::K_CART => 1, SpcReorder::K_ACCOUNT => 1, SpcReorder::K_PAYMENT => 0];
$page = $part->getContent();
ok(strpos($page, '[ok:') !== false && strpos($page, '<form:submitSpcReorder:6>') !== false && Configuration::$v[SpcReorder::K_HOME] === 0 && Configuration::$v[SpcReorder::K_PAYMENT] === 0, 'settings saved');
ok($part->summary()['fact'] === 'The last order in the cart in one tap.', 'the overview says what it does');
ok($part->uninstall() && !isset(Configuration::$v[SpcReorder::K_ENABLED]), 'uninstall removes the settings');

foreach (['orders', 'order_detail', 'product_attribute', 'cart', 'cart_product', 'image_shop', 'product_lang'] as $t) { $db->execute("DROP TABLE IF EXISTS rot_$t"); }
echo "ALL OK\n";
