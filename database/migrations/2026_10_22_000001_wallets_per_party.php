<?php

use App\Models\Settlement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * محفظة منفصلة لكل صفة: زبون · متجر · سائق.
 *
 * قبل: محفظة وحدة للمستخدم — فصاحب المتجر اللي يطلب كزبون كانت شحناته ومدفوعاته
 * تتخلط مع مستحقات متجره، وتطلع في «حسابي والتسويات»، وتدخل في مبلغ التسوية.
 *
 * التقسيم يمشي على نوع كل حركة قديمة:
 *   مستحقات متجر → متجر · أجرة توصيل وكاش محصّل → سائق · الشحن والطلبات والنقاط → زبون
 *   صرف/تسوية → من التسوية المربوطة بيها · إلغاء تسوية → من رقم التسوية في الملاحظة
 *   تعديل يدوي بدون تسوية → الصفة الأساسية للمستخدم
 * وبعدها نعاود نحسبو الرصيد و«الرصيد بعد» لكل محفظة من حركاتها بالترتيب.
 */
return new class extends Migration
{
    private const BY_TYPE = [
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

    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('party', 10)->default('customer')->after('user_id');
        });
        // الفهرس الجديد أول (يخدم المفتاح الأجنبي user_id في MySQL)، وبعدها نشيلو القديم
        Schema::table('wallets', function (Blueprint $table) {
            $table->unique(['user_id', 'party']);
        });
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });

        $settlementByTx = Settlement::whereNotNull('wallet_transaction_id')->pluck('party', 'wallet_transaction_id');
        $settlementByNumber = Settlement::pluck('party', 'number');

        DB::table('wallets')->orderBy('id')->each(function ($wallet) use ($settlementByTx, $settlementByNumber) {
            $user = DB::table('users')->where('id', $wallet->user_id)->first(['role']);
            $primary = in_array($user?->role, ['store', 'driver'], true) ? $user->role : 'customer';

            $groups = [];
            foreach (DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->orderBy('id')->get() as $tx) {
                $party = self::BY_TYPE[$tx->type] ?? null;
                if (! $party && in_array($tx->type, ['payout', 'settlement'], true)) {
                    $party = $settlementByTx[$tx->id] ?? ($primary === 'customer' ? 'store' : $primary);
                }
                if (! $party && $tx->note && preg_match('/إلغاء تسوية (\S+)/u', $tx->note, $m)) {
                    $party = $settlementByNumber[$m[1]] ?? null;
                }
                $groups[$party ?? $primary][] = $tx;
            }

            // المحفظة الحالية تاخذ صفة أكثر حركاتها (أو الأساسية لو فاضية)، والباقي محافظ جديدة
            uksort($groups, fn ($a, $b) => count($groups[$b]) <=> count($groups[$a]));
            $mainParty = array_key_first($groups) ?? $primary;
            DB::table('wallets')->where('id', $wallet->id)->update(['party' => $mainParty]);

            foreach ($groups as $party => $txs) {
                $walletId = $party === $mainParty ? $wallet->id : DB::table('wallets')->insertGetId([
                    'user_id' => $wallet->user_id, 'party' => $party, 'balance' => 0,
                    'is_active' => $wallet->is_active, 'created_at' => $wallet->created_at, 'updated_at' => now(),
                ]);

                $running = 0.0;
                foreach ($txs as $tx) {
                    $running = round($running + (float) $tx->amount, 2);
                    DB::table('wallet_transactions')->where('id', $tx->id)
                        ->update(['wallet_id' => $walletId, 'balance_after' => $running]);
                }
                DB::table('wallets')->where('id', $walletId)->update(['balance' => $running]);
            }

            // لو الرصيد القديم ما يطابقش مجموع الحركات (تعديل مباشر قديم)، الفرق يتسجّل حركة واضحة
            $sum = round(array_sum(array_map(fn ($t) => (float) $t->amount, array_merge([], ...array_values($groups)))), 2);
            $diff = round((float) $wallet->balance - $sum, 2);
            if (abs($diff) >= 0.01) {
                $main = DB::table('wallets')->where('id', $wallet->id)->first();
                $after = round((float) $main->balance + $diff, 2);
                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $wallet->id, 'type' => 'adjustment', 'amount' => $diff, 'balance_after' => $after,
                    'note' => 'فرق رصيد قديم عند فصل المحافظ', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('wallets')->where('id', $wallet->id)->update(['balance' => $after]);
            }
        });
    }

    public function down(): void
    {
        // نرجّعو كل حركات المستخدم لمحفظة وحدة
        foreach (DB::table('wallets')->select('user_id')->groupBy('user_id')->havingRaw('count(*) > 1')->pluck('user_id') as $userId) {
            $ids = DB::table('wallets')->where('user_id', $userId)->orderBy('id')->pluck('id');
            $keep = $ids->first();
            DB::table('wallet_transactions')->whereIn('wallet_id', $ids)->update(['wallet_id' => $keep]);
            DB::table('wallets')->whereIn('id', $ids->slice(1))->delete();

            $running = 0.0;
            foreach (DB::table('wallet_transactions')->where('wallet_id', $keep)->orderBy('id')->get() as $tx) {
                $running = round($running + (float) $tx->amount, 2);
                DB::table('wallet_transactions')->where('id', $tx->id)->update(['balance_after' => $running]);
            }
            DB::table('wallets')->where('id', $keep)->update(['balance' => $running]);
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->unique(['user_id']);
        });
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'party']);
            $table->dropColumn('party');
        });
    }
};
