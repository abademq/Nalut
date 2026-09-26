<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Console\Command;

/**
 * يشعر السائقين بالطلبات اللي انقضى وقت تحضيرها
 * وما وصلهمش إشعار عنها بعد.
 *
 * شغّله كل دقيقة:  php artisan schedule:work
 * أو يدوياً:       php artisan orders:notify-ready
 */
class NotifyReadyOrders extends Command
{
    protected $signature = 'orders:notify-ready';

    protected $description = 'إشعار السائقين بالطلبات الجاهزة للاستلام';

    public function handle(): int
    {
        if (! NotificationSetting::isEnabled('driver', 'available')) {
            $this->info('إشعار الطلبات المتاحة موقوف من لوحة التحكم.');

            return self::SUCCESS;
        }

        $orders = Order::whereNull('driver_id')
            ->whereNull('drivers_notified_at')
            ->whereIn('status', [OrderStatus::Ready->value, OrderStatus::Preparing->value])
            ->with('store')
            ->get()
            ->filter(fn ($o) => $o->isAvailableForDrivers());

        if ($orders->isEmpty()) {
            $this->info('ما فيش طلبات جديدة.');

            return self::SUCCESS;
        }

        $drivers = User::withRole('driver')
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->whereHas('driverProfile', fn ($q) => $q
                ->where('is_approved', true)
                ->where('is_online', true))
            ->with('driverProfile')
            ->get();

        foreach ($orders as $order) {
            $sent = 0;

            foreach ($drivers as $driver) {
                if (! $driver->driverProfile?->canAccept($order)) {
                    continue;
                }

                PushService::toUser(
                    $driver,
                    'طلب جديد متاح',
                    "{$order->store?->name} — أجرتك "
                        .number_format((float) $order->driver_earning, 2).' د.ل'
                        .' · '.number_format((float) $order->distance_km, 1).' كم',
                    ['type' => 'order_available', 'order_id' => (string) $order->id],
                    'driver'
                );

                $sent++;
            }

            $order->update(['drivers_notified_at' => now()]);

            $this->info("طلب {$order->code}: أُرسل لـ {$sent} سائق.");
        }

        return self::SUCCESS;
    }
}
