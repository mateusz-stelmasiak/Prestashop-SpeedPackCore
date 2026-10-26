<?php
/**
 * SpeedPack Core - the visitor's own address behind Cloudflare on the back office pages Symfony
 * answers (config/admin/services.yml). They check the employee's sign-in before any module hook
 * runs; with the address replaced only later (actionDispatcherBefore), the IP kept in the cookie
 * at sign-in and the one seen here differ, and "check the cookie's IP" signs the employee out.
 *
 * Done when the listener is made: Symfony makes the request listeners in order of priority
 * (this one first), and PrestaShop's own ones already check the sign-in when they are made.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SpcCloudflare.php';

class SpcCloudflareRealIp
{
    public function __construct()
    {
        SpcCloudflare::realIp();
    }

    public function onKernelRequest()
    {
        // replaced already (__construct); a second call finds the visitor's address and leaves it
        SpcCloudflare::realIp();
    }
}
