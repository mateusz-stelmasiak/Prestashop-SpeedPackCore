<?php
/**
 * SpeedPack Core - AI agents: the switches of what the shop publishes for AI agents, and the
 * writing of it (files under /.well-known, /openapi.json, /auth.md, robots.txt's Content-Signal).
 *
 * Nothing is published until the merchant presses "Make my shop agent-ready" on the AI agents tab
 * (K_ON); after that each part is on unless switched off. Only what the shop itself does is
 * published: the catalogue API, MCP, the A2A agent, the shopping link (UCP), ACP carts that hand
 * the person a link to pay on the shop's own checkout. Payment protocols that need an outside
 * provider (AP2 mandates, MPP, x402, delegated ACP checkout) are never declared.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcAgentSettings
{
    /** the master switch: set by the "Make my shop agent-ready" run */
    public const K_ON = 'SPC_AG_ON';
    public const K_LINKS = 'SPC_AG_LINKS';
    public const K_CATALOG = 'SPC_AG_CATALOG';
    public const K_OPENAPI = 'SPC_AG_OPENAPI';
    public const K_AUTHMD = 'SPC_AG_AUTHMD';
    public const K_UCP = 'SPC_AG_UCP';
    public const K_MCP = 'SPC_AG_MCP';
    public const K_SKILLS = 'SPC_AG_SKILLS';
    public const K_WEBMCP = 'SPC_AG_WEBMCP';
    public const K_A2A_AGENT = 'SPC_AG_A2A_AGENT';
    public const K_ACP_CARTS = 'SPC_AG_ACP_CARTS';
    public const K_ACP_KEYS = 'SPC_AG_ACP_KEYS';
    public const K_WBA = 'SPC_AG_WBA';
    public const K_WBA_KEY = 'SPC_AG_WBA_KEY';
    public const K_SIGNALS = 'SPC_AG_SIGNALS';
    public const K_SIG_SEARCH = 'SPC_AG_SIG_SEARCH';
    public const K_SIG_INPUT = 'SPC_AG_SIG_INPUT';
    public const K_SIG_TRAIN = 'SPC_AG_SIG_TRAIN';
    public const K_MARKDOWN = 'SPC_AG_MARKDOWN';
    public const K_LLMS = 'SPC_AG_LLMS';

    /** Content signals (contentsignals.org): key => [signal, default] */
    public const SIGNALS = [self::K_SIG_SEARCH => ['search', 'yes'], self::K_SIG_INPUT => ['ai-input', 'yes'], self::K_SIG_TRAIN => ['ai-train', 'no']];
    public const ROBOTS_START = '# BEGIN SpeedPack content-signal';
    public const ROBOTS_END = '# END SpeedPack content-signal';

    /** the parts, each on once the master switch is on, unless switched off */
    public const PARTS = [self::K_SIGNALS, self::K_LINKS, self::K_LLMS, self::K_MARKDOWN, self::K_CATALOG, self::K_OPENAPI, self::K_MCP, self::K_A2A_AGENT,
        self::K_SKILLS, self::K_WEBMCP, self::K_AUTHMD, self::K_WBA, self::K_UCP, self::K_ACP_CARTS, ];

    public static function active()
    {
        return (int) Configuration::get(self::K_ON) === 1;
    }

    public static function on($key)
    {
        if (!self::active()) {
            return false;
        }
        $v = Configuration::get($key);

        return $v === false || $v === '' || (int) $v === 1;
    }

    /** the shop's own ACP carts service: needs the shopping link for its continue_url */
    public static function acpCartsReady()
    {
        return self::on(self::K_ACP_CARTS) && self::on(self::K_UCP);
    }

    /** "search=yes, ai-input=yes, ai-train=no" (a signal set to "" is left unstated), or '' */
    public static function signalLine()
    {
        if (!self::on(self::K_SIGNALS)) {
            return '';
        }
        $out = [];
        foreach (self::SIGNALS as $k => $s) {
            $v = Configuration::get($k);
            $v = $v === false ? $s[1] : (string) $v;
            if ($v === 'yes' || $v === 'no') {
                $out[] = $s[0] . '=' . $v;
            }
        }

        return implode(', ', $out);
    }

    /**
     * Puts the Content-Signal line in robots.txt, in the "User-agent: *" group PrestaShop writes,
     * between markers so it can be changed and taken out again; the rest of the file stays as it is.
     * Called on save and after PrestaShop writes robots.txt again (SEO & URLs).
     */
    public static function writeRobots($remove = false)
    {
        $file = rtrim(_PS_ROOT_DIR_, '/') . '/robots.txt';
        $current = is_file($file) ? (string) @file_get_contents($file) : '';
        $clean = preg_replace('/' . preg_quote(self::ROBOTS_START, '/') . '.*?' . preg_quote(self::ROBOTS_END, '/') . '\r?\n?/s', '', $current);
        $line = $remove ? '' : self::signalLine();
        if ($line === '' && $clean === $current) {
            return true;
        }
        if ($line !== '') {
            $block = self::ROBOTS_START . "\n"
                . "# Content signals (https://contentsignals.org/): search = search results and links, ai-input = answers made\n"
                . "# from this content (RAG, grounding), ai-train = training AI models.\n"
                . 'Content-Signal: ' . $line . "\n"
                . self::ROBOTS_END . "\n";
            if (preg_match('/^User-agent:\s*\*\s*\r?\n/mi', $clean, $m, PREG_OFFSET_CAPTURE)) {
                $at = $m[0][1] + strlen($m[0][0]);
                $clean = substr($clean, 0, $at) . $block . substr($clean, $at);
            } else {
                $clean = rtrim($clean) . ($clean !== '' ? "\n\n" : '') . "User-agent: *\n" . $block . "Allow: /\n";
            }
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $clean) === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0644);

        return @rename($tmp, $file);
    }

    /* ------------------------------------------------------------------ *
     *  Publishing
     * ------------------------------------------------------------------ */

    /**
     * Writes what is switched on and removes what is not (so switching off unpublishes).
     *
     * @return array file => bool written (true) or removed (false)
     */
    public static function publish(Context $context)
    {
        require_once dirname(__FILE__) . '/SpcAgentAcp.php';
        require_once dirname(__FILE__) . '/SpcAgentA2a.php';
        $root = rtrim(_PS_ROOT_DIR_, '/') . '/';
        $wk = $root . '.well-known/';
        if (!is_dir($wk)) {
            @mkdir($wk, 0755, true);
        }
        $json = function ($data) { return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"; };
        $files = [
            $wk . 'api-catalog' => self::on(self::K_CATALOG) ? $json(SpcAgentApi::catalog($context)) : null,
            $wk . 'ucp' => self::on(self::K_UCP) ? $json(SpcAgentApi::ucpProfile($context)) : null,
            $wk . 'acp.json' => self::acpCartsReady() ? $json(SpcAgentAcp::discovery($context)) : null,
            $wk . 'agent-card.json' => self::on(self::K_A2A_AGENT) ? $json(SpcAgentA2a::card($context)) : null,
            $root . 'openapi.json' => self::on(self::K_OPENAPI) ? $json(SpcAgentApi::openapi($context)) : null,
            $root . 'auth.md' => self::on(self::K_AUTHMD) ? SpcAgentApi::authMd($context) : null,
        ];
        // RFC 9728 metadata of the carts API, the one resource that takes Bearer keys
        $files[$wk . 'oauth-protected-resource/acp'] = self::acpCartsReady() ? $json(SpcAgentAcp::resourceMetadata($context)) : null;
        // the MCP server card and the shopping skill (with its index and digest)
        require_once dirname(__FILE__) . '/SpcAgentMcp.php';
        $files[$wk . 'mcp/server-card.json'] = self::on(self::K_MCP) ? $json(SpcAgentMcp::serverCard($context)) : null;
        $skillName = SpcAgentMcp::slug() . '-shopping';
        $skill = self::on(self::K_SKILLS) ? SpcAgentMcp::skillMd($context) : null;
        foreach (glob($wk . 'agent-skills/*/SKILL.md') ?: [] as $old) {
            if ($skill === null || basename(dirname($old)) !== $skillName) {
                $files[$old] = null; // renamed shop, or switched off
            }
        }
        $files[$wk . 'agent-skills/' . $skillName . '/SKILL.md'] = $skill;
        $files[$wk . 'agent-skills/index.json'] = $skill === null ? null : $json([
            '$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
            'skills' => [[
                'name' => $skillName,
                'type' => 'skill-md',
                'description' => 'Find products of ' . Configuration::get('PS_SHOP_NAME') . ' and help a person buy them: search the catalogue, then give them a link that fills their cart and opens the checkout, where they pay.',
                'url' => '/.well-known/agent-skills/' . $skillName . '/SKILL.md',
                'digest' => 'sha256:' . hash('sha256', $skill),
            ]],
        ]);
        $out = ['robots.txt' => self::writeRobots()];
        foreach ($files as $file => $body) {
            if ($body !== null && !is_dir(dirname($file))) {
                @mkdir(dirname($file), 0755, true);
            }
            if ($body === null) {
                if (is_file($file)) {
                    @unlink($file);
                    @rmdir(dirname($file)); // only when empty
                }
                $out[basename($file)] = false;
                continue;
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $body) !== false && @rename($tmp, $file)) {
                @chmod($file, 0644);
                $out[basename($file)] = true;
            } else {
                @unlink($tmp);
            }
        }

        return $out;
    }
}
