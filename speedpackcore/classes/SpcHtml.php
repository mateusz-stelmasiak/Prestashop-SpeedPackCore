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
                // a theme that marks the main picture lazy holds back the largest paint (LCP)
                $new = preg_replace('#\sloading\s*=\s*(["\']?)lazy\1#i', ' loading="eager"', $new);
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
            // data blocks, modules and scripts of other kinds (delayed ones: spc/delay) stay as they are
            if (($type !== '' && preg_match(self::DATA_TYPES, $type)) || $type === 'module'
                || ($type !== '' && !in_array($type, ['text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript'], true))) {
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

    /**
     * Third-party scripts (trackers, chats, ads) wait for the visitor: those whose address or code
     * holds one of $patterns become type="spc/delay" (an address kept in data-spc-src) and a small
     * loader runs them, in the page's order, on the first touch, key, scroll or pointer move, or
     * after $timeout seconds (0: only then). When Google's tag is among them, a stand-in gtag()
     * and dataLayer come first in the head, so a cookie banner calling gtag() before the visitor
     * moves keeps working (its calls wait in dataLayer, as Google's own snippet would keep them).
     */
    public static function delay($html, array $patterns, $timeout = 0)
    {
        $patterns = array_values(array_filter(array_map('trim', $patterns), 'strlen'));
        $end = strripos($html, '</body>');
        if (!$patterns || $end === false) {
            return $html;
        }
        if (!preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $html;
        }
        $hit = function ($text) use ($patterns) {
            foreach ($patterns as $p) {
                if (stripos($text, $p) !== false) {
                    return true;
                }
            }

            return false;
        };
        $edits = [];
        $google = false;
        foreach ($m as $s) {
            if ($s[0][1] > $end) {
                break;
            }
            $attrs = $s[1][0];
            $type = preg_match('#\stype\s*=\s*["\']?([^"\'\s>]+)#i', ' ' . $attrs, $t) ? strtolower($t[1]) : '';
            if ($type !== '' && !in_array($type, ['text/javascript', 'application/javascript'], true)) {
                continue;
            }
            $src = preg_match('#\ssrc\s*=\s*(["\'])(.*?)\1#is', ' ' . $attrs, $u) ? $u[2] : (preg_match('#\ssrc\s*=\s*([^\s>]+)#i', ' ' . $attrs, $u) ? $u[1] : '');
            $code = $s[2][0];
            if (!($src !== '' ? $hit($src) : (trim($code) !== '' && $hit($code)))) {
                continue;
            }
            $google = $google || preg_match('#googletagmanager|gtag\(|google-analytics#i', $src . $code);
            $rest = preg_replace('#\s+#', ' ', preg_replace('#\s(type|src|async|defer)(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?#i', '', ' ' . $attrs));
            $edits[] = [$s[0][1], '<script type="spc/delay"' . ($src !== '' ? ' data-spc-src="' . htmlspecialchars(html_entity_decode($src, ENT_QUOTES), ENT_QUOTES) . '"' : '') . rtrim($rest) . '>' . $code . '</script>', strlen($s[0][0])];
        }
        if (!$edits) {
            return $html;
        }
        for ($i = count($edits) - 1; $i >= 0; --$i) {
            $html = substr_replace($html, $edits[$i][1], $edits[$i][0], $edits[$i][2]);
        }
        $loader = '<script id="spc-delay">(function(){var done=false,ev=["pointerdown","keydown","touchstart","scroll","wheel","mousemove"];'
            . 'function run(){if(done){return;}done=true;ev.forEach(function(e){window.removeEventListener(e,run,{passive:true});});'
            . 'var list=[].slice.call(document.querySelectorAll(\'script[type="spc/delay"]\'));'
            . '(function next(i){if(i>=list.length){return;}var o=list[i],s=document.createElement("script");'
            . 'for(var k=0;k<o.attributes.length;k++){var a=o.attributes[k];if(a.name!=="type"&&a.name!=="data-spc-src"){s.setAttribute(a.name,a.value);}}'
            . 'var src=o.getAttribute("data-spc-src");if(src){s.src=src;s.async=false;s.onload=s.onerror=function(){next(i+1);};o.parentNode.replaceChild(s,o);}'
            . 'else{s.text=o.text;o.parentNode.replaceChild(s,o);next(i+1);}})(0);}'
            . 'ev.forEach(function(e){window.addEventListener(e,run,{passive:true});});'
            . ((int) $timeout > 0 ? 'setTimeout(run,' . ((int) $timeout * 1000) . ');' : '')
            . '})();</script>';
        $end = strripos($html, '</body>');
        $html = substr($html, 0, $end) . $loader . substr($html, $end);
        if ($google && preg_match('#<head\b[^>]*>#i', $html, $h, PREG_OFFSET_CAPTURE)) {
            $at = $h[0][1] + strlen($h[0][0]);
            $html = substr($html, 0, $at) . '<script>window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};</script>' . substr($html, $at);
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
    public static function fingerprint(array $hrefs, $withQuery = false)
    {
        // the files, not their version numbers (?v=…): a module update that bumps one would turn
        // the critical CSS off until made again (1.7.x counted them: $withQuery)
        $norm = array_map(function ($h) use ($withQuery) {
            $p = parse_url($h);

            return (isset($p['path']) ? $p['path'] : '') . ($withQuery && isset($p['query']) ? '?' . $p['query'] : '');
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
        $hrefs = array_column($sheets, 'href');
        if (trim((string) $css) === '' || !$sheets || (self::fingerprint($hrefs) !== $fingerprint && self::fingerprint($hrefs, true) !== $fingerprint)) {
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
     *  Layout shift, fonts, CDN
     * ------------------------------------------------------------------ */

    /**
     * Pictures without a width and a height get them, from $sizeOf(url) => [w, h] or null, so the
     * browser keeps their room before they arrive (no layout shift, CLS). A rule in the head keeps
     * their height following their width (height: auto), so a picture shown smaller keeps its
     * shape.
     */
    public static function dimensions($html, callable $sizeOf)
    {
        $added = false;
        $html = self::outsideBlocks($html, self::bodyStart($html), function ($part) use ($sizeOf, &$added) {
            return preg_replace_callback('#<img\b[^>]*>#i', function ($m) use ($sizeOf, &$added) {
                $tag = $m[0];
                if (preg_match('#\swidth\s*=#i', $tag) || preg_match('#\sheight\s*=#i', $tag)) {
                    return $tag;
                }
                $src = preg_match('#\s(?:data-src|src)\s*=\s*(["\'])(.*?)\1#i', $tag, $u) ? html_entity_decode($u[2], ENT_QUOTES) : '';
                $size = $src !== '' ? $sizeOf($src) : null;
                if (!$size || (int) $size[0] < 1 || (int) $size[1] < 1) {
                    return $tag;
                }
                $added = true;

                return preg_replace('#^<img\b#i', '<img width="' . (int) $size[0] . '" height="' . (int) $size[1] . '" data-spc-dim', $tag);
            }, $part);
        });
        if ($added && preg_match('#</head>#i', $html, $h, PREG_OFFSET_CAPTURE)) {
            $html = substr_replace($html, '<style id="spc-dim">img[data-spc-dim]{height:auto}</style>', $h[0][1], 0);
        }

        return $html;
    }

    /**
     * Fonts that do not hold the text back: Google Fonts asked with display=swap and their two
     * servers connected to early; with critical CSS, the WOFF2 files its @font-face rules use
     * preloaded (at most $preload), and those rules made font-display: swap.
     */
    public static function fonts($html, $preload = 2)
    {
        $google = false;
        $html = preg_replace_callback('#<link\b[^>]*href\s*=\s*(["\'])(https?:)?//fonts\.googleapis\.com/[^"\']*\1[^>]*>#i', function ($m) use (&$google) {
            $google = true;

            return stripos($m[0], 'display=') !== false ? $m[0] : preg_replace('#(href\s*=\s*["\'][^"\']*)(["\'])#i', '$1&amp;display=swap$2', $m[0], 1);
        }, $html);
        $head = '';
        if ($google && stripos($html, 'preconnect" href="https://fonts.gstatic.com') === false) {
            $head .= '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        }
        if (preg_match('#<style id="spc-critical">(.*?)</style>#is', $html, $c, PREG_OFFSET_CAPTURE)) {
            $css = $c[1][0];
            $files = [];
            $swapped = preg_replace_callback('#@font-face\s*\{([^}]*)\}#i', function ($f) use (&$files) {
                if (preg_match_all('#url\(\s*["\']?([^"\')]+\.woff2)(\?[^"\')]*)?["\']?\s*\)#i', $f[1], $u)) {
                    foreach ($u[1] as $k => $file) {
                        $files[] = $file . $u[2][$k];
                    }
                }

                return stripos($f[1], 'font-display') !== false ? $f[0] : '@font-face{font-display: swap; ' . trim($f[1]) . '}';
            }, $css);
            $html = substr_replace($html, $swapped, $c[1][1], strlen($css));
            foreach (array_slice(array_values(array_unique($files)), 0, (int) $preload) as $file) {
                $head .= '<link rel="preload" href="' . htmlspecialchars($file, ENT_QUOTES) . '" as="font" type="font/woff2" crossorigin>';
            }
        }
        if ($head !== '' && preg_match('#<head\b[^>]*>#i', $html, $h, PREG_OFFSET_CAPTURE)) {
            $html = substr_replace($html, $head, $h[0][1] + strlen($h[0][0]), 0);
        }

        return $html;
    }

    /**
     * The shop's static files (pictures, theme and module CSS, JS, fonts) served from a CDN:
     * their addresses on $base (or starting at the root) move to $cdn, in attributes and inline
     * style url()s, never inside scripts (their addresses are for the shop's AJAX).
     */
    public static function cdn($html, $base, $cdn)
    {
        $base = rtrim((string) $base, '/');
        $cdn = rtrim((string) $cdn, '/');
        if ($cdn === '' || $base === '' || !preg_match('#^https?://#i', $cdn)) {
            return $html;
        }
        $host = preg_quote(preg_replace('#^https?:#i', '', $base), '#');
        // fonts stay on the shop's own address (a CDN would need CORS for them, and the preloaded
        // copy would not be the one the CSS asks for)
        $re = '#(["\'\s,(=])(?:(?:https?:)?' . $host . ')?(/(?:(?:img|themes|modules|js|upload)/[^"\'\s,)<>]+?\.(?:jpe?g|png|gif|webp|avif|svg|ico|css|js|mp4|webm)'
            . '|(?:[a-z]{2}/)?\d+(?:-[a-z0-9_]+)?/[a-z0-9_-]+\.(?:jpe?g|png|gif|webp|avif)|c/[a-z0-9_-]+\.(?:jpe?g|png|gif|webp|avif))(?:\?[^"\'\s,)<>]*)?)(?=["\'\s,)<>])#i';

        // a script's own address (its opening tag only: what it says inside stays)
        $html = preg_replace_callback('#<script\b[^>]*\ssrc\s*=[^>]*>#i', function ($t) use ($re, $cdn) {
            return preg_replace($re, '$1' . $cdn . '$2', $t[0]);
        }, $html);

        return self::outsideBlocks($html, 0, function ($part) use ($re, $cdn) {
            return preg_replace_callback('#<(?:img|source|link|video|audio)\b[^>]*>|\sstyle\s*=\s*(["\']).*?\1#is', function ($t) use ($re, $cdn) {
                // a link that is not a stylesheet, icon or preload is a page: left alone
                if (preg_match('#^<link\b#i', $t[0]) && !preg_match('#\srel\s*=\s*["\']?[^"\'>]*(stylesheet|icon|preload|apple-touch-icon)#i', $t[0])) {
                    return $t[0];
                }

                return preg_replace($re, '$1' . $cdn . '$2', $t[0]);
            }, $part);
        });
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
