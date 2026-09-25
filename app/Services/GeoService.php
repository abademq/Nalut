<?php

namespace App\Services;

use App\Models\DeliveryZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoService
{
    /** مسافة الخط المستقيم — تُستعمل كاحتياطي فقط */
    public static function airDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /**
     * المسافة الحقيقية على الطريق عبر OSRM.
     * لو الخدمة ما ردّتش، نرجع للخط المستقيم مضروب في معامل تقريبي.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $road = self::route($lat1, $lng1, $lat2, $lng2);

        return $road['distance_km'] ?? self::fallbackDistance($lat1, $lng1, $lat2, $lng2);
    }

    /** المسافة + الوقت المتوقع */
    public static function route(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $base = rtrim((string) config('delivery.osrm_url'), '/');

        if ($base === '') {
            return [];
        }

        // نقرّب الإحداثيات باش الكاش يفيد فعلاً (فرق 11 متر تقريباً)
        $key = 'osrm:'.implode(',', [
            round($lat1, 4), round($lng1, 4), round($lat2, 4), round($lng2, 4),
        ]);

        return Cache::remember($key, now()->addHours(12), function () use ($base, $lat1, $lng1, $lat2, $lng2) {
            try {
                $url = "$base/route/v1/driving/$lng1,$lat1;$lng2,$lat2";

                $res = Http::timeout(6)->get($url, [
                    'overview'    => 'false',
                    'alternatives' => 'false',
                ]);

                if ($res->failed()) {
                    Log::warning('OSRM failed', ['status' => $res->status()]);

                    return [];
                }

                $data = $res->json();

                if (($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
                    return [];
                }

                $route = $data['routes'][0];

                return [
                    'distance_km'    => round($route['distance'] / 1000, 2),
                    'duration_minutes' => (int) ceil($route['duration'] / 60),
                ];
            } catch (\Throwable $e) {
                Log::warning('OSRM exception: '.$e->getMessage());

                return [];
            }
        });
    }

    /**
     * احتياطي: الخط المستقيم × معامل التواء الطرق.
     * 1.3 رقم متعارف عليه للمدن المتوسطة.
     */
    private static function fallbackDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $factor = (float) config('delivery.road_factor', 1.3);

        return round(self::airDistanceKm($lat1, $lng1, $lat2, $lng2) * $factor, 2);
    }

    /** حساب رسوم التوصيل حسب المنطقة والمسافة */
    public static function deliveryFee(float $distanceKm, ?DeliveryZone $zone = null): float
    {
        $baseFee    = $zone->base_fee   ?? (float) config('delivery.base_fee', 5);
        $perKm      = $zone->fee_per_km ?? (float) config('delivery.fee_per_km', 1.5);
        $freeRadius = (float) config('delivery.free_radius_km', 1);

        $billableKm = max(0, $distanceKm - $freeRadius);

        return round($baseFee + ($billableKm * $perKm), 2);
    }

    /** رسالة موحّدة لما يكون العنوان برا كل مناطق التوصيل */
    public const OUT_OF_COVERAGE = 'عذراً، خدمتنا غير متوفرة في منطقتك حالياً.';

    /**
     * منطقة التوصيل للإحداثيات، أو خطأ «غير متوفرة في منطقتك».
     *
     * لو ما فيش ولا منطقة نشطة معرّفة (نظام جديد لسه ما تضبطش)
     * ما نمنعوش الطلبات — نرجّعو null ونستعملو الرسوم الافتراضية.
     */
    public static function requireZone(float $lat, float $lng, string $field = 'address'): ?DeliveryZone
    {
        $zone = self::resolveZone($lat, $lng);

        if (! $zone && DeliveryZone::where('is_active', true)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => self::OUT_OF_COVERAGE]);
        }

        return $zone;
    }

    /** أقرب منطقة توصيل تغطّي الإحداثيات — بالخط المستقيم لأنها دائرة تغطية */
    public static function resolveZone(float $lat, float $lng): ?DeliveryZone
    {
        return DeliveryZone::where('is_active', true)->get()
            ->filter(fn ($zone) => $zone->center_lat && $zone->center_lng)
            ->map(function ($zone) use ($lat, $lng) {
                $zone->setAttribute(
                    '_distance',
                    self::airDistanceKm($lat, $lng, $zone->center_lat, $zone->center_lng)
                );

                return $zone;
            })
            ->filter(fn ($zone) => $zone->getAttribute('_distance') <= $zone->radius_km)
            ->sortBy('_distance')
            ->first();
    }
}
