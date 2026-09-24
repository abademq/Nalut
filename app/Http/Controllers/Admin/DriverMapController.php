<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;

/**
 * بيانات خريطة السائقين — تستهلكها صفحة اللوحة كل 15 ثانية.
 */
class DriverMapController extends Controller
{
    /** الموقع يعتبر قديمًا بعد دقيقتين */
    private const STALE_SECONDS = 120;

    public function locations(): JsonResponse
    {
        $drivers = User::where('role', 'driver')
            ->with(['driverProfile.zones', 'wallet'])
            ->get();

        $activeOrders = Order::whereIn('status', OrderStatus::active())
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, count(*) as total')
            ->groupBy('driver_id')
            ->pluck('total', 'driver_id');

        // المواقع الحية تأتي من Cache
        $locations = collect(DriverLocationService::onlineDrivers())
            ->keyBy('driver_id');

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
            $balance = (float) ($driver->wallet?->balance ?? 0);

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
                && isset($location['at'])
                && $location['at'] >= now()->timestamp - self::STALE_SECONDS;

            if (
                $profile->is_online
                && $fresh
                && isset($location['lat'], $location['lng'])
            ) {
                $counts['online']++;

                $online[] = [
                    'id'       => $driver->id,
                    'name'     => $driver->name,
                    'phone'    => $driver->phone,
                    'lat'      => (float) $location['lat'],
                    'lng'      => (float) $location['lng'],
                    'heading'  => (float) ($location['heading'] ?? 0),
                    'since'    => now()->createFromTimestamp($location['at'])->diffForHumans(),
                    'orders'   => (int) ($activeOrders[$driver->id] ?? 0),
                    'capacity' => (int) $profile->max_active_orders,
                    'balance'  => round($balance, 2),
                    'zones'    => $profile->zones->pluck('name')->all(),
                ];
            } else {
                $counts['offline']++;
            }
        }

        return response()->json([
            'counts'  => $counts,
            'debt'    => round($debt, 2),
            'credit'  => round($credit, 2),
            'drivers' => $online,
            'at'      => now()->format('H:i:s'),
        ]);
    }
}