<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\AdminAlerts;
use App\Support\Options;
use Illuminate\Console\Command;

/** طلبات واقفة تحتاج تدخّل: المتجر ما ردّش، أو جاهز وما فيش سائق */
class CheckStuckOrders extends Command
{
    protected $signature = 'orders:check-stuck';

    protected $description = 'تنبيه الإدارة على الطلبات الواقفة';

    public function handle(): int
    {
        $pending = (int) Options::get('alerts.pending_minutes');
        $noDriver = (int) Options::get('alerts.no_driver_minutes');

        if ($pending > 0) {
            Order::with('store')
                ->where('status', OrderStatus::Pending->value)
                ->where(fn ($q) => $q->where('payment_method', '!=', 'card')->orWhere('is_paid', true))
                ->where('created_at', '<', now()->subMinutes($pending))
                ->where('created_at', '>', now()->subDay())
                ->each(fn (Order $o) => AdminAlerts::send(
                    "المتجر ما قبلش الطلب {$o->code}",
                    "{$o->store?->name} — فات {$pending} دقيقة بدون رد. كلّم المتجر.",
                    OrderResource::getUrl('view', ['record' => $o->id], panel: 'admin'),
                    'warning',
                    "stuck-pending:{$o->id}"
                ));
        }

        if ($noDriver > 0) {
            Order::with('store')
                ->where('status', OrderStatus::Ready->value)
                ->whereNull('driver_id')
                ->where('ready_at', '<', now()->subMinutes($noDriver))
                ->where('ready_at', '>', now()->subDay())
                ->each(fn (Order $o) => AdminAlerts::send(
                    "الطلب {$o->code} جاهز وما فيش سائق",
                    "{$o->store?->name} — فات {$noDriver} دقيقة من التجهيز.",
                    OrderResource::getUrl('view', ['record' => $o->id], panel: 'admin'),
                    'warning',
                    "stuck-ready:{$o->id}"
                ));
        }

        // «صنف مش متوفر»: الزبون ما ردّش خلال المهلة — الطلب يكمّل بدونه
        app(\App\Services\SubstitutionService::class)->expireDue();

        return self::SUCCESS;
    }
}
