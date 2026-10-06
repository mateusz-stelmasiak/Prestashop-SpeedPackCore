<?php
/**
 * SpeedPack Core - Optimize: WebP and AVIF copies of the shop's pictures, next to the originals.
 *
 * img/p/1/2/12-home_default.jpg gets img/p/1/2/12-home_default.webp (and .avif). A copy is kept
 * only when it is smaller than the original, and it is used only while it is at least as new
 * (a regenerated thumbnail makes its copy stale until it is converted again).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcImages
{
    /** pictures larger than this many pixels are left as they are (memory) */
    public const MAX_PIXELS = 25000000;

    /** What this server can write. */
    public static function formats()
    {
        return [
            'webp' => function_exists('imagewebp') && function_exists('imagecreatefromjpeg'),
            'avif' => function_exists('imageavif') && function_exists('imagecreatefromjpeg'),
        ];
    }

    public static function root()
    {
        return rtrim(defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : '.', '/') . '/';
    }

    /** The copy of a picture in another format. */
    public static function target($src, $format)
    {
        return preg_replace('/\.(jpe?g|png)$/i', '.' . $format, $src);
    }

    /** A copy that can be used: it exists and is not older than the picture. */
    public static function fresh($src, $format)
    {
        $dst = self::target($src, $format);

        return $dst !== $src && is_file($dst) && is_file($src) && filemtime($dst) >= filemtime($src);
    }

    /**
     * Makes the copy. @return int bytes saved (0 when the copy would not be smaller, or failed)
     */
    public static function convert($src, $format, $quality)
    {
        $dst = self::target($src, $format);
        if ($dst === $src || !is_file($src)) {
            return 0;
        }
        if (self::fresh($src, $format)) {
            return 0;
        }
        $info = @getimagesize($src);
        if (!$info || $info[0] * $info[1] > self::MAX_PIXELS || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return 0;
        }
        $im = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
        if (!$im) {
            return 0;
        }
        if ($info[2] === IMAGETYPE_PNG) {
            imagepalettetotruecolor($im);
            imagealphablending($im, false);
            imagesavealpha($im, true);
        }
        $tmp = $dst . '.' . getmypid() . '.tmp';
        $quality = max(30, min(100, (int) $quality));
        $ok = $format === 'avif' ? @imageavif($im, $tmp, $quality, 8) : @imagewebp($im, $tmp, $quality);
        imagedestroy($im);
        clearstatcache();
        $before = (int) filesize($src);
        $after = $ok && is_file($tmp) ? (int) filesize($tmp) : 0;
        if (!$ok || $after <= 0 || $after >= $before) {
            @unlink($tmp);
            @unlink($dst);

            return 0;
        }
        if (!@rename($tmp, $dst)) {
            @unlink($tmp);

            return 0;
        }
        @touch($dst, max(time(), filemtime($src)));

        return $before - $after;
    }

    /** The product picture files of one image (its sizes; the uploaded original is not shown). */
    public static function productFiles($idImage)
    {
        $id = (int) $idImage;
        $dir = self::root() . 'img/p/' . implode('/', str_split((string) $id)) . '/';

        return array_values(array_filter(glob($dir . $id . '-*.{jpg,jpeg,png}', GLOB_BRACE) ?: [], function ($f) {
            return !preg_match('/-\d+x\.(jpe?g|png)$/i', $f);
        }));
    }

    /** Category, brand, supplier and store pictures. */
    public static function otherFiles()
    {
        $out = [];
        foreach (['c', 'm', 'su', 'st'] as $d) {
            foreach (glob(self::root() . 'img/' . $d . '/*-*.{jpg,jpeg,png}', GLOB_BRACE) ?: [] as $f) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /**
     * One step of converting everything: $limit product images from $offset, then the other
     * pictures in one go. @return array offset, total, files, saved, done
     */
    public static function step($offset, $limit, array $formats, $quality)
    {
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image`');
        $out = ['offset' => (int) $offset, 'total' => $total + 1, 'files' => 0, 'saved' => 0, 'done' => false];
        $todo = [];
        if ($offset < $total) {
            $ids = Db::getInstance()->executeS('SELECT id_image FROM `' . _DB_PREFIX_ . 'image` ORDER BY id_image LIMIT ' . (int) $offset . ', ' . (int) $limit);
            foreach ($ids ?: [] as $r) {
                $todo = array_merge($todo, self::productFiles((int) $r['id_image']));
            }
            $out['offset'] = $offset + count($ids ?: []);
        } else {
            $todo = self::otherFiles();
            $out['offset'] = $total + 1;
            $out['done'] = true;
        }
        foreach ($todo as $file) {
            foreach ($formats as $format) {
                $saved = self::convert($file, $format, $quality);
                if ($saved > 0) {
                    ++$out['files'];
                    $out['saved'] += $saved;
                }
            }
        }

        return $out;
    }

    /** A product image deleted: its copies go too. */
    public static function forget($idImage)
    {
        $id = (int) $idImage;
        $dir = self::root() . 'img/p/' . implode('/', str_split((string) $id)) . '/';
        foreach (glob($dir . $id . '-*.{webp,avif}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
    }

    /**
     * The address of the copy of a picture of this shop, when there is a usable one.
     *
     * @param string $url the picture's address as written in the page
     * @param array $formats the formats the browser takes, best first
     * @param string $base the shop's address (https://shop/ with its folder)
     * @param string $host the shop's host
     */
    public static function resolve($url, array $formats, $base, $host)
    {
        $p = parse_url($url);
        if (!$p || !isset($p['path']) || isset($p['query'])) {
            return null;
        }
        if (isset($p['host']) && strcasecmp($p['host'], $host) !== 0) {
            return null;
        }
        $baseUri = (string) parse_url($base, PHP_URL_PATH);
        $path = $p['path'];
        if ($baseUri !== '' && strpos($path, $baseUri) === 0) {
            $path = substr($path, strlen($baseUri));
        }
        $path = ltrim($path, '/');
        if (preg_match('#^img/(p/(?:\d/)+\d+|c/\d+|m/\d+|su/\d+|st/\d+)(-[\w-]+)?\.(jpe?g|png)$#i', $path)) {
            $file = $path;
        } elseif (preg_match('#^(\d+)(-[\w-]+)/[^/]+\.(jpe?g|png)$#i', $path, $m)) {
            // the friendly address of a product picture: /12-home_default/name.jpg
            $file = 'img/p/' . implode('/', str_split($m[1])) . '/' . $m[1] . $m[2] . '.' . $m[3];
        } elseif (preg_match('#^c/(\d+)(-[\w-]+)/[^/]+\.(jpe?g|png)$#i', $path, $m)) {
            $file = 'img/c/' . $m[1] . $m[2] . '.' . $m[3];
        } else {
            return null;
        }
        $root = self::root();
        foreach ($formats as $format) {
            if (self::fresh($root . $file, $format)) {
                return rtrim($base, '/') . '/' . self::target($file, $format);
            }
        }

        return null;
    }
}
