<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;

/**
 * متغيرات التقرير الدوري — تتعبّى في قالب الرسالة.
 * بدون متجر = ملخص المنصة كاملة.
 */
class StoreReport
{
    public const PERIODS = [
        'today'       => 'اليوم',
        'yesterday'   => 'أمس',
        'last_7_days' => 'آخر 7 أيام',
        'this_month'  => 'هذا الشهر',
        'last_month'  => 'الشهر الماضي',
    ];

    /** المتغيرات المتاحة في القوالب — للمساعدة في اللوحة */
    public const VARS = [
        '{store}'            => 'اسم المتجر (أو اسم المنصة)',
        '{period}'           => 'الفترة: اليوم / آخر 7 أيام...',
        '{from}'             => 'من تاريخ',
        '{to}'               => 'إلى تاريخ',
        '{orders}'           => 'كل الطلبات',
        '{delivered}'        => 'المكتملة',
        '{cancelled}'        => 'الملغية',
        '{failed}'           => 'فشل التسليم',
        '{sales}'            => 'قيمة المبيعات (المكتملة)',
        '{commission}'       => 'عمولة المنصة',
        '{net}'              => 'صافي المتجر',
        '{balance}'          => 'رصيد المتجر الحالي',
        '{last_payout}'      => 'قيمة آخر تسكير (صرف مستحقات)',
        '{last_payout_date}' => 'تاريخ آخر تسكير',
        '{top_product}'      => 'الأكثر مبيعاً',
        '{delivery_fees}'    => 'رسوم التوصيل (ملخص المنصة)',
    ];

    /** @return array{0: Carbon, 1: Carbon} بداية ونهاية الفترة بـ UTC */
    public static function range(string $period, ?Carbon $now = null): array
    {
        $tz = LocalDay::timezone();
        $now = ($now ?? now())->copy()->setTimezone($tz);

        [$from, $to] = match ($period) {
            'yesterday'   => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'this_month'  => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month'  => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            default       => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };

        return [$from->utc(), $to->utc()];
    }

    public static function build(?Store $store, string $period, ?Carbon $now = null): array
    {
        [$from, $to] = self::range($period, $now);
        $tz = LocalDay::timezone();

        $orders = Order::query()
            ->when($store, fn ($q) => $q->where('store_id', $store->id))
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $delivered = $orders->filter(fn ($o) => $o->status === OrderStatus::Delivered);
        $sum = fn ($c, $col) => number_format((float) $c->sum($col), 2, '.', '');

        $top = OrderItem::query()
            ->whereIn('order_id', $delivered->pluck('id'))
            ->selectRaw('name, SUM(quantity) as qty')
            ->groupBy('name')->orderByDesc('qty')->first();

        $vars = [
            'store'            => $store?->name ?? \App\Filament\Pages\AppSettings::values()['name'],
            'period'           => self::PERIODS[$period] ?? $period,
            'from'             => $from->copy()->setTimezone($tz)->format('d/m/Y'),
            'to'               => $to->copy()->setTimezone($tz)->format('d/m/Y'),
            'orders'           => $orders->count(),
            'delivered'        => $delivered->count(),
            'cancelled'        => $orders->filter(fn ($o) => $o->status === OrderStatus::Cancelled)->count(),
            'failed'           => $orders->filter(fn ($o) => $o->status === OrderStatus::Failed)->count(),
            'sales'            => $sum($delivered, 'subtotal'),
            'commission'       => $sum($delivered, 'commission_amount'),
            'net'              => $sum($delivered, 'store_earning'),
            'delivery_fees'    => $sum($delivered, 'delivery_fee'),
            'top_product'      => $top ? "{$top->name} ({$top->qty})" : '-',
            'balance'          => '-',
            'last_payout'      => '-',
            'last_payout_date' => '-',
        ];

        $owner = $store?->owner;
        if ($owner instanceof User) {
            $wallets = app(WalletService::class);
            $vars['balance'] = number_format($wallets->balance($owner), 2, '.', '');

            $payout = WalletTransaction::where('wallet_id', $wallets->walletFor($owner)->id)
                ->where('type', 'payout')->latest('id')->first();
            if ($payout) {
                $vars['last_payout'] = number_format(abs((float) $payout->amount), 2, '.', '');
                $vars['last_payout_date'] = LocalDay::toLocal($payout->created_at)->format('d/m/Y');
            }
        }

        return $vars;
    }
}
