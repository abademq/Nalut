<?php

namespace App\Support;

/** المبلغ بالحروف للواصلات: «فقط مئة وخمسة وعشرون ديناراً و500 درهم لا غير» */
class ArabicAmount
{
    private const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة',
        'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];

    private const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];

    private const HUNDREDS = ['', 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة', 'ثمانمئة', 'تسعمئة'];

    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }

        $parts = [];
        foreach ([[1000000, 'مليون', 'مليونان', 'ملايين'], [1000, 'ألف', 'ألفان', 'آلاف']] as [$unit, $one, $two, $plural]) {
            $k = intdiv($n, $unit);
            $n %= $unit;
            if ($k === 0) {
                continue;
            }
            $parts[] = match (true) {
                $k === 1 => $one,
                $k === 2 => $two,
                $k <= 10 => self::below1000($k).' '.$plural,
                default => self::below1000($k).' '.$one,
            };
        }
        if ($n > 0) {
            $parts[] = self::below1000($n);
        }

        return implode(' و', $parts);
    }

    private static function below1000(int $n): string
    {
        $parts = [];
        if ($h = intdiv($n, 100)) {
            $parts[] = self::HUNDREDS[$h];
        }
        $r = $n % 100;
        if ($r > 0 && $r < 20) {
            $parts[] = self::ONES[$r];
        } elseif ($r >= 20) {
            $u = $r % 10;
            $parts[] = ($u ? self::ONES[$u].' و' : '').self::TENS[intdiv($r, 10)];
        }

        return implode(' و', $parts);
    }

    /** [مفرد, مثنى, جمع, تمييز منصوب] */
    private static function counted(int $n, array $w): string
    {
        $r = $n % 100;

        return match (true) {
            $n === 1 => $w[0].' واحد',
            $n === 2 => $w[1],
            $r >= 11 && $r <= 99 => self::number($n).' '.$w[3],
            $r >= 3 && $r <= 10 => self::number($n).' '.$w[2],
            default => self::number($n).' '.$w[0],
        };
    }

    /** الدينار الليبي = 1000 درهم */
    public static function words(float $amount): string
    {
        $amount = round(abs($amount), 3);
        $d = (int) floor($amount);
        $dirham = (int) round(($amount - $d) * 1000);

        $out = $d > 0 ? self::counted($d, ['دينار', 'ديناران', 'دنانير', 'ديناراً']) : '';
        if ($dirham > 0) {
            $out .= ($out ? ' و' : '').self::counted($dirham, ['درهم', 'درهمان', 'دراهم', 'درهماً']);
        }

        return 'فقط '.($out ?: 'صفر دينار').' لا غير';
    }
}
