<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Support\Options;
use Illuminate\Http\JsonResponse;

/**
 * بيانات خريطة العمليات (السائقين + المتاجر + مناطق التوصيل) — تستهلكها صفحة اللوحة كل 15 ثانية.
 */
class DriverMapController extends Controller
{
    /** الموقع يعتبر قديمًا بعد دقيقتين */
    private const STALE_SECONDS = 120;

    public function locations(): JsonResponse
    {
        // مواقع السائقين بيانات حساسة — نفس صلاحية صفحة الخريطة
        abort_unless(
            \App\Support\Perm::can('orders.view') || \App\Support\Perm::can('users.view'),
            403
        );

        $drivers = User::withRole('driver')
            ->with(['driverProfile.zones', 'driverWallet'])
            ->get();

        $activeOrders = Order::whereIn('status', OrderStatus::active())
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, count(*) as total')
            ->groupBy('driver_id')
            ->pluck('total', 'driver_id');

        // المواقع الحية تأتي من Cache
        $locations = collect(DriverLocationService::onlineDrivers())
            ->keyBy('driver_id');

        $storeOrders = Order::whereIn('status', OrderStatus::active())
            ->selectRaw('store_id, count(*) as total')
            ->groupBy('store_id')
            ->pluck('total', 'store_id');

        $lastSeenHours = (int) rescue(fn () => Options::get('tracking.map_last_seen_hours'), 24, false);

        $online = [];
        $counts = [
            'online' => 0,
            'offline' => 0,
            'suspended' => 0,
            'pending' => 0,
        ];

        $debt = 0.0;
        $credit = 0.0;

        foreach ($drivers as $driver) {
            $profile = $driver->driverProfile;
            $balance = (float) ($driver->driverWallet?->balance ?? 0);

            if ($balance < 0) {
                $debt += abs($balance);
            } else {
                $credit += $balance;
            }

            if (! $driver->is_active) {
                $counts['suspended']++;
                continue;
            }

            if (! $profile?->is_approved) {
                $counts['pending']++;
                continue;
            }

            $location = $locations->get($driver->id);

            $fresh = $location
                && isset($location['at'], $location['lat'], $location['lng'])
                && $location['at'] >= now()->timestamp - self::STALE_SECONDS;

            $isOnline = $profile->is_online && $fresh;
            $isOnline ? $counts['online']++ : $counts['offline']++;

            // غير متاح (أو متاح بس موقعه وقف) → آخر موقع معروف لو مش قديم برشا
            if (! $isOnline) {
                $location = $profile->liveLocation();

                if (! $location || $lastSeenHours <= 0
                    || $location['at'] < now()->timestamp - $lastSeenHours * 3600) {
                    continue;
                }
            }

            $online[] = [
                'id'       => $driver->id,
                'name'     => $driver->name,
                'phone'    => $driver->phone,
                'state'    => $isOnline ? 'online' : ($profile->is_online ? 'stale' : 'offline'),
                'lat'      => (float) $location['lat'],
                'lng'      => (float) $location['lng'],
                'heading'  => (float) ($location['heading'] ?? 0),
                'since'    => now()->createFromTimestamp($location['at'])->diffForHumans(),
                'orders'   => (int) ($activeOrders[$driver->id] ?? 0),
                'capacity' => (int) $profile->max_active_orders,
                'balance'  => round($balance, 2),
                'zones'    => $profile->zones->pluck('name')->all(),
                'url'      => self::link('users', $driver->id),
            ];
        }

        $stores = Store::query()
            ->where('is_active', true)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->with('zone:id,name')
            ->get()
            ->map(fn (Store $store) => [
                'id'     => $store->id,
                'name'   => $store->name,
                'phone'  => $store->phone,
                'lat'    => (float) $store->lat,
                'lng'    => (float) $store->lng,
                'open'   => $store->isAcceptingOrders(),
                'status' => $store->statusText(),
                'zone'   => $store->zone?->name,
                'orders' => (int) ($storeOrders[$store->id] ?? 0),
                'url'    => self::link('stores', $store->id),
            ])
            ->values();

        $zones = DeliveryZone::query()
            ->whereNotNull('center_lat')->whereNotNull('center_lng')
            ->where('radius_km', '>', 0)
            ->orderBy('name')
            ->get()
            ->map(fn (DeliveryZone $zone) => [
                'id'        => $zone->id,
                'name'      => $zone->name,
                'lat'       => $zone->center_lat,
                'lng'       => $zone->center_lng,
                'radius_km' => $zone->radius_km,
                'active'    => $zone->is_active,
                'base_fee'  => $zone->base_fee,
                'per_km'    => $zone->fee_per_km,
                'min_order' => $zone->min_order,
                'url'       => self::link('delivery-zones', $zone->id),
            ])
            ->values();

        $counts['stores_open'] = $stores->where('open', true)->count();
        $counts['stores_closed'] = $stores->count() - $counts['stores_open'];

        return response()->json([
            'counts'  => $counts,
            'debt'    => round($debt, 2),
            'credit'  => round($credit, 2),
            'drivers' => $online,
            'stores'  => $stores,
            'zones'   => $zones,
            'last_seen_hours' => $lastSeenHours,
            'at'      => now()->format('H:i:s'),
        ]);
    }

    /** رابط صفحة السجل في اللوحة — null لو المسار مش موجود */
    private static function link(string $resource, int $id): ?string
    {
        return rescue(fn () => route("filament.admin.resources.$resource.view", ['record' => $id]), null, false);
    }
}
