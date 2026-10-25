<?php
/**
 * Web Bot Auth: the shop's own bots (SpeedPack's cache warmer and speed audit) sign their requests
 * with an Ed25519 key (HTTP Message Signatures, RFC 9421), and the public key is published at
 * /.well-known/http-message-signatures-directory, itself signed (draft-meunier-http-message-
 * signatures-directory), so a site or CDN receiving those requests can tell they really come from
 * this shop.
 *
 * The key pair is made with libsodium on first use and kept in the configuration.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SpcAgentSettings.php';

class SpcBotAuth
{
    public const MEDIA_TYPE = 'application/http-message-signatures-directory+json';
    public const PATH = '/.well-known/http-message-signatures-directory';
    public const TTL = 300;

    public static function available()
    {
        return function_exists('sodium_crypto_sign_keypair');
    }

    public static function ready()
    {
        return SpcAgentSettings::on(SpcAgentSettings::K_WBA) && self::available();
    }

    public static function b64url($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** [secret key, public key], made once */
    protected static function keyPair()
    {
        $stored = base64_decode((string) Configuration::getGlobalValue(SpcAgentSettings::K_WBA_KEY), true);
        if ($stored === false || strlen($stored) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $pair = sodium_crypto_sign_keypair();
            $stored = sodium_crypto_sign_secretkey($pair);
            Configuration::updateGlobalValue(SpcAgentSettings::K_WBA_KEY, base64_encode($stored));
        }

        return [$stored, sodium_crypto_sign_publickey_from_secretkey($stored)];
    }

    /** a new key: what was signed with the old one no longer verifies */
    public static function rotate()
    {
        Configuration::deleteByName(SpcAgentSettings::K_WBA_KEY);

        return self::keyPair();
    }

    public static function jwk()
    {
        $pk = self::keyPair()[1];

        return ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => self::b64url($pk)];
    }

    /** RFC 7638 thumbprint of the JWK: the keyid */
    public static function keyId()
    {
        $j = self::jwk();

        return self::b64url(hash('sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $j['x'] . '"}', true));
    }

    public static function directory()
    {
        return ['keys' => [self::jwk() + ['kid' => self::keyId(), 'alg' => 'EdDSA', 'use' => 'sig']]];
    }

    /** "sig1=..." for the Signature-Agent header: where the directory is */
    public static function agent(Context $context)
    {
        return 'sig1="' . rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/') . '";type=directory';
    }

    protected static function sign($base)
    {
        return base64_encode(sodium_crypto_sign_detached($base, self::keyPair()[0]));
    }

    /** RFC 9421 serialisation of the parameters (order kept) */
    protected static function params(array $components, array $p)
    {
        $s = '(' . implode(' ', $components) . ')';
        foreach ($p as $k => $v) {
            $s .= ';' . $k . '=' . (is_int($v) ? $v : '"' . $v . '"');
        }

        return $s;
    }

    /**
     * The headers of the directory's answer: Content-Type, Content-Digest and the signature over
     * the request's authority and the digest, as the draft asks (tag http-message-signatures-directory).
     *
     * @return array [body, headers name => value]
     */
    public static function directoryResponse($authority)
    {
        $body = json_encode(self::directory(), JSON_UNESCAPED_SLASHES);
        $digest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
        $created = time();
        $components = ['"@authority";req', '"content-digest"'];
        $p = self::params($components, ['created' => $created, 'expires' => $created + self::TTL, 'keyid' => self::keyId(), 'alg' => 'ed25519', 'tag' => 'http-message-signatures-directory']);
        $base = '"@authority";req: ' . Tools::strtolower($authority) . "\n"
            . '"content-digest": ' . $digest . "\n"
            . '"@signature-params": ' . $p;

        return [$body, [
            'Content-Type' => self::MEDIA_TYPE,
            'Content-Digest' => $digest,
            'Signature-Input' => 'binding0=' . $p,
            'Signature' => 'binding0=:' . self::sign($base) . ':',
            'Cache-Control' => 'max-age=' . (self::TTL - 60),
        ]];
    }

    /**
     * Headers that sign a request the shop's bot makes (tag web-bot-auth): Signature-Agent,
     * Signature-Input and Signature over the target's authority and Signature-Agent. Empty when
     * switched off.
     *
     * @return array name => value
     */
    public static function requestHeaders($url, Context $context = null)
    {
        if (!self::ready()) {
            return [];
        }
        $context = $context ?: Context::getContext();
        $u = parse_url((string) $url);
        if (empty($u['host'])) {
            return [];
        }
        $scheme = isset($u['scheme']) ? Tools::strtolower($u['scheme']) : 'https';
        $authority = Tools::strtolower($u['host']);
        if (isset($u['port']) && !($scheme === 'https' && (int) $u['port'] === 443) && !($scheme === 'http' && (int) $u['port'] === 80)) {
            $authority .= ':' . (int) $u['port'];
        }
        $agent = self::agent($context);
        $created = time();
        // Signature-Agent is a dictionary: the signature covers its member sig1
        $p = self::params(['"@authority"', '"signature-agent";key="sig1"'], ['created' => $created, 'expires' => $created + self::TTL, 'keyid' => self::keyId(), 'alg' => 'ed25519',
            'nonce' => base64_encode(random_bytes(64)), 'tag' => 'web-bot-auth', ]);
        $base = '"@authority": ' . $authority . "\n" . '"signature-agent";key="sig1": ' . substr($agent, 5) . "\n" . '"@signature-params": ' . $p;

        return ['Signature-Agent' => $agent, 'Signature-Input' => 'sig1=' . $p, 'Signature' => 'sig1=:' . self::sign($base) . ':'];
    }

    /** the same as "Name: value" lines, for curl and stream contexts */
    public static function requestHeaderLines($url, Context $context = null)
    {
        $out = [];
        foreach (self::requestHeaders($url, $context) as $k => $v) {
            $out[] = $k . ': ' . $v;
        }

        return $out;
    }
}
