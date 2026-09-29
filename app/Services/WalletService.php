<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RechargeCard;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * كل حركة فلوس في النظام تمر من هنا.
 * الرصيد ما يتعدّلش مباشرة أبداً — كل تغيير يخلّف حركة في الدفتر.
 */
class WalletService
{
    /**
     * كل مستخدم عنده محفظة منفصلة لكل صفة (زبون · متجر · سائق) — ما تتخلطش.
     * أغلب الحركات صفتها معروفة من نوعها؛ الصرف والتسوية والتعديل اليدوي لازم تتحدد.
     */
    public const PARTY_BY_TYPE = [
        'store_earning' => 'store',
        'driver_earning' => 'driver',
        'cash_collected' => 'driver',
        'topup_card' => 'customer',
        'topup_cash' => 'customer',
        'topup_online' => 'customer',
        'order_payment' => 'customer',
        'order_refund' => 'customer',
        'points' => 'customer',
    ];

    public static function partyFor(string $type, ?string $party = null): string
    {
        $party = self::PARTY_BY_TYPE[$type] ?? $party ?? 'customer';

        return array_key_exists($party, Wallet::PARTIES) ? $party : 'customer';
    }

    public function walletFor(User $user, string $party = 'customer'): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $user->id, 'party' => $party], ['balance' => 0]);
    }

    public function balance(User $user, string $party = 'customer'): float
    {
        return (float) (Wallet::where('user_id', $user->id)->where('party', $party)->value('balance') ?? 0);
    }

    /**
     * إضافة رصيد.
     */
    public function credit(
        User $user,
        float $amount,
        string $type,
        ?Order $order = null,
        ?string $note = null,
        ?User $by = null,
        ?string $party = null
    ): WalletTransaction {
        return $this->record($user, abs($amount), $type, $order, $note, $by, self::partyFor($type, $party));
    }

    /**
     * خصم رصيد. allowNegative = true للسائق اللي ماسك كاش المنصة.
     */
    public function debit(
        User $user,
        float $amount,
        string $type,
        ?Order $order = null,
        ?string $note = null,
        ?User $by = null,
        bool $allowNegative = false,
        ?string $party = null
    ): WalletTransaction {
        $amount = abs($amount);
        $party = self::partyFor($type, $party);

        if (! $allowNegative && $this->balance($user, $party) < $amount) {
            throw ValidationException::withMessages([
                'wallet' => 'الرصيد ما يكفيش. المتوفر: '.number_format($this->balance($user, $party), 2).' د.ل',
            ]);
        }

        return $this->record($user, -$amount, $type, $order, $note, $by, $party);
    }

    /** شحن المحفظة بكرت */
    public function redeemCard(User $user, string $code): WalletTransaction
    {
        return DB::transaction(function () use ($user, $code) {
            $card = RechargeCard::where('code', RechargeCard::normalize($code))
                ->lockForUpdate()
                ->first();

            if (! $card) {
                throw ValidationException::withMessages(['code' => 'الكود غير صحيح.']);
            }

            if ($card->status === 'used') {
                throw ValidationException::withMessages(['code' => 'الكرت مستعمل من قبل.']);
            }

            if ($card->status === 'disabled') {
                throw ValidationException::withMessages(['code' => 'الكرت موقوف.']);
            }

            if ($card->expires_at && $card->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => 'الكرت منتهي الصلاحية.']);
            }

            $card->update([
                'status'  => 'used',
                'used_by' => $user->id,
                'used_at' => now(),
            ]);

            $tx = $this->record(
                $user,
                (float) $card->amount,
                'topup_card',
                null,
                "كرت {$card->code}",
                $user,
                'customer'
            );

            $tx->update(['recharge_card_id' => $card->id]);

            return $tx;
        });
    }

    /**
     * توزيع مستحقات طلب تم تسليمه.
     * تنفّذ مرة وحدة فقط لكل طلب.
     */
    public function settleOrderEarnings(Order $order): void
    {
        if ($order->earnings_settled) {
            return;
        }

        DB::transaction(function () use ($order) {
            // مستحقات المتجر
            if ($order->store?->owner && $order->store_earning > 0) {
                $this->credit(
                    $order->store->owner,
                    (float) $order->store_earning,
                    'store_earning',
                    $order,
                    "طلب {$order->code}"
                );
            }

            if ($order->driver) {
                // أجرة التوصيل
                if ($order->driver_earning > 0) {
                    $this->credit(
                        $order->driver,
                        (float) $order->driver_earning,
                        'driver_earning',
                        $order,
                        "طلب {$order->code}"
                    );
                }

                // الكاش اللي حصّله السائق يخص المنصة — يتسجّل دين عليه
                $cashCollected = max(0, (float) $order->total - (float) $order->wallet_paid);

                if ($cashCollected > 0 && $order->payment_method->value === 'cash') {
                    $this->debit(
                        $order->driver,
                        $cashCollected,
                        'cash_collected',
                        $order,
                        "كاش طلب {$order->code}",
                        null,
                        true // مسموح الرصيد يصير سالب
                    );
                }
            }

            $order->update(['earnings_settled' => true]);
        });
    }

    /** تسوية: السائق سلّم الكاش، أو المنصة صرفت للمتجر — على محفظة الصفة (store|driver) */
    public function settle(User $user, float $amount, string $type, ?string $note = null, ?User $by = null, ?string $party = null): WalletTransaction
    {
        // بدون صفة: الصرف عادةً للمتجر، والاستلام من السائق
        $party ??= $type === 'payout' ? 'store' : 'driver';

        return $type === 'payout'
            ? $this->record($user, -abs($amount), 'payout', null, $note, $by, $party)
            : $this->record($user, abs($amount), 'settlement', null, $note, $by, $party);
    }

    /** الكتابة الفعلية في الدفتر مع قفل الصف */
    private function record(
        User $user,
        float $signedAmount,
        string $type,
        ?Order $order,
        ?string $note,
        ?User $by,
        string $party
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $signedAmount, $type, $order, $note, $by, $party) {
            $wallet = Wallet::where('user_id', $user->id)->where('party', $party)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $user->id, 'party' => $party, 'balance' => 0]);

            $newBalance = round((float) $wallet->balance + $signedAmount, 2);
            $wallet->update(['balance' => $newBalance]);

            return WalletTransaction::create([
                'wallet_id'     => $wallet->id,
                'type'          => $type,
                'amount'        => round($signedAmount, 2),
                'balance_after' => $newBalance,
                'order_id'      => $order?->id,
                'created_by'    => $by?->id,
                'note'          => $note,
            ]);
        });
    }
}
