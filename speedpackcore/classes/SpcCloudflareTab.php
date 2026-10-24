<?php
/**
 * SpeedPack Core - the Cloudflare tab: whether the shop really sits behind Cloudflare, the
 * visitors' own addresses, the zone settings a PrestaShop shop wants (with one-click changes),
 * development mode, "Under attack", Bot Fight Mode, a purge and a look at the DNS records.
 * The purge that follows the page cache lives in SpcCloudflare.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcCloudflareTab extends SpcFeature
{
    public $id = 'cloudflare';

    /** the forms of this tab (the settings page opens the tab of the form just sent) */
    public const SUBMITS = ['submitSpcCloudflare', 'submitSpcCloudflareTest', 'submitSpcCfRefresh', 'submitSpcCfApply', 'submitSpcCfDev', 'submitSpcCfAttack', 'submitSpcCfBots', 'submitSpcCfPurge', 'submitSpcCfRealIp'];

    public function install()
    {
        return Configuration::updateValue(SpcCloudflare::K_REALIP, 1);
    }

    public function uninstall()
    {
        foreach ([SpcCloudflare::K_REALIP, SpcCloudflare::K_STATE] as $k) {
            Configuration::deleteByName($k);
        }

        return true;
    }

    public function summary()
    {
        if (!SpcCloudflare::configured()) {
            return ['on' => false, 'status' => $this->l('Not connected'), 'fact' => $this->l('Add the zone ID and an API token to check and tune the zone from here.')];
        }
        $state = json_decode((string) Configuration::get(SpcCloudflare::K_STATE), true);
        $fact = $this->l('Purges follow the page cache; the zone settings are checked on this tab.');
        $level = 'ok';
        if (is_array($state)) {
            if (isset($state['behind']) && $state['behind'] === false) {
                $fact = $this->l('The shop does not answer through Cloudflare yet: the domain still points elsewhere.');
                $level = 'warning';
            } elseif (isset($state['settings']['ssl']['value']) && in_array($state['settings']['ssl']['value'], ['off', 'flexible'], true)) {
                $fact = $this->l('SSL is "Flexible": Cloudflare reaches the shop unencrypted. Use "Full (strict)".');
                $level = 'warning';
            }
        }

        return ['on' => true, 'status' => $this->l('Connected'), 'fact' => $fact, 'level' => $level];
    }

    /** A text for a template that escapes it itself (l() gives it HTML-escaped already). */
    protected function t($string)
    {
        return html_entity_decode($this->l($string), ENT_QUOTES, 'UTF-8');
    }

    /** The shop's address, to ask whether it answers through Cloudflare. */
    protected function home()
    {
        return $this->context->link->getPageLink('index', true);
    }

    protected function host()
    {
        return (string) parse_url($this->home(), PHP_URL_HOST);
    }

    public function getContent()
    {
        $this->context->controller->addCSS($this->module->getPathUri() . 'views/css/optimize.css');
        $out = $this->handle();
        $state = SpcCloudflare::configured() ? SpcCloudflare::state(false, $this->home()) : null;
        $cert = $state ? SpcCloudflare::originCertificateValid($this->host(), isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '') : null;
        $names = [
            'ssl' => $this->t('SSL/TLS encryption'),
            'always_use_https' => $this->t('Always use HTTPS'),
            'automatic_https_rewrites' => $this->t('Automatic HTTPS rewrites'),
            'min_tls_version' => $this->t('Minimum TLS version'),
            'brotli' => $this->t('Brotli compression'),
            'http3' => 'HTTP/3',
            'early_hints' => $this->t('Early Hints'),
            'rocket_loader' => 'Rocket Loader',
            'development_mode' => $this->t('Development mode'),
        ];
        $why = [
            'ssl' => $this->t('"Flexible" sends the shop unencrypted from Cloudflare to the server, and PrestaShop with SSL on loops or loses the sign-in. "Full (strict)" checks the server certificate.'),
            'always_use_https' => $this->t('Every http:// address goes to https:// at Cloudflare, before the shop is asked.'),
            'automatic_https_rewrites' => $this->t('Old http:// links to pictures and scripts in content are fetched over https.'),
            'min_tls_version' => $this->t('TLS 1.0 and 1.1 are retired; card payments need 1.2.'),
            'brotli' => $this->t('Smaller CSS, JavaScript and HTML than gzip.'),
            'http3' => $this->t('Faster connections on phones and poor networks.'),
            'early_hints' => $this->t('The browser starts fetching stylesheets while the page is still being built.'),
            'rocket_loader' => $this->t('Rewrites every script: breaks themes, payment modules and the module\'s own deferred scripts. Optimize does the same job safely.'),
            'development_mode' => $this->t('On, Cloudflare caches nothing (it turns itself off after 3 hours).'),
        ];
        $rows = [];
        if ($state && $state['settings']) {
            foreach (SpcCloudflare::RECOMMENDED as $id => $unused) {
                if (!isset($state['settings'][$id])) {
                    continue;
                }
                $want = SpcCloudflare::recommended($id, $cert);
                $now = $state['settings'][$id]['value'];
                $rows[] = ['id' => $id, 'name' => $names[$id], 'now' => $now, 'want' => $want, 'ok' => $now === $want || ($id === 'ssl' && $now === 'strict'),
                    'editable' => $state['settings'][$id]['editable'], 'why' => $why[$id], ];
            }
        }
        $findings = [];
        if ($state && is_array($state['dns'])) {
            $texts = [
                'no_shop' => $this->t('No A, AAAA or CNAME record for %1$s: the shop\'s address is not in this zone.'),
                'shop_grey' => $this->t('%1$s is "DNS only" (grey cloud, to %2$s): visitors reach the server directly, without Cloudflare\'s cache or protection. Switch it to "Proxied".'),
                'mx_proxied' => $this->t('Mail for %1$s goes to %2$s, which is proxied (orange cloud): Cloudflare does not carry e-mail, so mail stops arriving. Point the MX at a "DNS only" record (for example mail.your-domain with the mail server\'s address).'),
            ];
            foreach (SpcCloudflare::dnsFindings($state['dns'], $this->host()) as $f) {
                $findings[] = ['level' => $f[0], 'text' => sprintf($texts[$f[1]], $f[2], $f[3])];
            }
        }
        $last = json_decode((string) Configuration::get(SpcCloudflare::K_LAST), true);
        $out .= $this->render('admin/cloudflare.tpl', ['spc_cf' => [
            'configured' => SpcCloudflare::configured(),
            'state' => $state,
            'rows' => $rows,
            'findings' => $findings,
            'cert' => $cert,
            'host' => $this->host(),
            'ns' => $state && $state['zone'] ? implode(', ', $state['zone']['name_servers']) : '',
            'edge' => isset($_SERVER['SPC_CF_EDGE']) ? (string) $_SERVER['SPC_CF_EDGE'] : '',
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            'last' => is_array($last) ? $last : null,
            'ago' => $state ? max(0, time() - (int) $state['at']) : 0,
            'attack' => $state && isset($state['settings']['security_level']['value']) && $state['settings']['security_level']['value'] === 'under_attack',
            'dev' => $state && isset($state['settings']['development_mode']['value']) && $state['settings']['development_mode']['value'] === 'on',
        ]]);

        return $out . $this->connectionForm() . $this->realIpForm();
    }

    /** The forms of the tab. */
    protected function handle()
    {
        $out = '';
        if (Tools::isSubmit('submitSpcCloudflare') || Tools::isSubmit('submitSpcCloudflareTest')) {
            $zone = trim((string) Tools::getValue(SpcCloudflare::K_ZONE));
            $token = trim((string) Tools::getValue(SpcCloudflare::K_TOKEN));
            if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/i', $zone)) {
                return $this->displayError($this->l('The zone ID is 32 letters and digits (Cloudflare, the domain, Overview, on the right).'));
            }
            Configuration::updateValue(SpcCloudflare::K_ZONE, $zone);
            // the token field shows dots once saved: dots leave the saved token as it is
            if ($token !== '' && strpos($token, '•') === false) {
                Configuration::updateValue(SpcCloudflare::K_TOKEN, $token);
            }
            if ($zone === '') {
                Configuration::updateValue(SpcCloudflare::K_TOKEN, '');
            }
            SpcCloudflare::forget();
            if (Tools::isSubmit('submitSpcCloudflareTest') && SpcCloudflare::configured()) {
                $t = SpcCloudflare::test();

                return $t['ok'] ? $this->displayConfirmation(sprintf($this->l('Cloudflare answers: zone %s. Cleared pages will be purged there too.'), $t['name']))
                    : $this->displayError(sprintf($this->l('Cloudflare did not accept it: %s'), $t['error']));
            }

            return $this->displayConfirmation($this->l('Settings updated.'));
        }
        if (Tools::isSubmit('submitSpcCfRealIp')) {
            Configuration::updateValue(SpcCloudflare::K_REALIP, Tools::getValue(SpcCloudflare::K_REALIP) ? 1 : 0);

            return $this->displayConfirmation($this->l('Settings updated.'));
        }
        if (!SpcCloudflare::configured()) {
            return '';
        }
        if (Tools::isSubmit('submitSpcCfRefresh')) {
            SpcCloudflare::forget();
        }
        if (Tools::isSubmit('submitSpcCfApply')) {
            $cert = SpcCloudflare::originCertificateValid($this->host(), isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '');
            $which = (string) Tools::getValue('submitSpcCfApply');
            $state = SpcCloudflare::state(true, $this->home());
            $failed = [];
            $done = 0;
            foreach (SpcCloudflare::RECOMMENDED as $id => $unused) {
                if (($which !== 'all' && $which !== $id) || !isset($state['settings'][$id])) {
                    continue;
                }
                $want = SpcCloudflare::recommended($id, $cert);
                if ($state['settings'][$id]['value'] === $want || ($id === 'ssl' && $state['settings'][$id]['value'] === 'strict')) {
                    continue;
                }
                $r = SpcCloudflare::setSetting($id, $want);
                if ($r['ok']) {
                    ++$done;
                } else {
                    $failed[] = $id . ': ' . $r['error'];
                }
            }
            $out .= $failed ? $this->displayError(sprintf($this->l('Not changed: %s'), implode('; ', $failed)))
                : $this->displayConfirmation(sprintf($this->l('%d settings changed at Cloudflare.'), $done));
        }
        if (Tools::isSubmit('submitSpcCfDev')) {
            $on = Tools::getValue('submitSpcCfDev') === 'on';
            $r = SpcCloudflare::setSetting('development_mode', $on ? 'on' : 'off');
            $out .= $r['ok'] ? $this->displayConfirmation($on ? $this->l('Development mode on: Cloudflare caches nothing for 3 hours.') : $this->l('Development mode off.'))
                : $this->displayError($r['error']);
        }
        if (Tools::isSubmit('submitSpcCfAttack')) {
            $on = Tools::getValue('submitSpcCfAttack') === 'on';
            $r = SpcCloudflare::setSetting('security_level', $on ? 'under_attack' : 'medium');
            $out .= $r['ok'] ? $this->displayConfirmation($on ? $this->l('"Under attack" on: every visitor passes a short browser check first. Switch it off once the attack is over.') : $this->l('"Under attack" off.'))
                : $this->displayError($r['error']);
        }
        if (Tools::isSubmit('submitSpcCfBots')) {
            $on = Tools::getValue('submitSpcCfBots') === 'on';
            $r = SpcCloudflare::botFight($on);
            $out .= $r['ok'] ? $this->displayConfirmation($on ? $this->l('Bot Fight Mode on.') : $this->l('Bot Fight Mode off.'))
                : $this->displayError(sprintf($this->l('Cloudflare did not change it: %s (the token needs the "Bot Management: Edit" permission).'), $r['error']));
        }
        if (Tools::isSubmit('submitSpcCfPurge')) {
            $r = SpcCloudflare::purgeNow();
            $out .= $r['ok'] ? $this->displayConfirmation($this->l('Cloudflare\'s cache was purged.')) : $this->displayError($r['error']);
        }

        return $out;
    }

    protected function helper($submit)
    {
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = $submit;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');

        return $helper;
    }

    /** The zone and the token, with a test. */
    protected function connectionForm()
    {
        $helper = $this->helper('submitSpcCloudflare');
        $helper->fields_value = [
            SpcCloudflare::K_ZONE => (string) Configuration::get(SpcCloudflare::K_ZONE),
            SpcCloudflare::K_TOKEN => (string) Configuration::get(SpcCloudflare::K_TOKEN) !== '' ? str_repeat('•', 12) : '',
        ];

        return $helper->generateForm([['form' => [
            'id_form' => 'spc-cloudflare',
            'legend' => ['title' => $this->l('Connection'), 'icon' => 'icon-cloud'],
            'description' => $this->l('Cloudflare, the domain, Overview: the zone ID is on the right. The token: My Profile, API Tokens, Create token, with these permissions for the zone: Zone Read, Zone Settings Edit, DNS Read, Cache Purge, and Bot Management Edit for Bot Fight Mode.'),
            'input' => [
                ['type' => 'text', 'name' => SpcCloudflare::K_ZONE, 'label' => $this->l('Zone ID'), 'class' => 'fixed-width-xxl'],
                ['type' => 'text', 'name' => SpcCloudflare::K_TOKEN, 'label' => $this->l('API token'), 'class' => 'fixed-width-xxl', 'desc' => $this->l('Kept on the server; shown as dots once saved.')],
            ],
            'submit' => ['title' => $this->l('Save')],
            'buttons' => [['type' => 'submit', 'name' => 'submitSpcCloudflareTest', 'title' => $this->l('Save and test'), 'icon' => 'process-icon-ok', 'class' => 'pull-left']],
        ]]]);
    }

    /** The visitors' own addresses. */
    protected function realIpForm()
    {
        $helper = $this->helper('submitSpcCfRealIp');
        $helper->fields_value = [SpcCloudflare::K_REALIP => Configuration::get(SpcCloudflare::K_REALIP) === '0' ? 0 : 1];
        $name = SpcCloudflare::K_REALIP;

        return $helper->generateForm([['form' => [
            'id_form' => 'spc-cf-realip',
            'legend' => ['title' => $this->l('Visitors\' own addresses'), 'icon' => 'icon-user'],
            'input' => [['type' => 'switch', 'name' => $name, 'label' => $this->l('Real visitor IP'), 'is_bool' => true,
                'desc' => $this->l('Behind Cloudflare, PrestaShop sees Cloudflare\'s servers instead of visitors: statistics, the maintenance allow list and the back office "check the cookie IP" go wrong. Requests from Cloudflare\'s own addresses get the visitor\'s address back; no one else can fake it. No effect without Cloudflare.'),
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')]], ]],
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }
}
