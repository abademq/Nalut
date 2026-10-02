<?php

namespace App\Support;

/** مدة مقرّبة بالعربي: «45 ثانية» · «12 دقيقة» · «20 ساعة و15 دقيقة» · «يومين و3 ساعات» */
class Duration
{
    public static function seconds(float|int $seconds): string
    {
        $s = (int) round(abs($seconds));
        if ($s < 60) {
            return $s <= 1 ? 'لحظات' : "$s ثانية";
        }

        $m = (int) round($s / 60);
        if ($m < 60) {
            return self::unit($m, 'دقيقة', 'دقيقتين', 'دقائق');
        }

        $h = intdiv($m, 60);
        $rm = $m % 60;
        if ($h < 24) {
            return self::unit($h, 'ساعة', 'ساعتين', 'ساعات').($rm ? ' و'.self::unit($rm, 'دقيقة', 'دقيقتين', 'دقائق') : '');
        }

        $d = intdiv($h, 24);
        $rh = $h % 24;

        return self::unit($d, 'يوم', 'يومين', 'أيام').($rh ? ' و'.self::unit($rh, 'ساعة', 'ساعتين', 'ساعات') : '');
    }

    public static function minutes(float|int $minutes): string
    {
        return self::seconds($minutes * 60);
    }

    public static function between(?\DateTimeInterface $from, ?\DateTimeInterface $to): string
    {
        return ($from && $to) ? self::seconds($to->getTimestamp() - $from->getTimestamp()) : '';
    }

    /** 1 دقيقة · دقيقتين · 3 دقائق · 11 دقيقة */
    private static function unit(int $n, string $one, string $two, string $few): string
    {
        return match (true) {
            $n === 1 => $one,
            $n === 2 => $two,
            $n >= 3 && $n <= 10 => "$n $few",
            default => "$n $one",
        };
    }
}
