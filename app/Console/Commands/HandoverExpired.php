<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\PushService;
use App\Support\Options;
use App\Support\Texts;
use Illuminate\Console\Command;

/**
 * «بانتظار التسليم»: لما مهلة الزبون تكمّل، إشعار للسائق (شن يدير توّا) وللزبون (آخر تنبيه).
 * يشتغل كل دقيقة، ومرة وحدة بس لكل طلب.
 */
class HandoverExpired extends Command
{
    protected $signature = 'orders:handover-expired';

    protected $description = 'إشعار انتهاء مهلة الزبون في طلبات «بانتظار التسليم»';

    public function handle(): int
    {
        $notify = Options::get('handover.notify_on_expiry');
        $n = 0;

        Order::query()
            ->where('status', OrderStatus::AwaitingHandover->value)
            ->whereNull('handover_expired_at')
            ->where('handover_deadline_at', '<=', now())
            ->with(['driver', 'customer'])
            ->each(function (Order $order) use ($notify, &$n) {
                // نعلّمو أول — باش لو الأمر اشتغل مرتين ما يتبعتش مرتين
                if (Order::whereKey($order->id)->whereNull('handover_expired_at')->update(['handover_expired_at' => now()]) === 0) {
                    return;
                }
                $n++;
                if (! $notify) {
                    return;
                }

                $title = Texts::get('notify.title', ['code' => $order->code]);
                $data = ['type' => 'order_status', 'order_id' => (string) $order->id, 'status' => 'handover_expired'];

                if ($order->driver && ($msg = trim((string) Options::get('handover.driver_message'))) !== '') {
                    PushService::toUser($order->driver, $title, $msg, $data, 'driver');
                }
                if ($order->customer && ($msg = trim((string) Options::get('handover.customer_message'))) !== '') {
                    PushService::toUser($order->customer, $title, $msg, $data, 'customer');
                }
            });

        $this->info("انتهت مهلة $n طلب.");

        return self::SUCCESS;
    }
}
