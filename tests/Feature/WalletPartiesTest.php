<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\SettlementService;
use App\Services\WalletService;
use App\Support\Options;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * صاحب متجر يطلب كزبون: محفظة الزبون منفصلة تماماً عن حساب المتجر.
 */
class WalletPartiesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $driver;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'صاحب', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->driver = User::create(['name' => 'سائق', 'phone' => '0912222222', 'role' => UserRole::Driver->value, 'is_active' => true]);
        $this->driver->ensureDriverProfile()->update(['is_approved' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_active' => true, 'lat' => 1, 'lng' => 1]);
        \App\Models\Setting::put('opt.show.store.balance', '1');
        \App\Models\Setting::put('opt.show.driver.balance', '1');
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function delivered(string $code, User $customer): Order
    {
        $o = Order::create(['code' => $code, 'customer_id' => $customer->id, 'store_id' => $this->store->id, 'driver_id' => $this->driver->id,
            'status' => OrderStatus::Delivered, 'payment_method' => 'cash', 'subtotal' => 100, 'delivery_fee' => 10, 'total' => 110,
            'wallet_paid' => 0, 'commission_amount' => 15, 'store_earning' => 85, 'driver_earning' => 10,
            'delivered_at' => now(), 'address_details' => 'x', 'address_lat' => 1, 'address_lng' => 1, 'customer_phone' => '1']);
        app(WalletService::class)->settleOrderEarnings($o->fresh(['store.owner', 'driver']));

        return $o;
    }

    public function test_customer_money_of_a_merchant_stays_out_of_the_store_account(): void
    {
        $w = app(WalletService::class);
        $this->delivered('A1', $this->driver);

        // صاحب المتجر شحن محفظته كزبون ودفع بيها طلب
        $w->credit($this->owner, 200, 'topup_cash', null, 'شحن');
        $w->debit($this->owner, 60, 'order_payment');

        $this->assertSame(140.0, $w->balance($this->owner, 'customer'));
        $this->assertSame(85.0, $w->balance($this->owner, 'store'));
        $this->assertSame(140.0, $this->owner->fresh()->walletBalance());
        $this->assertSame(85.0, $this->owner->fresh()->walletBalance('store'));

        // «حسابي والتسويات» في تطبيق التاجر: مستحقات المتجر بس
        $res = $this->actingAs($this->owner)->getJson('/api/v1/store/account')->assertOk();
        $this->assertEquals(85, $res->json('balance'));
        $labels = collect($res->json('unsettled.lines'))->pluck('label')->implode('|');
        $this->assertStringNotContainsString('شحن', $labels);

        // تطبيق الزبون: محفظة الزبون بس
        $res = $this->actingAs($this->owner)->withHeader('X-App', 'customer')->getJson('/api/v1/wallet')->assertOk();
        $this->assertEquals(140, $res->json('balance'));
        $types = collect($this->actingAs($this->owner)->withHeader('X-App', 'customer')
            ->getJson('/api/v1/wallet/transactions')->json('data'))->pluck('type')->all();
        $this->assertNotContains('store_earning', $types);

        // تطبيق التاجر: محفظة المتجر بس
        $types = collect($this->actingAs($this->owner)->withHeader('X-App', 'store')
            ->getJson('/api/v1/wallet/transactions')->json('data'))->pluck('type')->all();
        $this->assertSame(['store_earning'], array_values(array_unique($types)));

        // التسوية تصرف المستحقات من غير ما تلمس رصيد الزبون
        $s = app(SettlementService::class)->create($this->owner, 'store', 85, 'cash');
        $this->assertSame(85.0, (float) $s->balance_before);
        $this->assertSame(0.0, $w->balance($this->owner, 'store'));
        $this->assertSame(140.0, $w->balance($this->owner, 'customer'));

        // إلغاء التسوية يرجع لمحفظة المتجر
        app(SettlementService::class)->cancel($s, 'غلط');
        $this->assertSame(85.0, $w->balance($this->owner, 'store'));
        $this->assertSame(140.0, $w->balance($this->owner, 'customer'));
    }

    public function test_driver_earnings_go_to_driver_wallet_and_driver_app(): void
    {
        $this->delivered('A1', $this->owner);
        $w = app(WalletService::class);

        $this->assertSame(-100.0, $w->balance($this->driver, 'driver'));
        $this->assertSame(0.0, $w->balance($this->driver, 'customer'));

        $res = $this->actingAs($this->driver)->withHeader('X-App', 'driver')->getJson('/api/v1/wallet')->assertOk();
        $this->assertEquals(-100, $res->json('balance'));
        $this->assertEquals(-100, $this->actingAs($this->driver)->getJson('/api/v1/driver/account')->json('balance'));
    }

    public function test_customer_balance_check_ignores_store_money(): void
    {
        $this->delivered('A1', $this->driver);  // المتجر له 85
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(WalletService::class)->debit($this->owner, 50, 'order_payment');
    }

    public function test_migration_splits_an_old_mixed_wallet(): void
    {
        $migration = require database_path('migrations/2026_10_22_000001_wallets_per_party.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('wallets', 'party'));

        // محفظة قديمة مخلوطة: شحن زبون + مستحقات متجر + صرف تسوية
        $wid = DB::table('wallets')->insertGetId(['user_id' => $this->owner->id, 'balance' => 225, 'created_at' => now(), 'updated_at' => now()]);
        $tx = fn ($type, $amount, $note = null) => DB::table('wallet_transactions')->insertGetId([
            'wallet_id' => $wid, 'type' => $type, 'amount' => $amount, 'balance_after' => 0, 'note' => $note,
            'created_at' => now(), 'updated_at' => now()]);
        $tx('store_earning', 85);
        $tx('topup_cash', 200);
        $tx('order_payment', -60);
        $tx('store_earning', 85);
        $payout = $tx('payout', -85, 'صرف مستحقات');
        DB::table('settlements')->insert(['number' => 'ST-000001', 'user_id' => $this->owner->id, 'party' => 'store', 'direction' => 'pay',
            'amount' => 85, 'balance_before' => 170, 'balance_after' => 85, 'method' => 'cash', 'wallet_transaction_id' => $payout,
            'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $store = Wallet::where('user_id', $this->owner->id)->where('party', 'store')->first();
        $customer = Wallet::where('user_id', $this->owner->id)->where('party', 'customer')->first();
        $this->assertSame($wid, $store->id);           // الصفة الأساسية للمستخدم (أكثر حركات)
        $this->assertSame(85.0, (float) $store->balance);
        $this->assertSame(140.0, (float) $customer->balance);
        $this->assertSame(['store_earning', 'store_earning', 'payout'],
            WalletTransaction::where('wallet_id', $store->id)->orderBy('id')->pluck('type')->all());
        $this->assertSame([200.0, 140.0],
            WalletTransaction::where('wallet_id', $customer->id)->orderBy('id')->pluck('balance_after')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(0, Settlement::count() - 1);
    }

    public function test_migration_records_old_balance_drift(): void
    {
        $migration = require database_path('migrations/2026_10_22_000001_wallets_per_party.php');
        $migration->down();

        $wid = DB::table('wallets')->insertGetId(['user_id' => $this->driver->id, 'balance' => 15, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wallet_transactions')->insert(['wallet_id' => $wid, 'type' => 'driver_earning', 'amount' => 10, 'balance_after' => 10,
            'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $w = Wallet::find($wid);
        $this->assertSame('driver', $w->party);
        $this->assertSame(15.0, (float) $w->balance);
        $this->assertTrue(WalletTransaction::where('wallet_id', $wid)->where('note', 'فرق رصيد قديم عند فصل المحافظ')->where('amount', 5)->exists());
    }

    public function test_admin_pages_show_and_top_up_each_wallet(): void
    {
        $admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin);
        $this->delivered('A1', $this->driver);

        \Livewire\Livewire::test(\App\Filament\Resources\Users\Pages\ListUsers::class)
            ->assertOk()
            ->assertSee('رصيد الزبون')
            ->callTableAction('topup', $this->owner, ['party' => 'store', 'type' => 'topup_cash', 'amount' => 15])
            ->assertHasNoTableActionErrors()
            ->callTableAction('deduct', $this->owner, ['party' => 'customer', 'amount' => 5, 'note' => 'تجربة'])
            ->assertHasNoTableActionErrors();

        $w = app(WalletService::class);
        $this->assertSame(100.0, $w->balance($this->owner, 'store'));
        $this->assertSame(-5.0, $w->balance($this->owner, 'customer'));
        // شحن حساب المتجر يتسجّل تعديل، مش «شحن نقدي» زبون
        $this->assertSame('adjustment', WalletTransaction::where('wallet_id', $w->walletFor($this->owner, 'store')->id)->latest('id')->value('type'));

        \Livewire\Livewire::test(\App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions::class)
            ->assertOk()->assertSee('متجر');
        \Livewire\Livewire::test(\App\Filament\Widgets\FinanceStatsWidget::class)
            ->assertOk()->assertSee('أرصدة الزبائن');
        \Livewire\Livewire::test(\App\Filament\Resources\Users\RelationManagers\WalletTransactionsRelationManager::class,
            ['ownerRecord' => $this->owner, 'pageClass' => \App\Filament\Resources\Users\Pages\EditUser::class])
            ->assertOk()->assertSee('رصيد متجر');
    }
}
