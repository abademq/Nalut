<?php

namespace App\Filament\Merchant\Widgets;

use App\Enums\OrderStatus;
use App\Support\LocalDay;
use App\Support\Merchant;
use App\Support\OrderMoney;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** ملخص اليوم — نفس أرقام تطبيق المتجر */
class TodayStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $store = Merchant::store();
        if (! $store) {
            return [];
        }
        [$from, $to] = LocalDay::range();
        $visible = fn ($q) => $q->where(fn ($w) => $w->where('payment_method', '!=', 'card')->orWhere('is_paid', true));

        $stats = [
            Stat::make('طلبات اليوم', $store->orders()->whereBetween('created_at', [$from, $to])->count()),
            Stat::make('تستنى القبول', $visible($store->orders()->where('status', OrderStatus::Pending->value))->count())
                ->color('danger'),
            Stat::make('طلبات شغّالة', $visible($store->orders()->active())->count())->color('warning'),
        ];

        if (OrderMoney::can('store', 'store_net')) {
            $stats[] = Stat::make('صافي مبيعات اليوم',
                number_format((float) $store->orders()->whereBetween('created_at', [$from, $to])
                    ->where('status', OrderStatus::Delivered->value)->sum('store_earning'), 2).' د.ل')
                ->color('success');
        }

        $low = $store->products()->where('track_stock', true)->whereNotNull('low_stock_alert')
            ->whereColumn('stock_quantity', '<=', 'low_stock_alert')->count();
        if ($low) {
            $stats[] = Stat::make('أصناف قرّبت تخلص', $low)->color('warning');
        }

        return $stats;
    }
}
