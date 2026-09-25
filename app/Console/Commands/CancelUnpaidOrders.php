<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * يلغي طلبات البطاقة اللي الزبون ما كمّلش دفعها خلال المهلة.
 * الإلغاء يمر من OrderService: يرجّع المخزون وأي مبلغ اندفع من المحفظة،
 * ويبلّغ الزبون.
 *
 * يشتغل كل 5 دقائق من الجدولة، أو يدوياً: php artisan orders:cancel-unpaid
 */
class CancelUnpaidOrders extends Command
{
    protected $signature = 'orders:cancel-unpaid';

    protected $description = 'إلغاء طلبات الدفع الإلكتروني غير المكتملة';

    public function handle(OrderService $orders): int
    {
        $minutes = (int) config('delivery.unpaid_order_timeout_minutes', 30);

        $stale = Order::where('status', OrderStatus::Pending->value)
            ->where('payment_method', 'card')
            ->where('is_paid', false)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($stale as $order) {
            try {
                $orders->transition($order, OrderStatus::Cancelled, null, [
                    'reason' => 'ما تمّش الدفع الإلكتروني خلال '.$minutes.' دقيقة',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Auto-cancel failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("انلغى {$stale->count()} طلب غير مدفوع.");

        return self::SUCCESS;
    }
}
