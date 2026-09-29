<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\RechargeCard;
use App\Models\Wallet;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinanceStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return \App\Support\Perm::can('finance.view');
    }

    protected function getStats(): array
    {
        $delivered = Order::where('status', OrderStatus::Delivered->value);

        $commissionToday = (float) (clone $delivered)
            ->whereDate('delivered_at', today())
            ->sum('commission_amount');

        $commissionMonth = (float) (clone $delivered)
            ->whereMonth('delivered_at', now()->month)
            ->whereYear('delivered_at', now()->year)
            ->sum('commission_amount');

        $salesToday = (float) (clone $delivered)
            ->whereDate('delivered_at', today())
            ->sum('total');

        // أرصدة موجبة = فلوس المنصة مدينة بيها للمتاجر والسائقين
        $owed = (float) Wallet::whereIn('party', ['store', 'driver'])->where('balance', '>', 0)->sum('balance');

        // أرصدة سالبة في محافظ السائقين = كاش عندهم لسه ما تسلّمش
        $cashOut = abs((float) Wallet::where('party', 'driver')->where('balance', '<', 0)->sum('balance'));

        // أرصدة الزبائن = فلوس مشحونة لسه ما تصرفتش في طلبات
        $customers = (float) Wallet::where('party', 'customer')->where('balance', '>', 0)->sum('balance');

        $unusedCards = (float) RechargeCard::where('status', 'unused')->sum('amount');

        return [
            Stat::make('مبيعات اليوم', number_format($salesToday, 2).' د.ل')
                ->description('إجمالي الطلبات المسلّمة')
                ->color('primary'),

            Stat::make('عمولة اليوم', number_format($commissionToday, 2).' د.ل')
                ->description('ربح المنصة')
                ->color('success'),

            Stat::make('عمولة الشهر', number_format($commissionMonth, 2).' د.ل')
                ->color('success'),

            Stat::make('مستحقات للغير', number_format($owed, 2).' د.ل')
                ->description('أرصدة المتاجر والسائقين')
                ->color('warning'),

            Stat::make('كاش عند السائقين', number_format($cashOut, 2).' د.ل')
                ->description('لسه ما تسلّمش للمنصة')
                ->color($cashOut > 0 ? 'danger' : 'gray'),

            Stat::make('أرصدة الزبائن', number_format($customers, 2).' د.ل')
                ->description('مشحونة في المحافظ ولسه ما تصرفتش')
                ->color('gray'),

            Stat::make('كروت غير مستعملة', number_format($unusedCards, 2).' د.ل')
                ->description('التزام مستقبلي')
                ->color('gray'),
        ];
    }
}
