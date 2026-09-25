<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * "اليوم" بتوقيت ليبيا.
 *
 * التواريخ تتخزّن UTC، لكن يوم المتجر يبدا 00:00 بتوقيت طرابلس (UTC+2).
 * بدون التحويل، الطلبات من 00:00 لـ 02:00 بعد نص الليل تنحسب على اليوم الغلط.
 */
class LocalDay
{
    public static function timezone(): string
    {
        return (string) config('app.local_timezone', 'Africa/Tripoli');
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon} [بداية اليوم UTC, نهايته UTC, التاريخ المحلي]
     */
    public static function range(?string $date = null): array
    {
        $tz = self::timezone();

        $local = $date
            ? Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();

        return [
            $local->copy()->utc(),
            $local->copy()->endOfDay()->utc(),
            $local,
        ];
    }

    public static function toLocal(?\DateTimeInterface $at): ?Carbon
    {
        return $at ? Carbon::instance($at)->setTimezone(self::timezone()) : null;
    }
}
