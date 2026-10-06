<?php
/**
 * SpeedPack Core - database care (devdocs: Scale > Taking care of PrestaShop): the tables that only
 * grow, cleaned of what is older than a chosen age; ANALYZE TABLE for every table of the shop; and
 * a look at the configuration table, which PrestaShop reads on every request.
 *
 * Every cleanup works in batches (one AJAX step each, so no step meets PHP's time limit), never
 * removes anything younger than a week, and never touches orders:
 *   log            the back-office log
 *   connections    visit statistics (connections, their pages and sources)
 *   carts          carts of guests (no customer account) that never became an order
 *   guests         visitor records nothing points to any more, and only ones older than every
 *                  visit still kept – a visitor browsing now is never touched
 *   pagenotfound / statssearch / mail   when those tables exist
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcCare
{
    public const MIN_DAYS = 7;
    public const BATCH = 2000;
    public const ANALYZE_BATCH = 15;

    /** item => [tables it cleans, date column, default age in days] */
    public const ITEMS = [
        'log' => [['log'], 'date_add', 90],
        'connections' => [['connections', 'connections_page', 'connections_source'], 'date_add', 180],
        'carts' => [['cart', 'cart_product', 'cart_cart_rule'], 'date_upd', 60],
        // after visits and carts: a guest goes only once nothing points to it
        'guests' => [['guest'], null, 180],
        'pagenotfound' => [['pagenotfound'], 'date_add', 90],
        'statssearch' => [['statssearch'], 'date_add', 180],
        'mail' => [['mail'], 'date_add', 90],
    ];

    protected static function esc($s)
    {
        return Db::getInstance()->escape((string) $s);
    }

    protected static function t($table)
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    public static function exists($table)
    {
        return (bool) Db::getInstance()->getValue('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \'' . self::esc(_DB_PREFIX_ . $table) . '\'');
    }

    protected static function cutoff($days)
    {
        return date('Y-m-d H:i:s', time() - max(self::MIN_DAYS, (int) $days) * 86400);
    }

    /** @return array table => [rows (estimate), bytes] */
    protected static function sizes(array $tables)
    {
        $names = implode(',', array_map(function ($t) { return '\'' . self::esc(_DB_PREFIX_ . $t) . '\''; }, $tables));
        $out = [];
        foreach ((array) Db::getInstance()->executeS('SELECT table_name AS n, table_rows AS r, data_length + index_length AS b FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name IN (' . $names . ')') as $row) {
            $out[Tools::substr($row['n'], Tools::strlen(_DB_PREFIX_))] = [(int) $row['r'], (int) $row['b']];
        }

        return $out;
    }

    /** What each item holds, and how much of it is older than its age. */
    public static function scan(array $days = [])
    {
        $out = [];
        foreach (self::ITEMS as $item => list($tables, $col, $default)) {
            if (!self::exists($tables[0])) {
                continue;
            }
            $d = isset($days[$item]) ? max(self::MIN_DAYS, (int) $days[$item]) : $default;
            $sizes = self::sizes($tables);
            $bytes = 0;
            foreach ($sizes as $s) {
                $bytes += $s[1];
            }
            $out[$item] = [
                'days' => $d,
                'rows' => isset($sizes[$tables[0]]) ? $sizes[$tables[0]][0] : 0,
                'bytes' => $bytes,
                'size' => SpcHealth::size($bytes),
                'old' => self::count($item, $d),
            ];
        }

        return $out;
    }

    /** How many rows the next cleanup of this item would remove. */
    public static function count($item, $days)
    {
        $db = Db::getInstance();
        $cut = self::esc(self::cutoff($days));
        switch ($item) {
            case 'guests':
                $limit = self::guestLimit($days);

                return $limit ? (int) $db->getValue('SELECT COUNT(*) FROM (' . self::guestQuery($limit, 1000000) . ') g') : 0;
            case 'carts':
                return (int) $db->getValue('SELECT COUNT(*) FROM (' . self::cartQuery($days, 1000000) . ') c');
            default:
                return (int) $db->getValue('SELECT COUNT(*) FROM ' . self::t(self::ITEMS[$item][0][0]) . ' WHERE `' . self::ITEMS[$item][1] . '` < \'' . $cut . '\'');
        }
    }

    /**
     * Guests are only removed below the first guest of the visits that are kept: guests come in
     * order, so everything below that line is older than every visit still in the statistics.
     *
     * @return int 0 when there is no such line (no visits kept: nothing tells old from new)
     */
    protected static function guestLimit($days)
    {
        if (!self::exists('connections')) {
            return 0;
        }

        return (int) Db::getInstance()->getValue('SELECT MIN(id_guest) FROM ' . self::t('connections') . ' WHERE date_add >= \'' . self::esc(self::cutoff($days)) . '\'');
    }

    /** Guests with no visit, no cart and no living customer account behind them. */
    protected static function guestQuery($below, $limit)
    {
        return 'SELECT g.id_guest FROM ' . self::t('guest') . ' g
            LEFT JOIN ' . self::t('connections') . ' c ON (c.id_guest = g.id_guest)
            LEFT JOIN ' . self::t('cart') . ' ca ON (ca.id_guest = g.id_guest)
            LEFT JOIN ' . self::t('customer') . ' cu ON (cu.id_customer = g.id_customer)
            WHERE g.id_guest < ' . (int) $below . ' AND c.id_connections IS NULL AND ca.id_cart IS NULL
            AND (g.id_customer = 0 OR cu.id_customer IS NULL) LIMIT ' . (int) $limit;
    }

    /** Guest carts that never became an order, untouched for $days. */
    protected static function cartQuery($days, $limit)
    {
        return 'SELECT c.id_cart FROM ' . self::t('cart') . ' c
            LEFT JOIN ' . self::t('orders') . ' o ON (o.id_cart = c.id_cart)
            WHERE o.id_order IS NULL AND c.id_customer = 0 AND c.date_upd < \'' . self::esc(self::cutoff($days)) . '\' LIMIT ' . (int) $limit;
    }

    protected static function ids($sql, $key)
    {
        return array_map('intval', array_column((array) Db::getInstance()->executeS($sql), $key));
    }

    /**
     * One batch of one item.
     *
     * @return array ['deleted' => n, 'done' => bool]
     */
    public static function step($item, $days)
    {
        if (!isset(self::ITEMS[$item]) || !self::exists(self::ITEMS[$item][0][0])) {
            return ['deleted' => 0, 'done' => true];
        }
        $db = Db::getInstance();
        $cut = self::esc(self::cutoff($days));
        $deleted = 0;
        switch ($item) {
            case 'connections':
                $ids = self::ids('SELECT id_connections FROM ' . self::t('connections') . ' WHERE date_add < \'' . $cut . '\' LIMIT ' . self::BATCH, 'id_connections');
                if ($ids) {
                    $in = implode(',', $ids);
                    $db->execute('DELETE FROM ' . self::t('connections_page') . ' WHERE id_connections IN (' . $in . ')');
                    if (self::exists('connections_source')) {
                        $db->execute('DELETE FROM ' . self::t('connections_source') . ' WHERE id_connections IN (' . $in . ')');
                    }
                    $db->execute('DELETE FROM ' . self::t('connections') . ' WHERE id_connections IN (' . $in . ')');
                    $deleted = count($ids);
                }
                break;
            case 'guests':
                $below = self::guestLimit($days);
                $ids = $below ? self::ids(self::guestQuery($below, self::BATCH), 'id_guest') : [];
                if ($ids) {
                    $db->execute('DELETE FROM ' . self::t('guest') . ' WHERE id_guest IN (' . implode(',', $ids) . ')');
                    $deleted = count($ids);
                }
                break;
            case 'carts':
                $ids = self::ids(self::cartQuery($days, (int) (self::BATCH / 2)), 'id_cart');
                if ($ids) {
                    $in = implode(',', $ids);
                    $db->execute('DELETE FROM ' . self::t('cart_product') . ' WHERE id_cart IN (' . $in . ')');
                    $db->execute('DELETE FROM ' . self::t('cart_cart_rule') . ' WHERE id_cart IN (' . $in . ')');
                    if (self::exists('customization')) {
                        $cz = self::ids('SELECT id_customization FROM ' . self::t('customization') . ' WHERE id_cart IN (' . $in . ')', 'id_customization');
                        if ($cz) {
                            $db->execute('DELETE FROM ' . self::t('customized_data') . ' WHERE id_customization IN (' . implode(',', $cz) . ')');
                            $db->execute('DELETE FROM ' . self::t('customization') . ' WHERE id_customization IN (' . implode(',', $cz) . ')');
                        }
                    }
                    // prices made for one cart only (cart rules with a gift product)
                    $db->execute('DELETE FROM ' . self::t('specific_price') . ' WHERE id_cart IN (' . $in . ')');
                    $db->execute('DELETE FROM ' . self::t('cart') . ' WHERE id_cart IN (' . $in . ')');
                    $deleted = count($ids);
                }
                break;
            default:
                $table = self::ITEMS[$item][0][0];
                $col = self::ITEMS[$item][1];
                $ids = [];
                $pk = 'id_' . $table;
                $has = (bool) $db->getValue('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = \'' . self::esc(_DB_PREFIX_ . $table) . '\' AND column_name = \'' . self::esc($pk) . '\'');
                if ($has) {
                    $ids = self::ids('SELECT `' . $pk . '` FROM ' . self::t($table) . ' WHERE `' . $col . '` < \'' . $cut . '\' LIMIT ' . self::BATCH, $pk);
                    if ($ids) {
                        $db->execute('DELETE FROM ' . self::t($table) . ' WHERE `' . $pk . '` IN (' . implode(',', $ids) . ')');
                    }
                    $deleted = count($ids);
                } else {
                    $db->execute('DELETE FROM ' . self::t($table) . ' WHERE `' . $col . '` < \'' . $cut . '\' LIMIT ' . self::BATCH);
                    $deleted = (int) $db->Affected_Rows();
                }
        }

        return ['deleted' => $deleted, 'done' => $deleted < ($item === 'carts' ? (int) (self::BATCH / 2) : self::BATCH)];
    }

    /** The shop's tables, for ANALYZE TABLE. */
    public static function tables()
    {
        return array_column((array) Db::getInstance()->executeS('SELECT table_name AS n FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' AND LEFT(table_name, ' . (int) strlen(_DB_PREFIX_) . ') = \'' . self::esc(_DB_PREFIX_) . '\' ORDER BY table_name'), 'n');
    }

    /**
     * ANALYZE TABLE for the next batch of tables: MySQL refreshes what it knows about how the
     * values spread, so it picks the right index (the guide's mysqlcheck -a).
     *
     * @return array ['offset' => next, 'total' => n, 'done' => bool]
     */
    public static function analyze($offset)
    {
        $all = self::tables();
        $batch = array_slice($all, max(0, (int) $offset), self::ANALYZE_BATCH);
        if ($batch) {
            Db::getInstance()->executeS('ANALYZE TABLE ' . implode(', ', array_map(function ($t) { return '`' . str_replace('`', '', $t) . '`'; }, $batch)));
        }
        $next = (int) $offset + count($batch);

        return ['offset' => $next, 'total' => count($all), 'done' => $next >= count($all)];
    }

    /**
     * The configuration table: how big, and its largest values. PrestaShop loads all of it on
     * every request, so modules that store big data there – or left it behind when uninstalled –
     * slow every page.
     */
    public static function configuration()
    {
        $db = Db::getInstance();
        $rows = (int) $db->getValue('SELECT COUNT(*) FROM ' . self::t('configuration'));
        $bytes = (int) $db->getValue('SELECT SUM(LENGTH(name) + IFNULL(LENGTH(value), 0)) FROM ' . self::t('configuration'))
            + (int) $db->getValue('SELECT IFNULL(SUM(IFNULL(LENGTH(value), 0)), 0) FROM ' . self::t('configuration_lang'));
        $largest = [];
        foreach ((array) $db->executeS('SELECT name, LENGTH(value) AS b FROM ' . self::t('configuration') . ' ORDER BY b DESC LIMIT 8') as $r) {
            if (isset($r['name'], $r['b']) && (int) $r['b'] > 1024) {
                $largest[] = ['name' => $r['name'], 'size' => SpcHealth::size((int) $r['b'])];
            }
        }

        return ['rows' => $rows, 'size' => SpcHealth::size($bytes), 'heavy' => $bytes > 1048576 || $rows > 5000, 'largest' => $largest];
    }
}
