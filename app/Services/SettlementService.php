<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Activity;
use App\Support\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسويات المتاجر والسائقين.
 *
 * الرصيد في المحفظة هو المرجع:
 *   متجر رصيده موجب  = المنصة تدين له (نصرفوله)
 *   سائق رصيده سالب  = عنده كاش للمنصة (نستلمو منه)
 * التسوية تكتب حركة في المحفظة، تربط الطلبات اللي تحاسبت، وتحفظ صورة من الحساب للواصل.
 */
class SettlementService
{
    public function __construct(private readonly WalletService $wallets) {}

    /** الطلبات المسلّمة اللي دخلت الرصيد ولسه ما تسوّتش */
    public function unsettledOrders(User $user, string $party): Builder
    {
        $q = Order::query()
            ->where('status', OrderStatus::Delivered->value)
            ->where('earnings_settled', true);

        return $party === 'driver'
            ? $q->where('driver_id', $user->id)->whereNull('driver_settlement_id')
            : $q->whereHas('store', fn ($s) => $s->where('user_id', $user->id))->whereNull('store_settlement_id');
    }

    public function lastSettlement(User $user, string $party): ?Settlement
    {
        return Settlement::where('user_id', $user->id)->where('party', $party)->whereNull('cancelled_at')->latest('id')->first();
    }

    /** كشف الحساب: الرصيد + ملخص الطلبات اللي ما تسوّتش + الحركات الثانية */
    public function statement(User $user, string $party): array
    {
        $orders = $this->unsettledOrders($user, $party)->with('store')->orderBy('delivered_at')->get();
        $last = $this->lastSettlement($user, $party);
        $balance = $this->wallets->balance($user);

        $cash = fn (Order $o) => $o->payment_method?->value === 'cash' ? max(0, round((float) $o->total - (float) $o->wallet_paid, 2)) : 0.0;

        $sum = [
            'orders' => $orders->count(),
            'subtotal' => round($orders->sum('subtotal'), 2),
            'delivery' => round($orders->sum('delivery_fee'), 2),
            'customer_total' => round($orders->sum('total'), 2),
            'commission' => round($orders->sum('commission_amount'), 2),
            'store_net' => round($orders->sum('store_earning'), 2),
            'driver_earning' => round($orders->sum('driver_earning'), 2),
            'cash' => round($orders->sum($cash), 2),
        ];

        // حركات المحفظة من غير الطلبات والتسويات (تعديل يدوي، استرجاع...) من آخر تسوية
        $wallet = $this->wallets->walletFor($user);
        $other = WalletTransaction::where('wallet_id', $wallet->id)
            ->whereNotIn('type', ['store_earning', 'driver_earning', 'cash_collected', 'payout', 'settlement'])
            ->when($last, fn ($q) => $q->where('created_at', '>', $last->created_at))
            ->latest()->limit(50)->get();

        return [
            'balance' => $balance,
            // pay = نصرفوله · receive = نستلمو منه · none = ما فيش
            'direction' => $balance > 0.004 ? 'pay' : ($balance < -0.004 ? 'receive' : 'none'),
            'due' => round(abs($balance), 2),
            'orders' => $orders,
            'sum' => $sum,
            'other' => $other,
            'last' => $last,
            'from' => $orders->min('delivered_at') ?? $last?->created_at,
        ];
    }

