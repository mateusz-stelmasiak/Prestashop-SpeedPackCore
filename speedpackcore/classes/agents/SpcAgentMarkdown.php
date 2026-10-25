<?php
/**
 * SpeedPack Core - AI agents: the shop's pages in Markdown for an agent that asks for it
 * ("Accept: text/markdown", content negotiation). The page PrestaShop built is turned into
 * Markdown: its title and address, then the main content (headings, text, links, lists, tables,
 * pictures' descriptions), without the menu, footer, scripts and forms. Agents read it with a
 * fraction of the tokens the HTML costs. A browser never asks for it, so visitors never see it.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcAgentMarkdown
{
    /** where the page's own content is, in PrestaShop themes (first found wins) */
    public const MAIN = ['//main', '//*[@id="main"]', '//*[@id="content-wrapper"]', '//*[@id="content"]', '//body'];
    public const DROP = ['script', 'style', 'noscript', 'svg', 'nav', 'header', 'footer', 'form', 'iframe', 'template', 'button', 'select', 'input', 'textarea'];

    /** the request prefers Markdown to HTML (q-values honoured) */
    public static function wanted($accept = null)
    {
        $accept = $accept === null ? (isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '') : (string) $accept;
        if (stripos($accept, 'text/markdown') === false) {
            return false;
        }
        $q = ['md' => 0.0, 'html' => 0.0];
        foreach (explode(',', $accept) as $part) {
            $bits = array_map('trim', explode(';', $part));
            $type = strtolower(array_shift($bits));
            $w = 1.0;
            foreach ($bits as $b) {
                if (preg_match('/^q=([0-9.]+)$/i', $b, $m)) {
                    $w = (float) $m[1];
                }
            }
            if ($type === 'text/markdown' || $type === 'text/x-markdown') {
                $q['md'] = max($q['md'], $w);
            } elseif ($type === 'text/html') {
                $q['html'] = max($q['html'], $w);
            }
        }

        return $q['md'] > 0 && $q['md'] >= $q['html'];
    }

    public static function fromHtml($html, $url = '')
    {
        if (!class_exists('DOMDocument')) {
            return trim(strip_tags($html));
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);
        $title = trim((string) ($xp->query('//title')->length ? $xp->query('//title')->item(0)->textContent : ''));
        $canonical = $xp->query('//link[@rel="canonical"]/@href');
        $url = $canonical->length ? $canonical->item(0)->nodeValue : $url;
        $desc = $xp->query('//meta[@name="description"]/@content');
        $main = null;
        foreach (self::MAIN as $q) {
            $n = $xp->query($q);
            if ($n->length) {
                $main = $n->item(0);
                break;
            }
        }
        foreach (self::DROP as $tag) {
            foreach (iterator_to_array($xp->query('.//' . $tag, $main)) as $el) {
                $el->parentNode->removeChild($el);
            }
        }
        // hidden bits (modals, off-screen menus)
        foreach (iterator_to_array($xp->query('.//*[@hidden or @aria-hidden="true" or contains(@class,"modal") or contains(@style,"display:none") or contains(@style,"display: none")]', $main)) as $el) {
            if ($el->parentNode) {
                $el->parentNode->removeChild($el);
            }
        }
        $body = self::block($main, $url);
        $body = preg_replace("/\n{3,}/", "\n\n", trim($body));
        $head = '# ' . self::text($title) . "\n\n" . ($url !== '' ? 'URL: ' . $url . "\n" : '')
            . ($desc->length ? '> ' . self::text($desc->item(0)->nodeValue) . "\n" : '');

        return $head . "\n" . $body . "\n";
    }

    protected static function text($s)
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8')));
    }

    protected static function abs($href, $base)
    {
        $href = trim((string) $href);
        if ($href === '' || preg_match('#^(https?:|mailto:|tel:)#i', $href)) {
            return $href;
        }
        if (strpos($href, '#') === 0 || stripos($href, 'javascript:') === 0) {
            return '';
        }
        $p = parse_url($base);
        if (!$p || empty($p['host'])) {
            return $href;
        }
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

        return strpos($href, '//') === 0 ? $p['scheme'] . ':' . $href : (strpos($href, '/') === 0 ? $origin . $href : $origin . '/' . $href);
    }

    /** the Markdown of a node's children */
    protected static function block($node, $base)
    {
        $out = '';
        foreach ($node ? $node->childNodes : [] as $c) {
            $out .= self::node($c, $base);
        }

        return $out;
    }

    protected static function inline($node, $base)
    {
        return self::text(preg_replace('/\s+/', ' ', self::block($node, $base)));
    }

    protected static function node($n, $base)
    {
        if ($n->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/u', ' ', $n->nodeValue);
        }
        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        $tag = strtolower($n->nodeName);
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $t = self::inline($n, $base);

                return $t === '' ? '' : "\n\n" . str_repeat('#', (int) $tag[1] + ($tag === 'h1' ? 1 : 0)) . ' ' . $t . "\n\n";
            case 'p': case 'div': case 'section': case 'article': case 'aside': case 'figure': case 'dl':
                $b = self::block($n, $base);

                return trim($b) === '' ? '' : "\n\n" . trim($b) . "\n\n";
            case 'br':
                return "\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'strong': case 'b':
                $t = self::inline($n, $base);

                return $t === '' ? '' : ' **' . $t . '** ';
            case 'em': case 'i':
                $t = self::inline($n, $base);

                return $t === '' ? '' : ' _' . $t . '_ ';
            case 'a':
                $t = self::inline($n, $base);
                $href = self::abs($n->getAttribute('href'), $base);
                if ($t === '') {
                    return '';
                }

                return $href === '' ? $t : ' [' . str_replace(['[', ']'], ['(', ')'], $t) . '](' . $href . ') ';
            case 'img':
                $alt = self::text($n->getAttribute('alt'));

                return $alt === '' ? '' : ' ![' . $alt . '](' . self::abs($n->getAttribute('data-src') ?: $n->getAttribute('src'), $base) . ') ';
            case 'ul': case 'ol':
                $out = "\n\n";
                $i = 0;
                foreach ($n->childNodes as $li) {
                    if ($li->nodeType === XML_ELEMENT_NODE && strtolower($li->nodeName) === 'li') {
                        $t = trim(preg_replace("/\n{2,}/", "\n", self::block($li, $base)));
                        if ($t !== '') {
                            $out .= ($tag === 'ol' ? (++$i) . '. ' : '- ') . str_replace("\n", "\n  ", preg_replace('/[ \t]+/', ' ', $t)) . "\n";
                        }
                    }
                }

                return $out . "\n";
            case 'dt':
                return "\n**" . self::inline($n, $base) . '**: ';
            case 'dd':
                return self::inline($n, $base) . "\n";
            case 'table':
                $rows = [];
                foreach ((new DOMXPath($n->ownerDocument))->query('.//tr', $n) as $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $td) {
                        if ($td->nodeType === XML_ELEMENT_NODE && in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                            $cells[] = str_replace('|', '\\|', self::inline($td, $base));
                        }
                    }
                    if ($cells) {
                        $rows[] = '| ' . implode(' | ', $cells) . ' |';
                    }
                }
                if (!$rows) {
                    return '';
                }
                array_splice($rows, 1, 0, '|' . str_repeat(' --- |', substr_count($rows[0], ' | ') + 1));

                return "\n\n" . implode("\n", $rows) . "\n\n";
            case 'blockquote':
                return "\n\n> " . self::inline($n, $base) . "\n\n";
            case 'pre': case 'code':
                return ' `' . trim($n->textContent) . '` ';
            default:
                return self::block($n, $base);
        }
    }
}
