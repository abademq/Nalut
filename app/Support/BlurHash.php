<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * BlurHash: نص قصير (~30 حرف) يوصف ألوان الصورة — التطبيق والموقع يرسموه ضبابي
 * لين الصورة الحقيقية تتحمّل، بدل مربع رمادي فاضي.
 *
 * تنفيذ الخوارزمية الرسمية (wolt/blurhash) بدون مكتبات خارجية.
 */
class BlurHash
{
    private const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

    /** من ملف على قرص public — null لو مش صورة أو صار خطأ (ما يطيّحش الحفظ) */
    public static function fromPath(?string $path, string $disk = 'public'): ?string
    {
        if (! $path) {
            return null;
        }
        try {
            $fs = Storage::disk($disk);
            if (! $fs->exists($path)) {
                return null;
            }
            $bytes = $fs->get($path);
            if (! $bytes || strlen($bytes) > 15 * 1024 * 1024) {
                return null;
            }

            return self::fromBytes($bytes);
        } catch (\Throwable $e) {
            Log::info('BlurHash skipped', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public static function fromBytes(string $bytes): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            imagedestroy($src);

            return null;
        }

        // نصغّروها (الألوان العامة بس تهم) — أسرع بكثير
        $max = 32;
        $scale = min(1, $max / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $small = imagecreatetruecolor($tw, $th);
        // الشفاف يولّي أبيض (الصور PNG بخلفية شفافة)
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        imagecopyresampled($small, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);

        $pixels = [];
        for ($y = 0; $y < $th; $y++) {
            for ($x = 0; $x < $tw; $x++) {
                $c = imagecolorat($small, $x, $y);
                $pixels[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
            }
        }
        imagedestroy($small);

        // مكوّنات حسب اتجاه الصورة (عريضة = أكثر أفقياً)
        $cx = $tw >= $th ? 4 : 3;
        $cy = $tw >= $th ? 3 : 4;

        return self::encode($pixels, $tw, $th, $cx, $cy);
    }

    /** @param  list<array{0:int,1:int,2:int}>  $pixels */
    public static function encode(array $pixels, int $w, int $h, int $cx = 4, int $cy = 3): string
    {
        $linear = array_map(fn ($p) => [self::toLinear($p[0]), self::toLinear($p[1]), self::toLinear($p[2])], $pixels);

        $factors = [];
        for ($j = 0; $j < $cy; $j++) {
            for ($i = 0; $i < $cx; $i++) {
                $norm = ($i === 0 && $j === 0) ? 1 : 2;
                $r = $g = $b = 0.0;
                for ($y = 0; $y < $h; $y++) {
                    $cosY = cos(M_PI * $j * $y / $h);
                    for ($x = 0; $x < $w; $x++) {
                        $basis = cos(M_PI * $i * $x / $w) * $cosY;
                        $p = $linear[$y * $w + $x];
                        $r += $basis * $p[0];
                        $g += $basis * $p[1];
                        $b += $basis * $p[2];
                    }
                }
                $scale = $norm / ($w * $h);
                $factors[] = [$r * $scale, $g * $scale, $b * $scale];
            }
        }

        $dc = array_shift($factors);
        $hash = self::b83(($cx - 1) + ($cy - 1) * 9, 1);

        if ($factors) {
            $actualMax = max(array_map(fn ($f) => max(abs($f[0]), abs($f[1]), abs($f[2])), $factors));
            $quantMax = (int) max(0, min(82, floor($actualMax * 166 - 0.5)));
            $maxValue = ($quantMax + 1) / 166;
            $hash .= self::b83($quantMax, 1);
        } else {
            $maxValue = 1;
            $hash .= self::b83(0, 1);
        }

        $hash .= self::b83((self::toSrgb($dc[0]) << 16) + (self::toSrgb($dc[1]) << 8) + self::toSrgb($dc[2]), 4);

        foreach ($factors as $f) {
            $q = fn ($v) => (int) max(0, min(18, floor(self::signPow($v / $maxValue, 0.5) * 9 + 9.5)));
            $hash .= self::b83($q($f[0]) * 19 * 19 + $q($f[1]) * 19 + $q($f[2]), 2);
        }

        return $hash;
    }

    private static function b83(int $value, int $length): string
    {
        $out = '';
        for ($i = 1; $i <= $length; $i++) {
            $digit = intdiv($value, 83 ** ($length - $i)) % 83;
            $out .= self::CHARS[$digit];
        }

        return $out;
    }

    private static function toLinear(int $v): float
    {
        $v /= 255;

        return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    private static function toSrgb(float $v): int
    {
        $v = max(0.0, min(1.0, $v));

        return (int) ($v <= 0.0031308 ? $v * 12.92 * 255 + 0.5 : (1.055 * ($v ** (1 / 2.4)) - 0.055) * 255 + 0.5);
    }

    private static function signPow(float $v, float $exp): float
    {
        return ($v < 0 ? -1 : 1) * (abs($v) ** $exp);
    }
}
