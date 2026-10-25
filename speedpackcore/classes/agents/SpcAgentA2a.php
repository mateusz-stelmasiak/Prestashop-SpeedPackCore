<?php
/**
 * The shop's own A2A agent (Agent2Agent 0.3, JSON-RPC over HTTP at /a2a): another agent sends a
 * message ("smoked paprika for 2 people") and gets back matching products with prices, pages and
 * shopping links, as text and as a data part. Sending {"items": [{"id": 12, "quantity": 2}]} in a
 * data part gets one link for all of them. Opening a link puts the products in the person's cart
 * and opens the checkout, where they pay; the agent never orders or pays.
 *
 * No streaming, push notifications or long-running tasks: every answer is a single message.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SpcAgentApi.php';
require_once dirname(__FILE__) . '/SpcAgentMcp.php';

class SpcAgentA2a
{
    public const PROTOCOL = '0.3.0';

    public static function endpoint(Context $context)
    {
        return rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/') . '/a2a';
    }

    /** /.well-known/agent-card.json for the agent above */
    public static function card(Context $context)
    {
        $shop = (string) Configuration::get('PS_SHOP_NAME');
        $base = rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/');
        $url = self::endpoint($context);

        return [
            'protocolVersion' => self::PROTOCOL,
            'name' => $shop . ' shopping assistant',
            'description' => 'Finds products of ' . $shop . ' (' . $base . ') from a plain request and returns prices, availability, product pages and a shopping link the person opens to buy. It never orders or pays.',
            'url' => $url,
            'preferredTransport' => 'JSONRPC',
            'additionalInterfaces' => [['url' => $url, 'transport' => 'JSONRPC']],
            'supportedInterfaces' => [['url' => $url, 'protocolBinding' => 'JSONRPC']],
            'provider' => ['organization' => $shop, 'url' => $base],
            'version' => '1.0.0',
            'documentationUrl' => $base . '/auth.md',
            'capabilities' => ['streaming' => false, 'pushNotifications' => false, 'stateTransitionHistory' => false],
            'defaultInputModes' => ['text/plain', 'application/json'],
            'defaultOutputModes' => ['text/plain', 'application/json'],
            'skills' => [
                [
                    'id' => 'find-products',
                    'name' => 'Find products',
                    'description' => 'Products of ' . $shop . ' matching a request in plain words (English or Polish): name, price, availability, page and a shopping link.',
                    'tags' => ['shopping', 'catalog', 'search'],
                    'examples' => ['smoked paprika', 'przyprawa do kurczaka'],
                    'inputModes' => ['text/plain'],
                    'outputModes' => ['text/plain', 'application/json'],
                ],
                [
                    'id' => 'make-buy-link',
                    'name' => 'Make a shopping link',
                    'description' => 'Send a data part {"items": [{"id": 12, "quantity": 2}]}: one link that puts these products in the person\'s cart and opens the checkout, where they pay.',
                    'tags' => ['shopping', 'cart', 'checkout'],
                    'inputModes' => ['application/json'],
                    'outputModes' => ['text/plain', 'application/json'],
                ],
            ],
        ];
    }

    /** One JSON-RPC message: the answer, or null for a notification. */
    public static function handle(array $msg, Context $context)
    {
        $id = array_key_exists('id', $msg) ? $msg['id'] : null;
        $method = isset($msg['method']) ? (string) $msg['method'] : '';
        $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
        if (!isset($msg['jsonrpc']) || $msg['jsonrpc'] !== '2.0' || $method === '') {
            return SpcAgentMcp::error($id, -32600, 'Invalid Request');
        }
        if ($id === null) {
            return null;
        }
        switch ($method) {
            case 'message/send':
            case 'SendMessage':
                $in = isset($params['message']) && is_array($params['message']) ? $params['message'] : null;
                if ($in === null || !isset($in['parts']) || !is_array($in['parts'])) {
                    return SpcAgentMcp::error($id, -32602, 'Invalid params: message with parts is required');
                }
                $reply = self::reply($in, $context);

                return SpcAgentMcp::result($id, $method === 'SendMessage' ? ['message' => self::v1($reply)] : $reply);
            case 'tasks/get':
            case 'GetTask':
            case 'tasks/cancel':
            case 'CancelTask':
            case 'tasks/resubscribe':
                return SpcAgentMcp::error($id, -32001, 'Task not found: this agent answers with messages, it keeps no tasks');
            case 'message/stream':
            case 'SendStreamingMessage':
                return SpcAgentMcp::error($id, -32004, 'Streaming is not supported; use message/send');
            case 'tasks/pushNotificationConfig/set':
            case 'tasks/pushNotificationConfig/get':
            case 'tasks/pushNotificationConfig/list':
            case 'tasks/pushNotificationConfig/delete':
                return SpcAgentMcp::error($id, -32003, 'Push notifications are not supported');
            case 'agent/getAuthenticatedExtendedCard':
            case 'GetExtendedAgentCard':
                return SpcAgentMcp::error($id, -32007, 'There is no extended card');
            default:
                return SpcAgentMcp::error($id, -32601, 'Method not found');
        }
    }

    /** The answer to one message (A2A 0.3 Message). */
    public static function reply(array $in, Context $context)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $text = '';
        $data = [];
        foreach ($in['parts'] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= ' ' . $part['text'];
            }
            if (isset($part['data']) && is_array($part['data'])) {
                // 0.3: {"kind": "data", "data": {...}}; 1.0: {"data": {"data": {...}}}
                $data = isset($part['data']['data']) && is_array($part['data']['data']) ? $part['data']['data'] : $part['data'];
            }
        }
        $text = Tools::substr(trim($text), 0, 500);
        $out = [];
        $ucp = SpcAgentSettings::on(SpcAgentSettings::K_UCP);
        if (!empty($data['items']) && is_array($data['items'])) {
            $link = SpcAgentMcp::call('make_buy_link', ['items' => $data['items']], $context);
            $out = $link;
            $say = isset($link['url']) ? 'Give the person this link: ' . $link['url'] . "\nOpening it puts the products in their cart and opens the checkout, where they choose delivery and pay."
                : $link['error'];
        } else {
            $query = isset($data['query']) && is_string($data['query']) ? Tools::substr($data['query'], 0, 200) : $text;
            $found = SpcAgentApi::search($context, $idLang, $query, 8);
            $out = ['query' => $query, 'products' => $found];
            if (!$found) {
                $say = $query === '' ? 'Tell me what you are looking for, for example "smoked paprika".'
                    : 'Nothing in ' . Configuration::get('PS_SHOP_NAME') . ' matches "' . $query . '". Try other words, or browse ' . rtrim($context->link->getBaseLink((int) $context->shop->id, true), '/') . '.';
            } else {
                $lines = [];
                foreach ($found as $p) {
                    $lines[] = '- ' . $p['name'] . ' (id ' . $p['id'] . '): ' . number_format($p['price'], 2, '.', '') . ' ' . $p['currency']
                        . ($p['available'] ? '' : ', out of stock') . "\n  " . $p['url'] . ($ucp && $p['available'] ? "\n  buy: " . $p['buy_url'] : '');
                }
                $say = 'Found in ' . Configuration::get('PS_SHOP_NAME') . ":\n" . implode("\n", $lines)
                    . ($ucp ? "\n\nThe buy links put the product in the person's cart and open the checkout, where they pay. For several products at once, send a data part {\"items\": [{\"id\": 12, \"quantity\": 2}]}." : '');
            }
        }
        $msg = [
            'kind' => 'message',
            'messageId' => self::uuid(),
            'role' => 'agent',
            'parts' => [['kind' => 'text', 'text' => $say], ['kind' => 'data', 'data' => $out]],
        ];
        foreach (['contextId', 'taskId'] as $k) {
            if (isset($in[$k]) && is_string($in[$k])) {
                $msg[$k] = Tools::substr($in[$k], 0, 100);
            }
        }
        if (!isset($msg['contextId'])) {
            $msg['contextId'] = self::uuid();
        }

        return $msg;
    }

    /** the same message in A2A 1.0's JSON (no "kind", ROLE_AGENT) */
    protected static function v1(array $m)
    {
        return [
            'messageId' => $m['messageId'],
            'contextId' => $m['contextId'],
            'role' => 'ROLE_AGENT',
            'parts' => [['text' => $m['parts'][0]['text']], ['data' => ['data' => $m['parts'][1]['data']], 'mediaType' => 'application/json']],
        ];
    }

    public static function uuid()
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
