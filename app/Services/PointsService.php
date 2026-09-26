<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PointsTransaction;
use App\Models\User;
use App\Support\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * نقاط الولاء: تنكسب على الطلب المكتمل، وتنصرف بطريقة وحدة تحددها الإدارة:
 *   wallet   — تتحوّل لرصيد في المحفظة
 *   checkout — تنخصم مباشرة من إجمالي الطلب
 */
class PointsService
{
    public static function enabled(): bool
    {
        return (bool) Options::get('points.enabled');
    }

    public static function value(): float
    {
        return (float) Options::get('points.point_value');
    }

    /** قيمة النقاط بالدينار */
    public static function money(int $points): float
    {
        return round($points * self::value(), 2);
    }

    /** كم نقطة يكسب الطلب هذا */
    public function pointsFor(Order $order): int
    {
        if (! self::enabled() || (float) $order->subtotal < (float) Options::get('points.min_order')) {
            return 0;
        }

        $rate = (float) Options::get('points.earn_rate');

        return (int) floor(Options::get('points.earn_mode') === 'per_order'
            ? $rate
            : ((float) $order->subtotal - (float) $order->points_discount) * $rate);
    }

    /** بعد التسليم — مرة وحدة لكل طلب */
    public function award(Order $order): int
    {
        if ($order->points_awarded > 0 || ! $order->customer) {
            return 0;
        }

        $points = $this->pointsFor($order);
        if ($points <= 0) {
            return 0;
        }

        $this->record($order->customer, $points, 'earned', $order, "طلب {$order->code}");
        $order->forceFill(['points_awarded' => $points])->saveQuietly();

        return $points;
    }

    /** تحويل النقاط لرصيد في المحفظة (لو الإدارة مختارة «المحفظة») */
    public function convertToWallet(User $user, int $points): float
    {
        $this->guardRedeem($user, $points, 'wallet');

        return DB::transaction(function () use ($user, $points) {
            $amount = self::money($points);
            $this->record($user, -$points, 'converted', null, "تحويل {$points} نقطة للمحفظة");
            app(WalletService::class)->credit($user, $amount, 'points', null, "تحويل {$points} نقطة");

            return $amount;
        });
    }

    /**
     * كم نقطة وكم دينار ينخصمو من طلب قيمته $total (لو «يدفع بيها مباشرة»)
     *
     * @return array{0: int, 1: float} [points, discount]
     */
    public function checkoutDiscount(User $user, float $total): array
    {
        if (! self::enabled() || Options::get('points.redeem_mode') !== 'checkout') {
            return [0, 0.0];
        }

        $balance = (int) $user->points_balance;
        if ($balance < (int) Options::get('points.min_redeem') || $total <= 0) {
            return [0, 0.0];
        }

        $value = self::value();
        $points = min($balance, (int) floor($total / $value));
        $discount = min($total, self::money($points));

        return [$points, $discount];
    }

    public function redeemOnOrder(User $user, Order $order, int $points): void
    {
        if ($points > 0) {
            $this->record($user, -$points, 'redeemed', $order, "طلب {$order->code}");
        }
    }

    /** الطلب انلغى — نرجّعو النقاط اللي انصرفت عليه */
    public function refund(Order $order): void
    {
        if ($order->points_used <= 0 || ! $order->customer) {
            return;
        }

        $already = PointsTransaction::where('order_id', $order->id)->where('type', 'refund')->exists();
        if (! $already) {
            $this->record($order->customer, (int) $order->points_used, 'refund', $order, "إلغاء طلب {$order->code}");
        }
    }

    public function adjust(User $user, int $points, ?string $note, ?User $by): void
    {
        $this->record($user, $points, 'adjust', null, $note, $by);
    }

    private function guardRedeem(User $user, int $points, string $mode): void
    {
        if (! self::enabled() || Options::get('points.redeem_mode') !== $mode) {
            throw ValidationException::withMessages(['points' => 'الطريقة هذي مش متاحة.']);
        }
        if ($points < (int) Options::get('points.min_redeem')) {
            throw ValidationException::withMessages(['points' => 'أقل عدد نقاط للاستعمال '.Options::get('points.min_redeem').'.']);
        }
        if ($points > (int) $user->points_balance) {
            throw ValidationException::withMessages(['points' => 'نقاطك ما تكفيش.']);
        }
    }

    private function record(User $user, int $points, string $type, ?Order $order, ?string $note, ?User $by = null): PointsTransaction
    {
        return DB::transaction(function () use ($user, $points, $type, $order, $note, $by) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            $balance = (int) $fresh->points_balance + $points;

            if ($balance < 0) {
                throw ValidationException::withMessages(['points' => 'نقاطك ما تكفيش.']);
            }

            $fresh->forceFill(['points_balance' => $balance])->saveQuietly();
            $user->points_balance = $balance;

            return PointsTransaction::create([
                'user_id' => $user->id, 'points' => $points, 'balance_after' => $balance, 'type' => $type,
                'order_id' => $order?->id, 'note' => $note, 'created_by' => $by?->id, 'created_at' => now(),
            ]);
        });
    }
}
