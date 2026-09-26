<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Options;
use App\Support\Texts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * أصناف مش متوفرة في طلب:
 *  1) المتجر يحدد الأصناف ← تتقفل في القائمة، والطلب يستنى رد الزبون
 *  2) الزبون: يكمّل بدونها · أو يعدّل الطلب (ينلغى وترجعله السلة) · أو يلغي
 *  3) لو ما ردّش خلال المهلة: يكمّل تلقائياً
 */
class SubstitutionService
{
    public function __construct(private readonly OrderService $orders) {}

    /** @param  array<int, int>  $itemIds */
    public function markUnavailable(Order $order, array $itemIds, User $store): Order
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Accepted, OrderStatus::Preparing], true)) {
            throw ValidationException::withMessages(['item_ids' => 'ما تقدرش تعدّل أصناف الطلب في الحالة هذي.']);
        }
        if ($order->awaiting_customer_at) {
            throw ValidationException::withMessages(['item_ids' => Texts::get('msg.substitution_pending')]);
        }

        $items = $order->items()->whereIn('id', $itemIds)->where('is_unavailable', false)->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['item_ids' => 'اختار الأصناف الناقصة.']);
        }

        $minutes = (int) Options::get('orders.substitution_timeout_minutes');

        DB::transaction(function () use ($order, $items, $minutes, $store) {
            foreach ($items as $item) {
                $item->update(['is_unavailable' => true]);

                // الصنف يتقفل تلقائياً — ما حدش ثاني يطلبه لين المتجر يرجّعه
                if ($item->product_id) {
                    Product::whereKey($item->product_id)->update(['is_available' => false]);
                }
            }

            $order->update([
                'awaiting_customer_at'     => now(),
                'substitution_deadline_at' => now()->addMinutes($minutes),
            ]);

            $order->statusLogs()->create([
                'from_status' => $order->status->value, 'to_status' => $order->status->value,
                'changed_by' => $store->id, 'created_at' => now(),
                'note' => 'أصناف مش متوفرة: '.$items->pluck('name')->implode('، '),
            ]);
        });

        if ($order->customer) {
            PushService::toUser(
                $order->customer,
                Texts::get('notify.title', ['code' => $order->code]),
                Texts::get('notify.customer.substitution', ['items' => $items->pluck('name')->implode('، '), 'minutes' => $minutes]),
                ['type' => 'order_status', 'order_id' => (string) $order->id, 'status' => 'substitution']
            );
        }

        return $order->fresh(['items', 'store']);
    }

    /** الزبون يكمّل بدون الأصناف الناقصة (أو تلقائياً بعد المهلة) */
    public function continueWithout(Order $order, ?User $actor = null): Order
    {
        $this->guardAwaiting($order);

        return DB::transaction(function () use ($order, $actor) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();
            $removed = $order->items()->where('is_unavailable', true)->get();
            $kept = $order->items()->where('is_unavailable', false)->get();

            if ($kept->isEmpty()) {
                $order->update(['awaiting_customer_at' => null, 'substitution_deadline_at' => null]);

                return $this->orders->transition($order, OrderStatus::Cancelled, $actor,
                    ['reason' => Texts::get('msg.substitution_empty_reason'), 'force' => true]);
            }

            // المخزون المحجوز للأصناف المشالة يرجع
            foreach ($removed as $item) {
                if ($item->product_id) {
                    Product::withTrashed()->find($item->product_id)?->restoreStock($item->quantity);
                }
            }

            $subtotal = round((float) $kept->sum('line_total'), 2);
            $fee = (float) $order->delivery_fee;
            $discount = min((float) $order->discount, $subtotal + $fee);
            $pointsDiscount = min((float) $order->points_discount, $subtotal + $fee - $discount);
            $total = round($subtotal + $fee - $discount - $pointsDiscount, 2);
            $percent = (float) ($order->store?->commission_percent ?? 0);
            $commission = round($subtotal * $percent / 100, 2);

            // لو دفع من المحفظة أكثر من الإجمالي الجديد — نرجّعوله الفرق
            $walletPaid = (float) $order->wallet_paid;
            if ($walletPaid > $total && $order->customer) {
                app(WalletService::class)->credit($order->customer, round($walletPaid - $total, 2), 'order_refund', $order,
                    "فرق أصناف مش متوفرة — طلب {$order->code}");
                $walletPaid = $total;
            }

            $order->update([
                'subtotal'                 => $subtotal,
                'discount'                 => $discount,
                'points_discount'          => $pointsDiscount,
                'total'                    => $total,
                'wallet_paid'              => $walletPaid,
                'is_paid'                  => $walletPaid >= $total ? true : $order->is_paid,
                'commission_amount'        => $commission,
                'store_earning'            => round($subtotal - $commission, 2),
                'awaiting_customer_at'     => null,
                'substitution_deadline_at' => null,
            ]);

            $order->statusLogs()->create([
                'from_status' => $order->status->value, 'to_status' => $order->status->value,
                'changed_by' => $actor?->id, 'created_at' => now(),
                'note' => $actor ? 'الزبون كمّل بدون الأصناف الناقصة' : 'مهلة الرد انتهت — الطلب كمّل بدون الأصناف الناقصة',
            ]);

            if ($order->store?->owner) {
                PushService::toUser($order->store->owner, Texts::get('notify.title', ['code' => $order->code]),
                    Texts::get('notify.store.substitution_continue'),
                    ['type' => 'order_status', 'order_id' => (string) $order->id]);
            }

            return $order->fresh(['items', 'store', 'driver']);
        });
    }

    /**
     * الزبون يبي يعدّل الطلب: ينلغى (والفلوس والنقاط والمخزون ترجع)،
     * وترجعله السلة بالأصناف المتوفرة باش يبدّل ويعاود يطلب.
     */
    public function editOrder(Order $order, User $customer): array
    {
        $this->guardAwaiting($order);

        $lines = $order->items()->where('is_unavailable', false)->get()
            ->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => $i->quantity, 'note' => $i->note])
            ->filter(fn ($l) => $l['product_id'])->values()->all();

        $order->update(['awaiting_customer_at' => null, 'substitution_deadline_at' => null]);
        $this->orders->transition($order, OrderStatus::Cancelled, $customer,
            ['reason' => Texts::get('msg.substitution_cancel_reason'), 'force' => true]);

        return app(CartBuilder::class)->build($order->store, $lines);
    }

    /** الطلبات اللي انتهت مهلتها — تكمّل تلقائياً */
    public function expireDue(): int
    {
        $n = 0;
        Order::whereNotNull('awaiting_customer_at')
            ->where('substitution_deadline_at', '<=', now())
            ->each(function (Order $o) use (&$n) {
                $this->continueWithout($o);
                $n++;
            });

        return $n;
    }

    private function guardAwaiting(Order $order): void
    {
        if (! $order->awaiting_customer_at || $order->status->isFinal()) {
            throw ValidationException::withMessages(['action' => 'الطلب مش مستني ردّك.']);
        }
    }
}
