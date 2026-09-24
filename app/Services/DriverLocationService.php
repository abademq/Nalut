<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * مواقع السائقين — تشتغل بأي كاش (file محلياً، Redis على السيرفر).
 * ضع هذا الملف في: app/Services/DriverLocationService.php
 */
class DriverLocationService
{
    private const TTL = 120; // ثانية

    private const INDEX_KEY = 'drivers:online:index';

    public static function put(int $driverId, float $lat, float $lng, ?float $heading = null): void
    {
        Cache::put("driver:loc:$driverId", [
            'lat'     => $lat,
            'lng'     => $lng,
            'heading' => $heading,
            'at'      => now()->timestamp,
        ], self::TTL);

        $index = Cache::get(self::INDEX_KEY, []);
        $index[$driverId] = now()->timestamp;

        // نظّف اللي انتهى وقتهم
        $cutoff = now()->timestamp - self::TTL;
        $index  = array_filter($index, fn ($ts) => $ts >= $cutoff);

        Cache::put(self::INDEX_KEY, $index, self::TTL * 5);
    }

    public static function get(int $driverId): ?array
    {
        return Cache::get("driver:loc:$driverId");
    }

    public static function goOffline(int $driverId): void
    {
        Cache::forget("driver:loc:$driverId");

        $index = Cache::get(self::INDEX_KEY, []);
        unset($index[$driverId]);
        Cache::put(self::INDEX_KEY, $index, self::TTL * 5);
    }

    /** @return array<int, array{driver_id:int, lat:float, lng:float}> */
    public static function onlineDrivers(): array
    {
        $out = [];

        foreach (array_keys(Cache::get(self::INDEX_KEY, [])) as $id) {
            if ($loc = self::get((int) $id)) {
                $out[] = ['driver_id' => (int) $id] + $loc;
            }
        }

        return $out;
    }
}
