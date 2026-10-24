<?php
/**
 * SpeedPack Core - Optimize: the shop's pictures themselves made smaller (JPEG and PNG), for
 * every browser and every place they go (feeds, e-mails, marketplaces), not only the WebP copies.
 *
 *   - "on this server": JPEG saved again with PHP GD at the quality set (progressive, without the
 *     camera data), PNG saved again at the highest lossless compression;
 *   - "TinyPNG": the picture sent to the TinyPNG / Tinify API with the shop's own key (500
 *     pictures a month free) and its answer kept.
 *
 * A picture is replaced only when the result is at least MIN_GAIN smaller. The original is kept
 * first in var/speedpack-originals/ (same path as under the shop), so "Restore originals" puts
 * every picture back as it was. The picture keeps its date, so the WebP and AVIF copies made from
 * the original stay in use; a picture PrestaShop makes again (new date) counts as not compressed.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcCompress
{
    public const K_ENGINE = 'SPC_OPT_COMPRESS_ENGINE';
    public const K_KEY = 'SPC_OPT_TINYPNG_KEY';
    public const K_QUALITY = 'SPC_OPT_COMPRESS_QUALITY';
    public const K_AUTO = 'SPC_OPT_COMPRESS_AUTO';
    public const K_STATS = 'SPC_OPT_COMPRESS_STATS';
    public const K_TINY_COUNT = 'SPC_OPT_TINYPNG_COUNT';

    /** a result less than 3% smaller is not worth a second generation of JPEG */
    public const MIN_GAIN = 0.03;

    /** pictures larger than this are left as they are (memory, API limits) */
    public const MAX_BYTES = 5000000;

    public static $api = 'https://api.tinify.com';

    /** @var callable|null tests: stands in for the TinyPNG call (path => compressed bytes, or an error) */
    public static $tinify;

    public static function engine()
    {
        return Configuration::get(self::K_ENGINE) === 'tinypng' ? 'tinypng' : 'local';
    }

    public static function quality()
    {
        $q = (int) Configuration::get(self::K_QUALITY);

        return $q >= 40 && $q <= 95 ? $q : 82;
    }

    /** Whether this server can do it at all, per engine. */
    public static function can()
    {
        return [
            'local' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'tinypng' => function_exists('curl_init') && (string) Configuration::get(self::K_KEY) !== '',
        ];
    }

    public static function backupRoot()
    {
        return SpcImages::root() . 'var/speedpack-originals/';
    }

    /** Where the original of a picture is kept. */
    public static function backup($file)
    {
        $root = SpcImages::root();
        $rel = strpos($file, $root) === 0 ? substr($file, strlen($root)) : ltrim($file, '/');

        return self::backupRoot() . $rel;
    }

    /** Compressed by us and not made again by PrestaShop since. */
    public static function done($file)
    {
        $b = self::backup($file);
        clearstatcache();

        return is_file($b) && is_file($file) && filemtime($file) === filemtime($b) && filesize($file) < filesize($b);
    }

    /**
     * Makes one picture smaller in place. @return array bytes before and after (equal: left as it
     * was), or 'error' => message (the picture untouched)
     */
    public static function file($file, $engine = null, $quality = null)
    {
        $engine = $engine ?: self::engine();
        clearstatcache();
        if (!is_file($file) || !preg_match('/\.(jpe?g|png)$/i', $file)) {
            return ['before' => 0, 'after' => 0];
        }
        $before = (int) filesize($file);
        if (self::done($file) || $before <= 0 || $before > self::MAX_BYTES) {
            return ['before' => $before, 'after' => $before];
        }
        $data = $engine === 'tinypng' ? self::tinify($file) : self::local($file, $quality ?: self::quality());
        if (is_array($data)) {
            return ['before' => $before, 'after' => $before, 'error' => $data['error'], 'stop' => !empty($data['stop'])];
        }
        $after = strlen((string) $data);
        if ($after <= 0 || $after > $before * (1 - self::MIN_GAIN)) {
            return ['before' => $before, 'after' => $before];
        }
        $mtime = filemtime($file);
        $b = self::backup($file);
        if (!is_dir(dirname($b)) && !@mkdir(dirname($b), 0755, true)) {
            return ['before' => $before, 'after' => $before, 'error' => 'var/speedpack-originals cannot be written'];
        }
        if (!@copy($file, $b)) {
            return ['before' => $before, 'after' => $before, 'error' => 'the original could not be kept'];
        }
        $tmp = $file . '.' . getmypid() . '.spc';
        if (@file_put_contents($tmp, $data) !== $after || !@rename($tmp, $file)) {
            @unlink($tmp);

            return ['before' => $before, 'after' => $before, 'error' => 'the picture could not be written'];
        }
        SpcImages::readable($b, $file);
        // the same date on both: the copies made from the original stay fresh, and done() can tell
        @touch($file, $mtime);
        @touch($b, $mtime);

        return ['before' => $before, 'after' => $after];
    }

    /** @return string|array the smaller picture, or 'error' */
    public static function local($file, $quality)
    {
        $info = @getimagesize($file);
        if (!$info || $info[0] * $info[1] > SpcImages::MAX_PIXELS) {
            return ['error' => 'not a picture GD can read'];
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $im = @imagecreatefromjpeg($file);
            if (!$im) {
                return ['error' => 'not a picture GD can read'];
            }
            imageinterlace($im, true);
            ob_start();
            $ok = imagejpeg($im, null, max(40, min(95, (int) $quality)));
            $out = ob_get_clean();
        } elseif ($info[2] === IMAGETYPE_PNG) {
            $im = @imagecreatefrompng($file);
            if (!$im) {
                return ['error' => 'not a picture GD can read'];
            }
            imagealphablending($im, false);
            imagesavealpha($im, true);
            ob_start();
            $ok = imagepng($im, null, 9);
            $out = ob_get_clean();
        } else {
            return ['error' => 'not JPEG or PNG'];
        }
        imagedestroy($im);

        return $ok ? (string) $out : ['error' => 'GD could not save it'];
    }

    /** @return string|array the picture TinyPNG made, or 'error' (a stop on the monthly limit) */
    public static function tinify($file)
    {
        if (self::$tinify) {
            return call_user_func(self::$tinify, $file);
        }
        $key = (string) Configuration::get(self::K_KEY);
        if ($key === '' || !function_exists('curl_init')) {
            return ['error' => 'no TinyPNG key'];
        }
        $ch = curl_init(self::$api . '/shrink');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) file_get_contents($file),
            CURLOPT_USERPWD => 'api:' . $key,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADER => true,
        ]);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);
        if (preg_match('/^compression-count:\s*(\d+)/mi', substr($raw, 0, $headerSize), $m)) {
            Configuration::updateGlobalValue(self::K_TINY_COUNT, json_encode(['count' => (int) $m[1], 'month' => date('Y-m')]));
        }
        $a = json_decode(substr($raw, $headerSize), true);
        if ($code !== 201 || empty($a['output']['url'])) {
            $msg = !empty($a['message']) ? (string) $a['message'] : ($err ?: 'HTTP ' . $code);

            return ['error' => 'TinyPNG: ' . $msg, 'stop' => in_array($code, [401, 429], true)];
        }
        $ch = curl_init((string) $a['output']['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'api:' . $key,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200 && $out !== '' ? $out : ['error' => 'TinyPNG: the result could not be downloaded'];
    }

    /** TinyPNG's count of pictures this month, as it last said. */
    public static function tinyCount()
    {
        $c = json_decode((string) Configuration::get(self::K_TINY_COUNT), true);

        return is_array($c) && isset($c['month']) && $c['month'] === date('Y-m') ? (int) $c['count'] : null;
    }

    /** Saved so far: pictures, bytes before, bytes after. */
    public static function stats()
    {
        $s = json_decode((string) Configuration::get(self::K_STATS), true);

        return is_array($s) ? $s + ['files' => 0, 'before' => 0, 'after' => 0] : ['files' => 0, 'before' => 0, 'after' => 0];
    }

    protected static function count(array $r)
    {
        if ($r['after'] < $r['before']) {
            $s = self::stats();
            Configuration::updateValue(self::K_STATS, json_encode(['files' => $s['files'] + 1, 'before' => $s['before'] + $r['before'], 'after' => $s['after'] + $r['after']]));
        }
    }

    /** The pictures of one product image, compressed (new pictures, when switched on). */
    public static function image($idImage)
    {
        $out = ['files' => 0, 'saved' => 0];
        foreach (SpcImages::productFiles($idImage) as $f) {
            $r = self::file($f);
            self::count($r);
            if ($r['after'] < $r['before']) {
                ++$out['files'];
                $out['saved'] += $r['before'] - $r['after'];
            }
        }

        return $out;
    }

    /**
     * One step over the whole catalogue, like the WebP copies: $limit product images from
     * $offset, then the other pictures. @return array offset, total, files, saved, done, error
     */
    public static function step($offset, $limit)
    {
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image`');
        $out = ['offset' => (int) $offset, 'total' => $total + 1, 'files' => 0, 'saved' => 0, 'done' => false, 'error' => ''];
        $todo = [];
        if ($offset < $total) {
            $ids = Db::getInstance()->executeS('SELECT id_image FROM `' . _DB_PREFIX_ . 'image` ORDER BY id_image LIMIT ' . (int) $offset . ', ' . (int) $limit);
            foreach ($ids ?: [] as $r) {
                $todo = array_merge($todo, SpcImages::productFiles((int) $r['id_image']));
            }
            $out['offset'] = $offset + count($ids ?: []);
        } else {
            $todo = SpcImages::otherFiles();
            $out['offset'] = $total + 1;
            $out['done'] = true;
        }
        foreach ($todo as $file) {
            $r = self::file($file);
            self::count($r);
            if ($r['after'] < $r['before']) {
                ++$out['files'];
                $out['saved'] += $r['before'] - $r['after'];
            }
            if (!empty($r['error'])) {
                $out['error'] = $r['error'];
                if (!empty($r['stop'])) {
                    $out['done'] = true; // the key is wrong or the month is used up: no use going on
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Puts originals back, $limit at a time. @return array restored, left
     */
    public static function restore($limit = 200)
    {
        $root = self::backupRoot();
        $done = 0;
        $left = 0;
        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $b = $f->getPathname();
                if ($done >= $limit) {
                    ++$left;
                    continue;
                }
                $file = SpcImages::root() . substr($b, strlen($root));
                // a picture PrestaShop made again since is newer than its original: that one stays
                if (is_file($file) && filemtime($file) === filemtime($b)) {
                    $mtime = filemtime($b);
                    if (!@copy($b, $file)) {
                        continue;
                    }
                    @touch($file, $mtime);
                }
                @unlink($b);
                ++$done;
            }
        }
        if ($left === 0) {
            Configuration::updateValue(self::K_STATS, '');
            if (is_dir($root)) {
                $dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($dirs as $d) {
                    if ($d->isDir()) {
                        @rmdir($d->getPathname());
                    }
                }
                @rmdir($root);
            }
        }

        return ['restored' => $done, 'left' => $left];
    }

    /** A product image deleted: its originals go too. */
    public static function forget($idImage)
    {
        $id = (int) $idImage;
        $dir = self::backupRoot() . 'img/p/' . implode('/', str_split((string) $id)) . '/';
        foreach (glob($dir . $id . '-*.{jpg,jpeg,png}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
    }
}
