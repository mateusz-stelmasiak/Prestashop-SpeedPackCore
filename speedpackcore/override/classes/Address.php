<?php
/**
 * PrestaShop asks "does address N exist?" for every price and tax it works out: each cart line,
 * each total, each carrier. Without a cache that is one query per question, the same
 * SELECT id_address ... repeated dozens of times on one cart page. An address that existed a
 * moment ago still exists, so a yes is remembered for the rest of the request (a no is always
 * asked again, and deleting an address forgets it).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
class Address extends AddressCore
{
    /** @var array id_address => true */
    protected static $spc_exists = [];

    public static function addressExists($id_address, bool $useCache = false)
    {
        $id_address = (int) $id_address;
        // SpeedPack Core: switched off in the module settings
        if (!Configuration::get('SPC_CS_ENABLED') || !Module::isEnabled('speedpackcore')) {
            return parent::addressExists($id_address, $useCache);
        }
        if ($id_address > 0 && isset(self::$spc_exists[$id_address])) {
            return true;
        }
        $exists = parent::addressExists($id_address, $useCache);
        if ($exists && $id_address > 0) {
            self::$spc_exists[$id_address] = true;
        }

        return $exists;
    }

    public function delete()
    {
        unset(self::$spc_exists[(int) $this->id]);

        return parent::delete();
    }
}
