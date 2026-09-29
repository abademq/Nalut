<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SettlementDesk;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Settlements\Pages\ViewSettlement;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\SettlementService;
use App\Services\WalletService;
use App\Support\ArabicAmount;
use App\Support\Options;
use App\Support\OrderMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class SettlementsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $driver;

    private User $customer;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'صاحب', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->driver = User::create(['name' => 'سائق', 'phone' => '0912222222', 'role' => UserRole::Driver->value, 'is_active' => true]);
        $this->driver->ensureDriverProfile()->update(['is_approved' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم الجبل', 'slug' => 'm', 'is_active' => true, 'lat' => 1, 'lng' => 1]);
    }

    /** طلب مسلّم: أصناف 100، توصيل 10، عمولة 15%، نقداً */
    private function delivered(string $code, float $subtotal = 100, string $pay = 'cash'): Order
    {
        $o = Order::create(['code' => $code, 'customer_id' => $this->customer->id, 'store_id' => $this->store->id, 'driver_id' => $this->driver->id,
            'status' => OrderStatus::Delivered, 'payment_method' => $pay, 'subtotal' => $subtotal, 'delivery_fee' => 10,
            'total' => $subtotal + 10, 'wallet_paid' => $pay === 'wallet' ? $subtotal + 10 : 0,
            'commission_amount' => $subtotal * 0.15, 'store_earning' => $subtotal * 0.85, 'driver_earning' => 10,
            'delivered_at' => now(), 'address_details' => 'x', 'address_lat' => 1, 'address_lng' => 1, 'customer_phone' => '1']);
        app(WalletService::class)->settleOrderEarnings($o->fresh(['store.owner', 'driver']));

        return $o->fresh();
    }

    public function test_money_breakdown(): void
    {
        $o = $this->delivered('A1');
        $n = OrderMoney::numbers($o);
        $this->assertSame(110.0, $n['cash_to_collect']);
        $this->assertSame(85.0, $n['store_net']);
        $this->assertSame(100.0, $n['driver_owes']);
        $this->assertSame(15.0, $n['platform_net']);

        // الأرصدة: المتجر له 85، السائق عليه 100
        $this->assertSame(85.0, $this->owner->fresh()->walletBalance('store'));
        $this->assertSame(-100.0, $this->driver->fresh()->walletBalance('driver'));
    }

    public function test_settle_store_and_driver_with_receipts(): void
    {
        $this->delivered('A1');
        $this->delivered('A2', 50);
        $svc = app(SettlementService::class);

        $st = $svc->statement($this->owner, 'store');
        $this->assertSame('pay', $st['direction']);
        $this->assertSame(127.5, $st['due']);
        $this->assertSame(2, $st['sum']['orders']);

        $s = $svc->create($this->owner, 'store', 127.5, 'transfer', 'TR-9', null, $this->admin);
        $this->assertSame('ST-'.str_pad((string) $s->id, 6, '0', STR_PAD_LEFT), $s->number);
        $this->assertSame(0.0, $this->owner->fresh()->walletBalance('store'));
        $this->assertSame(2, Order::where('store_settlement_id', $s->id)->count());
        $this->assertSame(0, $svc->statement($this->owner, 'store')['sum']['orders']);

        // السائق: جزء بس من اللي عليه
        $d = $svc->create($this->driver, 'driver', 100, 'cash', null, 'دفعة أولى', $this->admin);
        $this->assertSame('receive', $d->direction);
        $this->assertSame(-50.0, $this->driver->fresh()->walletBalance('driver'));

        // الواصل
        $this->actingAs($this->admin, 'web')->get(route('settlements.print', ['settlement' => $s, 'paper' => 'a4']))
            ->assertOk()->assertSee('واصل تسوية متجر')->assertSee('127.50')->assertSee('A2')
            ->assertSee('فقط مئة وسبعة وعشرون ديناراً وخمسمئة درهم لا غير');
        if ($dump = getenv('DUMP_RECEIPT')) {
            file_put_contents($dump.'/a4.html', $this->get(route('settlements.print', ['settlement' => $s, 'paper' => 'a4']))->getContent());
            file_put_contents($dump.'/80.html', $this->get(route('settlements.print', ['settlement' => $s, 'paper' => '80']))->getContent());
        }
        // بدون دخول: ممنوع — ورابط موقّع: يفتح
        auth('web')->logout();
        $this->get(route('settlements.print', ['settlement' => $s]))->assertForbidden();
        $this->get($s->signedPrintUrl())->assertOk();

        // إلغاء
        $svc->cancel($s, 'غلط في المبلغ', $this->admin);
        $this->assertSame(127.5, $this->owner->fresh()->walletBalance('store'));
        $this->assertSame(0, Order::where('store_settlement_id', $s->id)->count());
    }

    public function test_admin_pages(): void
    {
        $this->delivered('A1');
        $s = app(SettlementService::class)->create($this->owner, 'store', 85, 'cash', null, null, $this->admin);

        $this->actingAs($this->admin, 'web');
        $this->get(SettlementDesk::getUrl(['party' => 'driver', 'account' => $this->driver->id]))
            ->assertOk()->assertSee('سائق')->assertSee('A1')->assertSee('100.00');
        $this->get(ViewSettlement::getUrl(['record' => $s]))->assertOk()->assertSee($s->number);
        $this->get('/admin/settlements')->assertOk()->assertSee($s->number);
        $this->get(OrderResource::getUrl('view', ['record' => Order::first()]))
            ->assertOk()->assertSee('مين يحصّل ومين يسدد')->assertSee($s->number);

        Livewire::test(SettlementDesk::class, ['party' => 'driver'])
            ->set('userId', $this->driver->id)
            ->callAction('settle', ['direction' => 'receive', 'amount' => 100, 'method' => 'cash'])
            ->assertHasNoActionErrors();
        $this->assertSame(0.0, $this->driver->fresh()->walletBalance('driver'));
    }

    public function test_visibility_controls_api(): void
    {
        $o = $this->delivered('A1');

        $res = $this->actingAs($this->driver, 'sanctum')->getJson('/api/v1/driver/orders')->assertOk();
        $order = collect($res->json('data'))->firstWhere('code', 'A1');
        $this->assertSame(10.0, (float) $order['driver_earning']);
        $this->assertContains('أجرتك من الطلب', array_column($order['money']['lines'], 'label'));
        $this->assertNotContains('صافي المتجر', array_column($order['money']['lines'], 'label'));

        // الإدارة تخفي رقم الزبون والإجمالي على السائق
        Setting::put('opt.show.driver.customer_phone', '0');
        Setting::put('opt.show.driver.order_total', '0');
        Cache::flush();
        $order = collect($this->getJson('/api/v1/driver/orders')->json('data'))->firstWhere('code', 'A1');
        $this->assertNull($order['total']);
        $this->assertSame(110.0, (float) $order['cash_to_collect']); // الكاش دائماً
        $this->assertFalse($order['show']['customer_phone']);

        // المتجر: صافيه والعمولة
        $res = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/store/orders')->assertOk();
        $labels = array_column(collect($res->json('data'))->firstWhere('code', 'A1')['money']['lines'], 'label');
        $this->assertContains('صافي المتجر', $labels);
        $this->assertNotContains('أجرة السائق', $labels);

        // حسابي
        $this->getJson('/api/v1/store/account')->assertOk()->assertJsonPath('direction', 'pay')->assertJsonPath('due', 85);
        $this->actingAs($this->driver, 'sanctum')->getJson('/api/v1/driver/account')->assertOk()
            ->assertJsonPath('message', 'عليك للإدارة 100.00 د.ل');
    }

    public function test_driver_share_option(): void
    {
        Setting::put('opt.delivery.driver_share_percent', '80');
        Cache::flush();
        $this->assertSame(80.0, (float) Options::get('delivery.driver_share_percent'));
    }

    public function test_amount_words(): void
    {
        $this->assertSame('فقط ألفان وخمسون ديناراً لا غير', ArabicAmount::words(2050));
    }

    public function test_cash_to_collect_never_hidden_or_wrongly_paid(): void
    {
        $o = $this->delivered('C1');
        $o->update(['status' => OrderStatus::Ready, 'is_paid' => false, 'delivered_at' => null]);

        // المتجر مخبّي «اللي يدفعه الزبون» — واصل السائق لازم يعرف الكاش
        Setting::put('opt.show.store.order_total', '0');
        Cache::flush();
        $order = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/store/orders')->json('data'))->firstWhere('code', 'C1');
        $this->assertNull($order['total']);
        $this->assertSame(110.0, (float) $order['cash_to_collect']);

        // دفع إلكتروني ما كملش: مش «مدفوع»
        $o->update(['payment_method' => 'card', 'is_paid' => false]);
        $this->assertSame(110.0, OrderMoney::cashToCollect($o->fresh()));
        $o->update(['is_paid' => true]);
        $this->assertSame(0.0, OrderMoney::cashToCollect($o->fresh()));
    }

    public function test_customer_does_not_see_driver_phone_after_order_ends(): void
    {
        $o = $this->delivered('P1');
        $o->update(['status' => OrderStatus::PickedUp, 'delivered_at' => null]);
        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$o->id}")
            ->assertJsonPath('data.driver.phone', $this->driver->phone);

        $o->update(['status' => OrderStatus::Delivered]);
        $this->getJson("/api/v1/orders/{$o->id}")->assertJsonPath('data.driver.phone', null)
            ->assertJsonPath('data.driver.name', $this->driver->name);
        $this->assertNull(collect($this->getJson('/api/v1/orders')->json('data'))->firstWhere('id', $o->id)['driver']['phone'] ?? null);
    }
}
