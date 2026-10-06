<?php
/**
 * SpeedPack Core - Optimize: what is done to a page on its way out (actionOutputHTMLBefore).
 *
 * Each step takes the page and gives it back changed, or unchanged when it is not sure:
 *   images()   product, category and brand pictures as WebP or AVIF, for browsers that take them
 *   lazy()     pictures and frames below the top of the page load as they come into view; the
 *              main product picture is asked for first
 *   defer()    scripts at the end of the page wait for the page instead of holding it up, and
 *              still run in the page's order
 *   critical() the CSS for the top of the page inline, the rest loaded without holding it up
 *   minify()   comments and runs of white space out (never in pre, textarea, script or style)
 *
 * Plain string work on the HTML PrestaShop produced: no DOM parser, so nothing it does not touch
 * is rewritten.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcHtml
{
    /** attributes that hold picture addresses */
    public const IMAGE_ATTRS = 'src|srcset|data-src|data-srcset|data-image-large-src|data-image-medium-src|data-full-size-image-url|data-zoom-image|data-lazy|data-original';

    /** script types that are not scripts to run */
    public const DATA_TYPES = '#^(application/(ld\+)?json|text/(template|x-template|html|x-handlebars-template|x-tmpl|plain))$#i';

    /** Where <body> starts (0 when there is none). */
    protected static function bodyStart($html)
    {
        $p = stripos($html, '<body');

        return $p === false ? 0 : $p;
    }

    /** Where the page's own content starts: the wrapper, main, or the body. */
    protected static function contentStart($html, $body)
    {
        $best = false;
        foreach (['id="wrapper"', "id='wrapper'", '<main', 'id="content-wrapper"', 'id="main"'] as $needle) {
            $p = stripos($html, $needle, $body);
            if ($p !== false && ($best === false || $p < $best)) {
                $best = $p;
            }
        }

        return $best === false ? $body : $best;
    }

    /** Runs $fn on the parts of $html outside <script>, <style>, <textarea> and <pre>, from $from on. */
    protected static function outsideBlocks($html, $from, callable $fn)
    {
        $head = substr($html, 0, $from);
        $rest = substr($html, $from);
        $parts = preg_split('#(<(script|style|textarea|pre)\b[^>]*>.*?</\2\s*>)#is', $rest, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        $out = '';
        for ($i = 0, $n = count($parts); $i < $n; ++$i) {
            // the split gives text, the block, the block's tag name, text...
            if ($i % 3 === 0) {
                $done = $fn($parts[$i]);
                // a regular expression that gave up (a huge page): that part as it was
                $out .= is_string($done) ? $done : $parts[$i];
            } elseif ($i % 3 === 1) {
                $out .= $parts[$i];
            }
        }

        return $head . $out;
    }

    /* ------------------------------------------------------------------ *
     *  Pictures
     * ------------------------------------------------------------------ */

    /**
     * Picture addresses in the body handed to $resolve(url): it answers the new address (a WebP
     * or AVIF copy that exists) or null to keep the original.
     */
    public static function images($html, callable $resolve)
    {
        return self::outsideBlocks($html, self::bodyStart($html), function ($part) use ($resolve) {
            return preg_replace_callback('#(\s(?:' . self::IMAGE_ATTRS . ')\s*=\s*)(["\'])(.*?)\2#i', function ($m) use ($resolve) {
                $value = $m[3];
                if (stripos($m[1], 'srcset') !== false) {
                    $items = array_map('trim', explode(',', $value));
                    foreach ($items as &$item) {
                        $bits = preg_split('/\s+/', $item, 2);
                        $new = $bits[0] !== '' ? $resolve(html_entity_decode($bits[0], ENT_QUOTES)) : null;
                        if ($new) {
                            $item = htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . (isset($bits[1]) ? ' ' . $bits[1] : '');
                        }
                    }
                    unset($item);
                    $value = implode(', ', $items);
                } else {
                    $new = $resolve(html_entity_decode($value, ENT_QUOTES));
                    if ($new) {
                        $value = htmlspecialchars($new, ENT_QUOTES, 'UTF-8');
                    }
                }

                return $m[1] . $m[2] . $value . $m[2];
            }, $part);
        });
    }

    /**
     * Native lazy loading below the top of the page: the header and the first $skip pictures of
     * the content load at once, the main product picture is asked for first (fetchpriority).
     */
    public static function lazy($html, $skip = 2)
    {
        $body = self::bodyStart($html);
        $content = self::contentStart($html, $body);
        if (!preg_match_all('#<(img|iframe)\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $body)) {
            return $html;
        }
        // tags inside scripts (templates) are not tags of the page
        $blocks = [];
        if (preg_match_all('#<script\b[^>]*>.*?</script\s*>#is', $html, $sm, PREG_OFFSET_CAPTURE, $body)) {
            foreach ($sm[0] as $b) {
                $blocks[] = [$b[1], $b[1] + strlen($b[0])];
            }
        }
        $out = '';
        $last = 0;
        $seen = 0;
        foreach ($m[0] as $i => $hit) {
            list($tag, $pos) = $hit;
            foreach ($blocks as $b) {
                if ($pos > $b[0] && $pos < $b[1]) {
                    continue 2;
                }
            }
            $name = strtolower($m[1][$i][0]);
            $new = $tag;
            $hero = $name === 'img' && preg_match('#class\s*=\s*["\'][^"\']*\b(js-qv-product-cover|product-cover-image|js-product-cover)\b#i', $tag);
            if ($hero) {
                if (!preg_match('#\sfetchpriority\s*=#i', $tag)) {
                    $new = preg_replace('#^<img\b#i', '<img fetchpriority="high"', $new);
                }
            } elseif ($pos >= $content && !preg_match('#\sloading\s*=#i', $tag) && !preg_match('#\sfetchpriority\s*=\s*["\']?high#i', $tag)) {
                if ($name === 'iframe') {
                    $new = preg_replace('#^<iframe\b#i', '<iframe loading="lazy"', $tag);
                } elseif (++$seen > $skip) {
                    $new = preg_replace('#^<img\b#i', '<img loading="lazy"' . (preg_match('#\sdecoding\s*=#i', $tag) ? '' : ' decoding="async"'), $tag);
                }
            }
            $out .= substr($html, $last, $pos - $last) . $new;
            $last = $pos + strlen($tag);
        }

        return $out . substr($html, $last);
    }

    /* ------------------------------------------------------------------ *
     *  Scripts
     * ------------------------------------------------------------------ */

    /**
     * From the first plain external script in the body on, every script waits for the page and
     * they still run in the page's order: external ones get defer, inline ones become deferred
     * scripts of their own (src="data:..."), and the browser runs all deferred scripts in document
     * order before DOMContentLoaded, as it would have run them while reading the page. Scripts that
     * were already async, deferred or modules, and data blocks (JSON-LD, templates), are left as
     * they are. A page with document.write in one of them is left as it is.
     */
    public static function defer($html)
    {
        $body = self::bodyStart($html);
        $end = strripos($html, '</body>');
        if (!$body || $end === false) {
            return $html;
        }
        if (!preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $body)) {
            return $html;
        }
        $started = false;
        $edits = [];
        foreach ($m as $s) {
            $pos = $s[0][1];
            if ($pos > $end) {
                break;
            }
            $attrs = $s[1][0];
            $type = preg_match('#\stype\s*=\s*["\']?([^"\'\s>]+)#i', ' ' . $attrs, $t) ? strtolower($t[1]) : '';
            if (($type !== '' && preg_match(self::DATA_TYPES, $type)) || $type === 'module') {
                continue;
            }
            $external = (bool) preg_match('#\ssrc\s*=#i', ' ' . $attrs);
            $waits = (bool) preg_match('#\s(async|defer)\b#i', ' ' . $attrs);
            if (!$started) {
                if ($external && !$waits) {
                    $started = true;
                } else {
                    continue;
                }
            }
            if (stripos($s[2][0], 'document.write') !== false) {
                return $html;
            }
            if ($external && !$waits) {
                $edits[] = [$pos, '<script defer' . $attrs . '>' . $s[2][0] . '</script>', strlen($s[0][0])];
            } elseif (!$external && trim($s[2][0]) !== '') {
                $clean = preg_replace('#\stype\s*=\s*["\']?[^"\'\s>]*["\']?#i', '', $attrs);
                $edits[] = [$pos, '<script defer' . $clean . ' src="data:text/javascript;charset=utf-8;base64,' . base64_encode($s[2][0]) . '"></script>', strlen($s[0][0])];
            }
        }
        for ($i = count($edits) - 1; $i >= 0; --$i) {
            $html = substr_replace($html, $edits[$i][1], $edits[$i][0], $edits[$i][2]);
        }

        return $html;
    }

    /* ------------------------------------------------------------------ *
     *  CSS
     * ------------------------------------------------------------------ */

    /** The stylesheets of the head, as written (media print left out). */
    public static function stylesheets($html)
    {
        $headEnd = stripos($html, '</head>');
        $head = $headEnd === false ? $html : substr($html, 0, $headEnd);
        preg_match_all('#<link\b[^>]*>#i', $head, $m);
        $out = [];
        foreach ($m[0] as $tag) {
            if (preg_match('#\srel\s*=\s*["\']?stylesheet#i', $tag) && preg_match('#\shref\s*=\s*["\']([^"\']+)#i', $tag, $h)
                && !preg_match('#\smedia\s*=\s*["\']?print#i', $tag)) {
                $out[] = ['tag' => $tag, 'href' => html_entity_decode($h[1], ENT_QUOTES)];
            }
        }

        return $out;
    }

    /** What the critical CSS was made for: the head's stylesheets (combined files change name with their content). */
    public static function fingerprint(array $hrefs)
    {
        $norm = array_map(function ($h) {
            $p = parse_url($h);

            return (isset($p['path']) ? $p['path'] : '') . (isset($p['query']) ? '?' . $p['query'] : '');
        }, $hrefs);

        return sha1(implode('|', $norm));
    }

    /**
     * The critical CSS inline, the stylesheets loaded without holding up the first paint (a
     * preload that turns itself into a stylesheet, with a <noscript> copy). Only when the page has
     * the stylesheets the CSS was made from.
     */
    public static function critical($html, $css, $fingerprint)
    {
        $sheets = self::stylesheets($html);
        if (trim((string) $css) === '' || !$sheets || self::fingerprint(array_column($sheets, 'href')) !== $fingerprint) {
            return $html;
        }
        $css = str_ireplace('</style', '<\/style', $css);
        $first = true;
        foreach ($sheets as $s) {
            $tag = $s['tag'];
            $preload = preg_replace('#\srel\s*=\s*(["\'])?stylesheet\1?#i', ' rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"', $tag);
            $new = ($first ? '<style id="spc-critical">' . $css . '</style>' : '') . $preload . '<noscript>' . $tag . '</noscript>';
            $first = false;
            $at = strpos($html, $tag);
            if ($at !== false) {
                $html = substr_replace($html, $new, $at, strlen($tag));
            }
        }

        return $html;
    }

    /* ------------------------------------------------------------------ *
     *  Minify
     * ------------------------------------------------------------------ */

    /** Comments and runs of white space out of the HTML (not in pre, textarea, script, style). */
    public static function minify($html)
    {
        return self::outsideBlocks($html, 0, function ($part) {
            $part = preg_replace('#<!--(?!\[if|\s*/?ko\b|\s*email_off|\s*googleoff|\s*googleon).*?-->#s', '', $part);

            return preg_replace('#\s{2,}#', ' ', str_replace(["\r", "\t"], ' ', $part));
        });
    }
}
