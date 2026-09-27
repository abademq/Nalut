<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * تفصيل فلوس الطلب: شن يدفع الزبون، وكيف تتوزع بين المتجر والسائق والمنصة، ومين يحصّل الكاش.
 *
 * نفس الحساب في لوحة التحكم وتطبيق المتجر وتطبيق السائق — لكن كل طرف يشوف
 * بس اللي الإدارة سمحت بيه من صفحة «ما يظهر للمتجر والسائق».
 */
class OrderMoney
{
    /** مين يشوف: admin | store | driver | customer */
    public static function viewer(?Request $request = null): string
    {
        $request ??= request();
        if ($request->is('api/v1/store/*')) {
            return 'store';
        }
        if ($request->is('api/v1/driver/*')) {
            return 'driver';
        }
        if ($request->is('api/*')) {
            return 'customer';
        }

        return 'admin';
    }

    /** هل الطرف مسموحله يشوف هذي المعلومة */
    public static function can(string $viewer, string $what): bool
    {
        if ($viewer === 'admin') {
            return true;
        }
        if (! in_array($viewer, ['store', 'driver'], true)) {
            return true;
        }
        $key = "show.$viewer.$what";

        return array_key_exists($key, Options::definitions()) ? (bool) Options::get($key) : true;
    }

    /** الأرقام الخام */
    public static function numbers(Order $o): array
    {
        $subtotal = (float) $o->subtotal;
        $delivery = (float) $o->delivery_fee;
        $coupon = (float) $o->discount;
        $points = (float) $o->points_discount;
        $total = (float) $o->total;
        $wallet = (float) ($o->wallet_paid ?? 0);
        $commission = (float) $o->commission_amount;
        $storeNet = (float) $o->store_earning;
        $driver = (float) $o->driver_earning;
        $cash = $o->payment_method?->value === 'cash' ? max(0, round($total - $wallet, 2)) : 0.0;

        return [
            'subtotal' => $subtotal,
            'delivery_fee' => $delivery,
            'coupon_discount' => $coupon,
            'points_discount' => $points,
            'total' => $total,
            'wallet_paid' => $wallet,
            'cash_to_collect' => $cash,
            'paid_online' => in_array($o->payment_method?->value, ['card'], true) ? round($total - $wallet, 2) : 0.0,
            'commission' => $commission,
            'commission_percent' => $subtotal > 0 ? round(100 * $commission / $subtotal, 1) : 0,
            'store_net' => $storeNet,
            'driver_earning' => $driver,
            'delivery_platform' => round($delivery - $driver, 2),
            // ربح المنصة = العمولة + نصيبها من التوصيل − الخصومات اللي تتحمّلها
            'platform_net' => round($commission + ($delivery - $driver) - $coupon - $points, 2),
            // السائق: يسلّم الكاش للمنصة ويستحق أجرته → الصافي اللي عليه
            'driver_owes' => round($cash - $driver, 2),
        ];
    }

    /**
     * أسطر جاهزة للعرض — التطبيقات ترسمها كما هي.
     *
     * @return list<array{group: string, label: string, amount: float, style: string, hint?: string}>
     */
    public static function lines(Order $o, string $viewer): array
    {
        $n = self::numbers($o);
        $can = fn (string $w) => self::can($viewer, $w);
        $out = [];
        $add = function (string $group, string $label, float $amount, string $style = 'normal', ?string $hint = null) use (&$out) {
            $out[] = array_filter(compact('group', 'label', 'amount', 'style', 'hint'), fn ($v) => $v !== null);
        };

        $method = ['cash' => 'نقداً عند الاستلام', 'wallet' => 'من المحفظة', 'card' => 'دفع إلكتروني'][$o->payment_method?->value] ?? '';

        // ===== على الزبون =====
        if ($can('order_total')) {
            $add('customer', 'مجموع الأصناف', $n['subtotal']);
            $add('customer', 'رسوم التوصيل', $n['delivery_fee']);
            if ($n['coupon_discount'] > 0) {
                $add('customer', 'خصم الكوبون', -$n['coupon_discount'], 'minus');
            }
            if ($n['points_discount'] > 0) {
                $add('customer', 'خصم النقاط', -$n['points_discount'], 'minus');
            }
            $add('customer', 'الإجمالي على الزبون', $n['total'], 'total', $method);
            if ($n['wallet_paid'] > 0) {
                $add('customer', 'مدفوع من محفظة الزبون', $n['wallet_paid'], 'muted');
            }
        }

        // الكاش: السائق لازم يعرفه دائماً
        if ($n['cash_to_collect'] > 0 && ($viewer === 'driver' || $can('order_total'))) {
            $add('customer', 'يتحصّل نقداً من الزبون', $n['cash_to_collect'], 'highlight',
                $viewer === 'driver' ? 'تسلّمه للإدارة في التسوية' : null);
        }

        // ===== التوزيع =====
        if ($viewer === 'store') {
            if ($can('commission')) {
                $add('split', 'قيمة الأصناف', $n['subtotal']);
                $add('split', "عمولة المنصة ({$n['commission_percent']}%)", -$n['commission'], 'minus');
            }
            if ($can('store_net')) {
                $add('split', 'صافي المتجر', $n['store_net'], 'total', 'يتضاف لرصيدك بعد التسليم');
            }
            if ($can('driver_earning')) {
                $add('split', 'أجرة السائق', $n['driver_earning'], 'muted');
            }
        } elseif ($viewer === 'driver') {
            if ($can('earning')) {
                $add('split', 'أجرتك من الطلب', $n['driver_earning'], 'total', 'تتضاف لرصيدك بعد التسليم');
            }
            if ($can('store_net')) {
                $add('split', 'صافي المتجر', $n['store_net'], 'muted');
            }
            if ($can('commission')) {
                $add('split', 'عمولة المنصة', $n['commission'], 'muted');
            }
            if ($n['cash_to_collect'] > 0 && $can('earning')) {
                $add('split', $n['driver_owes'] >= 0 ? 'الصافي اللي عليك للإدارة' : 'الصافي اللي لك عند الإدارة',
                    abs($n['driver_owes']), 'highlight', 'الكاش ناقص أجرتك');
            }
        } elseif ($viewer === 'admin') {
            $add('split', "صافي المتجر (بعد عمولة {$n['commission_percent']}%)", $n['store_net'], 'normal');
            $add('split', 'أجرة السائق', $n['driver_earning']);
            $add('split', 'عمولة المنصة من المتجر', $n['commission']);
            if ($n['delivery_platform'] != 0) {
                $add('split', 'نصيب المنصة من التوصيل', $n['delivery_platform']);
            }
            if ($n['coupon_discount'] + $n['points_discount'] > 0) {
                $add('split', 'خصومات تتحمّلها المنصة', -($n['coupon_discount'] + $n['points_discount']), 'minus');
            }
            $add('split', 'صافي المنصة', $n['platform_net'], 'total');
        }

        return $out;
    }

    public const GROUPS = ['customer' => 'على الزبون', 'split' => 'التوزيع'];

    /** للـ API */
    public static function forApi(Order $o, string $viewer): array
    {
        return [
            'groups' => self::GROUPS,
            'lines' => self::lines($o, $viewer),
        ];
    }
}
