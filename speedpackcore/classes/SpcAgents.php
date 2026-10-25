<?php
/**
 * SpeedPack Core - AI agents: "Get your shop ready for AI agents". One button walks through what
 * an agent-readiness scanner checks (isitagentready: discoverability, content, bot access,
 * discovery, commerce), sets each part up, explains it, and proves it works by asking the shop
 * from the outside. Every part can be switched off again, and everything at once.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/agents/SpcAgentApi.php';
require_once dirname(__FILE__) . '/agents/SpcAgentLlms.php';
require_once dirname(__FILE__) . '/agents/SpcAgentMarkdown.php';
require_once dirname(__FILE__) . '/agents/SpcAgentCheck.php';
require_once dirname(__FILE__) . '/agents/SpcBotAuth.php';
require_once dirname(__FILE__) . '/agents/SpcAgentAcp.php';
require_once dirname(__FILE__) . '/agents/SpcAgentMcp.php';
require_once dirname(__FILE__) . '/agents/SpcAgentA2a.php';

class SpcAgents extends SpcFeature
{
    public $id = 'agents';

    /** the last check: {at, results, score} */
    public const K_LAST = 'SPC_AG_LAST';
    /** the score before the first run, kept for the "before / after" */
    public const K_BEFORE = 'SPC_AG_BEFORE';

    public const SUBMITS = ['submitSpcAgents'];

    /**
     * The run, in order: step => [parts switched on, checks that prove it]
     */
    public const STEPS = [
        'signals' => [[SpcAgentSettings::K_SIGNALS], ['robots', 'contentSignals']],
        'links' => [[SpcAgentSettings::K_LINKS], ['linkHeaders', 'sitemap']],
        'llms' => [[SpcAgentSettings::K_LLMS], ['llms']],
        'markdown' => [[SpcAgentSettings::K_MARKDOWN], ['markdown']],
        'catalog' => [[SpcAgentSettings::K_CATALOG, SpcAgentSettings::K_OPENAPI], ['apiCatalog', 'openapi']],
        'mcp' => [[SpcAgentSettings::K_MCP], ['mcp']],
        'a2a' => [[SpcAgentSettings::K_A2A_AGENT], ['a2a']],
        'skills' => [[SpcAgentSettings::K_SKILLS], ['skills']],
        'webmcp' => [[SpcAgentSettings::K_WEBMCP], ['webmcp']],
        'authmd' => [[SpcAgentSettings::K_AUTHMD], ['authMd']],
        'botauth' => [[SpcAgentSettings::K_WBA], ['webBotAuth']],
        'ucp' => [[SpcAgentSettings::K_UCP], ['ucp']],
        'acp' => [[SpcAgentSettings::K_ACP_CARTS], ['acp']],
        'dnsaid' => [[], ['dnsAid']],
    ];

    public function install()
    {
        return SpcAgentAcp::install();
    }

    /** a text the template escapes itself (l() gives it escaped already) */
    protected function t($string)
    {
        return html_entity_decode($this->l($string), ENT_QUOTES, 'UTF-8');
    }

    public function uninstall()
    {
        $this->switchOff();
        SpcAgentAcp::uninstall();
        foreach ((new ReflectionClass('SpcAgentSettings'))->getConstants() as $name => $value) {
            if (strpos($name, 'K_') === 0) {
                Configuration::deleteByName($value);
            }
        }
        Configuration::deleteByName(self::K_LAST);
        Configuration::deleteByName(self::K_BEFORE);

        return true;
    }

    public function summary()
    {
        $last = json_decode((string) Configuration::get(self::K_LAST), true);
        if (!SpcAgentSettings::active()) {
            return ['on' => false, 'status' => $this->t('Not set up'), 'fact' => $this->t('One click makes the shop readable, searchable and shoppable for AI agents.'), 'level' => 'warning'];
        }

        return ['on' => true, 'status' => $last ? sprintf($this->t('%1$d/%2$d checks pass'), $last['score'][0], $last['score'][1]) : $this->t('On'),
            'fact' => $this->t('AI assistants can find the catalogue, ask about products and hand shoppers a ready cart.'), ];
    }

    /* ------------------------------------------------------------------ *
     *  The run
     * ------------------------------------------------------------------ */

    /** One AJAX call from views/js/agents.js: check, step (one step of the run), off. Answers JSON. */
    public function ajax($op)
    {
        @set_time_limit(120);
        $out = ['ok' => true];
        if ($op === 'check') {
            $out += $this->check();
        } elseif ($op === 'start') {
            // the score before, then everything off: each step switches its own part on
            $before = $this->check();
            if (!SpcAgentSettings::active()) {
                Configuration::updateValue(self::K_BEFORE, json_encode($before['score']));
            }
            Configuration::updateValue(SpcAgentSettings::K_ON, 1);
            foreach (SpcAgentSettings::PARTS as $k) {
                Configuration::updateValue($k, 0);
            }
            // a module that published the same things steps back (its own robots.txt lines...)
            Hook::exec('actionSpeedPackAgentsOn', []);
            $out['before'] = $before['score'];
        } elseif ($op === 'step' && isset(self::STEPS[(string) Tools::getValue('step')])) {
            $out += $this->step((string) Tools::getValue('step'));
        } elseif ($op === 'finish') {
            // llms.txt again, now that it can name the API and the shopping link
            if (SpcAgentLlms::ours()) {
                SpcAgentLlms::write($this->context);
            }
            $out += $this->check();
            $out['before'] = json_decode((string) Configuration::get(self::K_BEFORE), true) ?: [0, $out['score'][1]];
        } elseif ($op === 'off') {
            $this->switchOff();
            $out += $this->check();
        } else {
            $out = ['ok' => false, 'error' => 'unknown'];
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    }

    /** the whole check, kept for the overview */
    public function check()
    {
        $res = SpcAgentCheck::run($this->context);
        $score = SpcAgentCheck::score($res);
        Configuration::updateValue(self::K_LAST, json_encode(['at' => time(), 'results' => $res, 'score' => $score]));

        return ['results' => $res, 'score' => $score];
    }

    /** One step: its parts switched on, written, and proved from the outside. */
    public function step($step)
    {
        list($keys, $checks) = self::STEPS[$step];
        foreach ($keys as $k) {
            Configuration::updateValue($k, 1);
        }
        $note = '';
        switch ($step) {
            case 'signals':
                if (!is_file(_PS_ROOT_DIR_ . '/robots.txt')) {
                    Tools::generateRobotsFile(true);
                }
                break;
            case 'links':
                SpcAgentApi::writeCharset();
                // kept pages were made without the Link headers
                SpcPageCache::flush();
                break;
            case 'webmcp':
                SpcPageCache::flush();
                break;
            case 'llms':
                $other = SpcAgentLlms::otherMaker();
                if ($other !== null) {
                    $note = sprintf($this->t('Kept the llms.txt that %s makes.'), $other);
                } else {
                    SpcAgentLlms::write($this->context);
                }
                break;
            case 'botauth':
                if (!SpcBotAuth::available()) {
                    $note = $this->t('Needs PHP\'s sodium extension, which this server does not have.');
                } else {
                    SpcBotAuth::keyId();
                }
                break;
            case 'acp':
                SpcAgentAcp::install();
                break;
            case 'dnsaid':
                if (SpcCloudflare::configured()) {
                    $r = SpcCloudflare::publishAgents((string) parse_url(SpcAgentCheck::base($this->context), PHP_URL_HOST), _PS_ROOT_DIR_);
                    $note = $r['ok'] ? sprintf($this->t('%d records written at Cloudflare.'), $r['done']) : sprintf($this->t('Cloudflare refused: %s (the token needs "DNS: Edit").'), $r['error']);
                } else {
                    $note = $this->t('Optional: connect Cloudflare (Cloudflare tab) and it is written there. DNS changes take a while to show.');
                }
                break;
        }
        SpcAgentSettings::publish($this->context);
        $res = SpcAgentCheck::run($this->context, $checks);

        return ['step' => $step, 'results' => $res, 'note' => $note];
    }

    /** everything this section published, taken back */
    public function switchOff()
    {
        if (!SpcAgentSettings::active() && !Configuration::get(self::K_LAST)) {
            return;
        }
        Configuration::updateValue(SpcAgentSettings::K_ON, 0);
        SpcAgentSettings::publish($this->context);
        SpcPageCache::flush();
        SpcAgentSettings::writeRobots(true);
        SpcAgentLlms::remove();
    }

    /* ------------------------------------------------------------------ *
     *  Settings
     * ------------------------------------------------------------------ */

    protected function handle()
    {
        if (!Tools::isSubmit('submitSpcAgents')) {
            return '';
        }
        foreach (SpcAgentSettings::PARTS as $k) {
            Configuration::updateValue($k, Tools::getValue($k) ? 1 : 0);
        }
        foreach (SpcAgentSettings::SIGNALS as $k => $sig) {
            Configuration::updateValue($k, in_array(Tools::getValue($k), ['yes', 'no', 'none'], true) ? Tools::getValue($k) : $sig[1]);
        }
        $out = '';
        $revoke = Tools::getValue('acp_revoke');
        if (is_array($revoke) && $revoke) {
            SpcAgentAcp::revoke(array_map('strval', $revoke));
        }
        $name = trim((string) Tools::getValue('acp_new_key'));
        if ($name !== '') {
            SpcAgentAcp::install();
            $out .= '<div class="alert alert-info">' . sprintf(htmlspecialchars(html_entity_decode($this->l('New ACP key for %1$s: %2$s Copy it now: it is not shown again.'), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), '<code>' . SpcAgentAcp::newKey($name) . '</code>') . '</div>';
        }
        if (SpcAgentSettings::active()) {
            SpcAgentSettings::publish($this->context);
            if (SpcAgentSettings::on(SpcAgentSettings::K_LLMS)) {
                SpcAgentLlms::write($this->context);
            } else {
                SpcAgentLlms::remove();
            }
        }

        return $out . $this->displayConfirmation($this->l('Settings updated.'));
    }

    /** the texts of the run, one per step (shown as the step runs) */
    protected function stepTexts()
    {
        return [
            'signals' => [$this->t('Tell AI what it may do with your pages'),
                $this->t('A Content-Signal line goes into robots.txt: search engines and AI assistants may use your pages to answer shoppers, model training is not allowed (you can change each one below).'),
                $this->t('Every AI company reads robots.txt first. Without a signal each one decides for you.'), 'contentsignals.org · robots.txt (RFC 9309)', ],
            'links' => [$this->t('Point every page at your catalogue'),
                $this->t('Each page of the shop now carries Link headers to the API catalog, the API description and llms.txt.'),
                $this->t('An agent that lands on any page finds the way to all your data in one request, without guessing URLs.'), 'RFC 8288 · RFC 9727', ],
            'llms' => [$this->t('Write the shop\'s llms.txt'),
                $this->t('/llms.txt describes the shop in plain Markdown: categories, every product with its price, information pages. Made again every day.'),
                $this->t('Language models read one short file instead of crawling hundreds of pages: what they say about your shop comes from you.'), 'llmstxt.org', ],
            'markdown' => [$this->t('Serve pages to agents in Markdown'),
                $this->t('When an agent asks for Markdown (Accept: text/markdown), it gets the page as clean text: title, description, content, links. Browsers still get HTML.'),
                $this->t('Agents pay per token. A page without menus, scripts and styles costs a fraction to read, so they read more of your shop.'), 'HTTP content negotiation (RFC 9110)', ],
            'catalog' => [$this->t('Publish a catalogue API'),
                $this->t('A public, read-only JSON API of your products (name, price, availability, picture, address), its OpenAPI description at /openapi.json and the API catalog at /.well-known/api-catalog.'),
                $this->t('Assistants that compare products read prices and stock straight from you, always up to date. Only what any visitor sees is in it.'), 'RFC 9727 · OpenAPI 3.1', ],
            'mcp' => [$this->t('Open an MCP server'),
                $this->t('/mcp speaks the Model Context Protocol with three tools: search products, make a shopping link, shop information. Its card is at /.well-known/mcp/server-card.json.'),
                $this->t('MCP is how Claude, ChatGPT, Cursor and many agents plug into tools. Your shop becomes one of them.'), 'Model Context Protocol 2025-06-18', ],
            'a2a' => [$this->t('Put a shop assistant agent online'),
                $this->t('/a2a is an agent of your own: another agent asks it in plain words ("smoked paprika for 2") and gets products, prices and a link to buy. Its card is at /.well-known/agent-card.json.'),
                $this->t('Agent-to-agent is how the next shopping assistants will talk to shops: yours answers.'), 'A2A 0.3 (Linux Foundation)', ],
            'skills' => [$this->t('Teach agents how to shop here'),
                $this->t('A SKILL.md explains, step by step, how to find products here and how to hand a purchase to the person; listed in /.well-known/agent-skills/index.json.'),
                $this->t('Agents that support skills learn the right way once, instead of trying things on your pages.'), 'Agent Skills 0.2', ],
            'webmcp' => [$this->t('Give in-browser agents real buttons'),
                $this->t('In your pages, an AI agent working in the shopper\'s own browser gets four tools: search products, add to cart, view cart, go to checkout.'),
                $this->t('Browser agents stop clicking around your theme: they call the shop\'s own actions, reliably.'), 'WebMCP (W3C draft, Chrome)', ],
            'authmd' => [$this->t('Write the rules of access'),
                $this->t('/auth.md tells agents what needs no key (everything that reads), what does (ACP carts) and the rules: never ask a customer for passwords or card numbers.'),
                $this->t('Agents check this before they act. Clear rules mean fewer blocked or confused agents.'), 'auth.md', ],
            'botauth' => [$this->t('Sign your own bots'),
                $this->t('The shop gets an Ed25519 key; its public half is published, signed, at /.well-known/http-message-signatures-directory, and SpeedPack\'s own cache warmer and speed audit sign their requests with it.'),
                $this->t('Cloudflare and other firewalls can tell your bots from strangers instead of blocking them.'), 'Web Bot Auth · RFC 9421', ],
            'ucp' => [$this->t('Let agents hand over a ready cart'),
                $this->t('/buy/12:2,15:1 puts those products in the shopper\'s cart and opens your checkout; /.well-known/ucp tells agents about it.'),
                $this->t('An assistant that found what the person wants gives them one link: they land in a full cart and pay with your usual payment. The agent never pays or orders.'), 'Universal Commerce Protocol (UCP)', ],
            'acp' => [$this->t('Accept agent-built carts'),
                $this->t('/acp/carts lets agent platforms build a basket with estimated totals and hand the shopper its link. Platforms get a key from you (below); /.well-known/acp.json announces it.'),
                $this->t('The Agentic Commerce Protocol is what ChatGPT\'s shopping uses: carts there end in your checkout.'), 'Agentic Commerce Protocol 2026-04-17', ],
            'dnsaid' => [$this->t('Announce your agents in DNS'),
                $this->t('SVCB records at _mcp._agents and _a2a._agents point to your MCP server and agent, so they can be found from the domain name alone.'),
                $this->t('Optional, and new: it needs your DNS at Cloudflare (Cloudflare tab).'), 'DNS-AID (IETF draft)', ],
        ];
    }

    /** checks shown with a reason they do not apply (so nothing is left unexplained) */
    protected function notApplicable()
    {
        return [
            [$this->t('OAuth / OpenID sign-in for agents'), $this->t('Not needed: everything agents may do here is public or uses a key you give out. Publishing OAuth metadata without a real sign-in server would only mislead them.')],
            [$this->t('AP2, MPP, x402 (agents paying)'), $this->t('These let an agent pay by itself. They need a payment provider that supports them; your shoppers pay on your checkout, so nothing false is published.')],
        ];
    }

    public function getContent()
    {
        $this->context->controller->addCSS($this->module->getPathUri() . 'views/css/agents.css');
        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/agents.js');
        $out = $this->handle();
        $last = json_decode((string) Configuration::get(self::K_LAST), true);
        $names = [
            'robots' => 'robots.txt', 'sitemap' => $this->t('Sitemap'), 'linkHeaders' => $this->t('Link headers'), 'llms' => 'llms.txt', 'markdown' => $this->t('Markdown for agents'),
            'contentSignals' => $this->t('Content signals'), 'webBotAuth' => 'Web Bot Auth', 'apiCatalog' => $this->t('API catalog'), 'openapi' => 'OpenAPI',
            'mcp' => $this->t('MCP server'), 'a2a' => $this->t('A2A agent'), 'skills' => $this->t('Agent skills'), 'webmcp' => 'WebMCP', 'authMd' => 'auth.md',
            'ucp' => $this->t('UCP shopping link'), 'acp' => $this->t('ACP carts'), 'dnsAid' => 'DNS-AID',
        ];
        $groups = ['discoverability' => $this->t('Discoverability'), 'content' => $this->t('Content'), 'bots' => $this->t('Bot access'), 'discovery' => $this->t('Discovery'), 'commerce' => $this->t('Commerce')];
        $keys = [];
        foreach (SpcAgentAcp::keys() as $k) {
            $keys[] = ['hash' => $k['hash'], 'name' => $k['name'], 'hint' => $k['hint'], 'created' => $k['created']];
        }
        $partNames = [
            SpcAgentSettings::K_SIGNALS => $this->t('Content signals in robots.txt'), SpcAgentSettings::K_LINKS => $this->t('Link headers'), SpcAgentSettings::K_LLMS => 'llms.txt',
            SpcAgentSettings::K_MARKDOWN => $this->t('Markdown for agents'), SpcAgentSettings::K_CATALOG => $this->t('Catalogue API and API catalog'), SpcAgentSettings::K_OPENAPI => 'OpenAPI (/openapi.json)',
            SpcAgentSettings::K_MCP => $this->t('MCP server'), SpcAgentSettings::K_A2A_AGENT => $this->t('A2A agent'), SpcAgentSettings::K_SKILLS => $this->t('Agent skill'),
            SpcAgentSettings::K_WEBMCP => 'WebMCP', SpcAgentSettings::K_AUTHMD => 'auth.md', SpcAgentSettings::K_WBA => 'Web Bot Auth',
            SpcAgentSettings::K_UCP => $this->t('UCP shopping link (/buy)'), SpcAgentSettings::K_ACP_CARTS => $this->t('ACP carts'),
        ];
        $parts = [];
        foreach ($partNames as $k => $n) {
            $parts[] = ['key' => $k, 'name' => $n, 'on' => SpcAgentSettings::on($k)];
        }
        $signals = [];
        foreach (SpcAgentSettings::SIGNALS as $k => $s) {
            $v = Configuration::get($k);
            $signals[] = ['key' => $k, 'name' => $s[0], 'value' => $v === false || $v === '' ? $s[1] : (string) $v];
        }
        $base = SpcAgentCheck::base($this->context);
        $base64 = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);

        $optional = array_keys(array_filter(SpcAgentCheck::CHECKS, function ($c) { return $c[1]; }));
        $stepChecks = array_map(function ($s) { return $s[1]; }, self::STEPS);
        // handed to views/js/agents.js as JSON (Smarty here may not call json_encode)
        $json = [
            'steps' => json_encode(array_keys(self::STEPS)), 'stepChecks' => json_encode($stepChecks), 'names' => json_encode($names),
            'optional' => json_encode($optional), 'last' => $last ? json_encode($last) : '',
        ];

        return $out . $this->render('admin/agents.tpl', ['spc_ag' => [
            'json' => $json,
            'active' => SpcAgentSettings::active(),
            'last' => $last,
            'names' => $names,
            'groups' => $groups,
            'checks' => array_map(function ($c) { return $c[0]; }, SpcAgentCheck::CHECKS),
            'optional' => array_keys(array_filter(SpcAgentCheck::CHECKS, function ($c) { return $c[1]; })),
            'steps' => array_keys(self::STEPS),
            'stepChecks' => array_map(function ($s) { return $s[1]; }, self::STEPS),
            'texts' => $this->stepTexts(),
            'na' => $this->notApplicable(),
            'parts' => $parts,
            'signals' => $signals,
            'keys' => $keys,
            'ajax' => $base64 . '&spc_ajax=agents',
            'links' => [
                ['llms.txt', $base . '/llms.txt'], ['API catalog', $base . '/.well-known/api-catalog'], ['OpenAPI', $base . '/openapi.json'],
                ['MCP', $base . '/.well-known/mcp/server-card.json'], ['A2A', $base . '/.well-known/agent-card.json'], ['auth.md', $base . '/auth.md'],
                ['UCP', $base . '/.well-known/ucp'], ['ACP', $base . '/.well-known/acp.json'],
            ],
            'scanner' => 'https://isitagentready.com/?url=' . rawurlencode($base),
            'sodium' => SpcBotAuth::available(),
        ]]);
    }

    /* ------------------------------------------------------------------ *
     *  Front office
     * ------------------------------------------------------------------ */

    /** Link headers and the WebMCP tools on every page (actionFrontControllerSetMedia) */
    public function hookActionFrontControllerSetMedia()
    {
        if (!SpcAgentSettings::active()) {
            return;
        }
        if (!headers_sent()) {
            foreach (SpcAgentApi::linkHeaders($this->context) as $link) {
                header('Link: ' . $link, false);
            }
            if (SpcAgentSettings::on(SpcAgentSettings::K_MARKDOWN)) {
                header('Vary: Accept', false);
            }
        }
        if (SpcAgentSettings::on(SpcAgentSettings::K_WEBMCP) && isset($this->context->controller)) {
            Media::addJsDef(['spcWebMcp' => [
                'products' => SpcAgentApi::url($this->context, 'products'),
                'cart' => $this->context->link->getPageLink('cart', true),
                'order' => $this->context->link->getPageLink('order', true),
                'token' => Tools::getToken(false),
            ]]);
            $this->context->controller->registerJavascript('spc-webmcp', 'modules/' . $this->name . '/views/js/webmcp.js', ['position' => 'bottom', 'priority' => 300, 'attributes' => 'defer']);
        }
        if (SpcAgentLlms::due()) {
            $context = $this->context;
            register_shutdown_function(function () use ($context) {
                SpcWarm::letVisitorGo();
                SpcAgentLlms::write($context);
            });
        }
    }

    /** /buy, /mcp, /a2a, /acp/carts, the key directory */
    public static function routes()
    {
        $p = ['fc' => 'module', 'module' => 'speedpackcore'];

        return [
            'module-speedpackcore-agbuy' => ['controller' => 'agbuy', 'rule' => 'buy{/:items}', 'keywords' => ['items' => ['regexp' => '[A-Za-z0-9._~:,-]*', 'param' => 'items']], 'params' => $p],
            'module-speedpackcore-agmcp' => ['controller' => 'agmcp', 'rule' => 'mcp', 'keywords' => [], 'params' => $p],
            'module-speedpackcore-aga2a' => ['controller' => 'aga2a', 'rule' => 'a2a', 'keywords' => [], 'params' => $p],
            'module-speedpackcore-agacp' => ['controller' => 'agacp', 'rule' => 'acp/carts{/:cart}{/:op}', 'keywords' => [
                'cart' => ['regexp' => 'cart_[a-zA-Z0-9]+', 'param' => 'cart'], 'op' => ['regexp' => 'cancel', 'param' => 'op'], ], 'params' => $p, ],
            'module-speedpackcore-agbotkeys' => ['controller' => 'agbotkeys', 'rule' => '.well-known/http-message-signatures-directory', 'keywords' => [], 'params' => $p],
        ];
    }
}