    public function create(
        User $user,
        string $party,
        float $amount,
        string $method = 'cash',
        ?string $reference = null,
        ?string $note = null,
        ?User $by = null,
        ?string $direction = null,
    ): Settlement {
        if (! in_array($party, ['store', 'driver'], true)) {
            throw ValidationException::withMessages(['party' => 'نوع الحساب غلط.']);
        }
        if (! $user->hasRole($party)) {
            throw ValidationException::withMessages(['user_id' => 'الحساب هذا مش '.Settlement::PARTIES[$party].'.']);
        }
        $amount = round(abs($amount), 2);
        if ($amount < 0.01) {
            throw ValidationException::withMessages(['amount' => 'المبلغ لازم يكون أكبر من صفر.']);
        }

        return DB::transaction(function () use ($user, $party, $amount, $method, $reference, $note, $by, $direction) {
            $st = $this->statement($user, $party);
            $direction ??= $st['direction'] === 'receive' ? 'receive' : 'pay';
            $before = $st['balance'];

            $tx = $this->wallets->settle($user, $amount, $direction === 'pay' ? 'payout' : 'settlement',
                trim(($direction === 'pay' ? 'صرف مستحقات' : 'استلام نقدي').($note ? " — $note" : '')), $by);

            $s = Settlement::create([
                'number' => 'TMP-'.uniqid(),
                'user_id' => $user->id,
                'party' => $party,
                'store_id' => $party === 'store' ? $user->store?->id : null,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => (float) $tx->balance_after,
                'method' => array_key_exists($method, Settlement::METHODS) ? $method : 'other',
                'reference' => $reference,
                'period_from' => $st['from'],
                'period_to' => now(),
                'orders_count' => $st['sum']['orders'],
                'summary' => $st['sum'] + [
                    'other' => $st['other']->map(fn ($t) => ['type' => $t->typeLabel(), 'amount' => (float) $t->amount, 'note' => $t->note,
                        'at' => $t->created_at?->toDateTimeString()])->values()->all(),
                ],
                'note' => $note,
                'wallet_transaction_id' => $tx->id,
                'created_by' => $by?->id,
            ]);
            $s->update(['number' => Options::get('settlement.prefix').str_pad((string) $s->id, 6, '0', STR_PAD_LEFT)]);
            $tx->update(['note' => $tx->note." ({$s->number})"]);

            // الطلبات اللي دخلت في الحساب
            Order::whereIn('id', $st['orders']->pluck('id'))
                ->update([$party === 'driver' ? 'driver_settlement_id' : 'store_settlement_id' => $s->id]);

            Activity::record('settlement.created', "تسوية {$s->number}: {$s->directionLabel()} {$s->partyName()} ".number_format($amount, 2).' د.ل', $s);

            return $s->fresh();
        });
    }

    /** إلغاء تسوية غلط: حركة عكسية في المحفظة والطلبات ترجع «ما تسوّتش» */
    public function cancel(Settlement $s, string $reason, ?User $by = null): void
    {
        if ($s->isCancelled()) {
            return;
        }

        DB::transaction(function () use ($s, $reason, $by) {
            $note = "إلغاء تسوية {$s->number} — $reason";
            $s->direction === 'pay'
                ? $this->wallets->credit($s->user, $s->amount, 'adjustment', null, $note, $by)
                : $this->wallets->debit($s->user, $s->amount, 'adjustment', null, $note, $by, true);

            Order::where($s->party === 'driver' ? 'driver_settlement_id' : 'store_settlement_id', $s->id)
                ->update([$s->party === 'driver' ? 'driver_settlement_id' : 'store_settlement_id' => null]);

            $s->update(['cancelled_at' => now(), 'cancel_reason' => $reason, 'cancelled_by' => $by?->id]);
            Activity::record('settlement.cancelled', $note, $s);
        });
    }

    /** كل حسابات المتاجر أو السائقين مع أرصدتهم — لصفحة التسويات */
    public function accounts(string $party): Collection
    {
        $users = User::withRole($party)->with(['wallet', 'store'])->orderBy('name')->get();

        $counts = $party === 'driver'
            ? Order::where('status', OrderStatus::Delivered->value)->where('earnings_settled', true)->whereNull('driver_settlement_id')
                ->selectRaw('driver_id as uid, count(*) as c')->groupBy('driver_id')->pluck('c', 'uid')
            : Order::join('stores', 'stores.id', '=', 'orders.store_id')->where('orders.status', OrderStatus::Delivered->value)
                ->where('orders.earnings_settled', true)->whereNull('orders.store_settlement_id')
                ->selectRaw('stores.user_id as uid, count(*) as c')->groupBy('stores.user_id')->pluck('c', 'uid');

        $last = Settlement::whereNull('cancelled_at')->where('party', $party)
            ->selectRaw('user_id, max(created_at) as at')->groupBy('user_id')->pluck('at', 'user_id');

        return $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $party === 'store' && $u->store ? $u->store->name : $u->name,
            'sub' => $party === 'store' ? $u->name.' · '.$u->phone : $u->phone,
            'balance' => $u->walletBalance(),
            'orders' => (int) ($counts[$u->id] ?? 0),
            'last' => $last[$u->id] ?? null,
        ]);
    }
}
