<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\AnalyticsReport;
use App\Filament\Pages\MonitorScreen;
use App\Http\Controllers\Admin\MonitorController;
use App\Models\Address;
use App\Models\AppSection;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PointsTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReferralService;
use App\Support\Analytics;
use App\Support\DeliverySubsidy;
use App\Support\OrderMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** v86: الإحصاءات وشاشة المراقبة · دعم التوصيل · ادعُ صديقك */
class AnalyticsSubsidyReferralTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private User $driver;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Cache::flush();
        $this->admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'سالم علي', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->driver = User::create(['name' => 'علي', 'phone' => '0912222222', 'role' => UserRole::Driver->value, 'is_active' => true]);
        DriverProfile::create(['user_id' => $this->driver->id, 'is_approved' => true, 'is_online' => true]);
        $section = AppSection::create(['name' => 'مطاعم', 'sort' => 1, 'is_active' => true]);
        $type = StoreType::create(['name' => 'مطعم', 'app_section_id' => $section->id, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $owner->id, 'store_type_id' => $type->id, 'name' => 'مطعم نالوت', 'slug' => 'm',
            'is_active' => true, 'is_open' => true, 'lat' => 31.87, 'lng' => 10.98, 'commission_percent' => 10]);
    }

    private function order(array $attrs = []): Order
    {
        static $i = 0;
        $i++;

        $created = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $o = Order::create($attrs + [
            'code' => 'A'.$i, 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'status' => OrderStatus::Pending, 'address_details' => 'x', 'address_lat' => 31.8, 'address_lng' => 10.9,
            'customer_phone' => '1', 'subtotal' => 20, 'delivery_fee' => 5, 'total' => 25,
        ]);
        if ($created) {
            $o->forceFill(['created_at' => $created])->saveQuietly();
        }

        return $o->fresh();
    }

    // ===== دعم التوصيل =====

    public function test_subsidy_modes(): void
    {
        $this->assertSame([10.0, 0.0], DeliverySubsidy::split(10, 30));

        Setting::put('opt.delivery.subsidy_mode', 'percent');
        Setting::put('opt.delivery.subsidy_value', '50');
        $this->assertSame([5.0, 5.0], DeliverySubsidy::split(10, 30));

        Setting::put('opt.delivery.subsidy_mode', 'fixed');
        Setting::put('opt.delivery.subsidy_value', '7');
        $this->assertSame([3.0, 7.0], DeliverySubsidy::split(10, 30));
        $this->assertSame([0.0, 4.0], DeliverySubsidy::split(4, 30)); // ما يتجاوزش الرسوم

        Setting::put('opt.delivery.subsidy_mode', 'customer_max');
        Setting::put('opt.delivery.subsidy_value', '3');
        $this->assertSame([3.0, 7.0], DeliverySubsidy::split(10, 30));
        $this->assertSame([2.0, 0.0], DeliverySubsidy::split(2, 30));

        Setting::put('opt.delivery.subsidy_min_subtotal', '50');
        $this->assertSame([10.0, 0.0], DeliverySubsidy::split(10, 30)); // الطلب أقل من الحد
    }

    public function test_order_uses_subsidy_and_driver_gets_full_fee(): void
    {
        Setting::put('opt.delivery.base_fee', '10');
        Setting::put('opt.delivery.fee_per_km', '0');
        Setting::put('opt.delivery.subsidy_mode', 'percent');
        Setting::put('opt.delivery.subsidy_value', '50');
        $product = Product::create(['store_id' => $this->store->id, 'name' => 'شاورما', 'price' => 20, 'is_available' => true]);
        $address = Address::create(['user_id' => $this->customer->id, 'details' => 'حي', 'lat' => 31.871, 'lng' => 10.981]);

        Sanctum::actingAs($this->customer);
        $quote = $this->postJson('/api/v1/orders/quote', ['store_id' => $this->store->id, 'address_id' => $address->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertOk()->json();
        $this->assertEquals(5, $quote['delivery_fee']);
        $this->assertEquals(10, $quote['delivery_fee_full']);
        $this->assertEquals(25, $quote['total']);

        $order = app(OrderService::class)->create($this->customer, ['store_id' => $this->store->id, 'address_id' => $address->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash']);
        $this->assertEquals(5, $order->delivery_fee);
        $this->assertEquals(5, $order->delivery_subsidy);
        $this->assertEquals(10, $order->driver_earning); // الأجرة على الرسوم الكاملة
        $this->assertEquals(25, $order->total);

        $n = OrderMoney::numbers($order);
        // العمولة 2 + (5 − 10) = −3 : الشركة دفعت 5 دعم
        $this->assertEquals(-3, $n['platform_net']);
        $lines = collect(OrderMoney::lines($order, 'admin'))->pluck('label');
        $this->assertTrue($lines->contains('دعم التوصيل (تدفعه الشركة)'));
    }

    // ===== ادعُ صديقك =====

    private function enableReferrals(): void
    {
        Setting::put('opt.points.enabled', '1');
        Setting::put('opt.referral.enabled', '1');
        Setting::put('opt.referral.referrer_points', '40');
        Setting::put('opt.referral.referee_points', '30');
    }

    public function test_referral_flow_rewards_both_on_first_delivered_order(): void
    {
        $this->enableReferrals();
        Sanctum::actingAs($this->customer);
        $mine = $this->getJson('/api/v1/referral')->assertOk()->json('data');
        $this->assertTrue($mine['enabled']);
        $this->assertSame(6, strlen($mine['code']));
        $this->assertStringContainsString('/r/'.$mine['code'], $mine['link']);
        $this->assertStringContainsString($mine['link'], $mine['share_text']);

        // صفحة الرابط: Google Play معاه الكود
        $this->get('/r/'.$mine['code'])->assertOk()->assertSee('referrer=ref%3D'.$mine['code'], false)->assertSee($mine['code']);
        $this->get('/r/ZZZZZZ')->assertNotFound();

        $friend = User::create(['name' => 'صديق', 'phone' => '0914444444', 'role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($friend);
        $this->postJson('/api/v1/referral', ['code' => 'nope12'])->assertStatus(422);
        $this->postJson('/api/v1/referral', ['code' => strtolower($mine['code'])])->assertOk();
        $this->assertSame($this->customer->id, $friend->fresh()->referred_by_id);
        $this->postJson('/api/v1/referral', ['code' => $mine['code']])->assertStatus(422); // مرة وحدة

        // كودي أنا ما ينفعش
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/referral', ['code' => $mine['code']])->assertStatus(422);

        // أول طلب للصديق يتسلّم → الهدية للاثنين
        $o = $this->order(['customer_id' => $friend->id, 'driver_id' => $this->driver->id, 'status' => OrderStatus::OnTheWay, 'wallet_paid' => 25]);
        app(OrderService::class)->transition($o, OrderStatus::Delivered, $this->driver);
        $this->assertGreaterThanOrEqual(30, $friend->fresh()->points_balance);
        $this->assertGreaterThanOrEqual(40, $this->customer->fresh()->points_balance);
        $this->assertNotNull($friend->fresh()->referral_rewarded_at);

        // الطلب الثاني ما يعطيش هدية ثانية
        $before = $this->customer->fresh()->points_balance;
        $o2 = $this->order(['customer_id' => $friend->id, 'driver_id' => $this->driver->id, 'status' => OrderStatus::OnTheWay, 'wallet_paid' => 25]);
        app(OrderService::class)->transition($o2, OrderStatus::Delivered, $this->driver);
        $this->assertSame($before, $this->customer->fresh()->points_balance);

        Sanctum::actingAs($this->customer);
        $s = $this->getJson('/api/v1/referral')->json('data');
        $this->assertSame(1, $s['invited']);
        $this->assertSame(1, $s['rewarded']);
        $this->assertSame(40, $s['points_earned']);
    }

    public function test_referral_only_for_new_accounts_and_monthly_cap(): void
    {
        $this->enableReferrals();
        $code = app(ReferralService::class)->codeFor($this->customer);

        $old = User::create(['name' => 'قديم', 'phone' => '0915555555', 'role' => 'customer', 'is_active' => true]);
        $old->forceFill(['created_at' => now()->subDays(30)])->save();
        Sanctum::actingAs($old);
        $this->postJson('/api/v1/referral', ['code' => $code])->assertStatus(422);
        $this->assertFalse($this->getJson('/api/v1/referral')->json('data.can_enter_code'));

        Setting::put('opt.referral.monthly_cap', '1');
        foreach (['0916666661', '0916666662'] as $phone) {
            $f = User::create(['name' => 'ص', 'phone' => $phone, 'role' => 'customer', 'is_active' => true]);
            app(ReferralService::class)->apply($f, $code);
            $o = $this->order(['customer_id' => $f->id, 'driver_id' => $this->driver->id, 'status' => OrderStatus::OnTheWay, 'wallet_paid' => 25]);
            app(OrderService::class)->transition($o, OrderStatus::Delivered, $this->driver);
            $this->assertGreaterThanOrEqual(30, $f->fresh()->points_balance); // الصديق ياخذ دائماً
        }
        // صاحب الرابط: مرة وحدة بس في الشهر (الحد 1) + نقاط الطلب العادية
        $this->assertSame(40, (int) PointsTransaction::where('user_id', $this->customer->id)->where('type', 'referral')->sum('points'));
    }

    public function test_referral_disabled_without_points(): void
    {
        Setting::put('opt.referral.enabled', '1');
        Sanctum::actingAs($this->customer);
        $this->assertFalse($this->getJson('/api/v1/referral')->json('data.enabled'));
    }

    // ===== الإحصاءات =====

    public function test_monitor_numbers_problems_and_access(): void
    {
        $this->order(['status' => OrderStatus::Pending, 'created_at' => now()->subMinutes(20)]);
        $this->order(['status' => OrderStatus::Delivered, 'accepted_at' => now()->subMinutes(40), 'ready_at' => now()->subMinutes(25),
            'picked_up_at' => now()->subMinutes(20), 'delivered_at' => now(), 'created_at' => now()->subMinutes(45), 'driver_id' => $this->driver->id]);
        $this->order(['status' => OrderStatus::Cancelled]);

        $m = Analytics::monitor();
        $this->assertSame(3, $m['kpis']['orders_today']);
        $this->assertSame(1, $m['kpis']['active']);
        $this->assertSame(1, $m['kpis']['delivered_today']);
        $this->assertEquals(50.0, $m['kpis']['completion_rate']);
        $this->assertEquals(45.0, $m['kpis']['avg_delivery_minutes']);
        $this->assertEquals(15.0, $m['kpis']['avg_prep_minutes']);
        $this->assertSame('مطاعم', $m['sections'][0]['name']);
        $this->assertSame(1, $m['drivers']['online']);
        $this->assertSame('المتجر ما قبلش الطلب', $m['problems'][0]['title']);

        // الدخول: بدون → ممنوع · بالرابط → مسموح · إداري → مسموح
        $this->get('/monitor')->assertForbidden();
        $this->getJson('/monitor/data')->assertForbidden();
        $key = MonitorController::key();
        $this->get('/monitor?key='.$key)->assertOk()->assertSee('شاشة المراقبة');
        $this->getJson('/monitor/data?key='.$key)->assertOk()->assertJsonPath('kpis.orders_today', 3);
        $this->getJson('/monitor/data?key=wrong')->assertForbidden();

        $this->actingAs($this->admin)->get('/monitor')->assertOk();
        $this->get(MonitorScreen::getUrl())->assertOk()->assertSee('رابط الشاشة الكبيرة');
    }

    public function test_report_rates_times_and_retention(): void
    {
        // زبون رجع: طلب قديم + طلبين في الفترة
        $this->order(['status' => OrderStatus::Delivered, 'created_at' => now()->subDays(40), 'delivered_at' => now()->subDays(40)]);
        $this->order(['status' => OrderStatus::Delivered, 'created_at' => now()->subDays(2), 'accepted_at' => now()->subDays(2)->addMinutes(2),
            'ready_at' => now()->subDays(2)->addMinutes(17), 'picked_up_at' => now()->subDays(2)->addMinutes(20),
            'delivered_at' => now()->subDays(2)->addMinutes(35), 'distance_km' => 5, 'driver_id' => $this->driver->id]);
        $this->order(['status' => OrderStatus::Delivered, 'created_at' => now()->subDay(), 'delivered_at' => now()->subDay()->addMinutes(50)]);
        $this->order(['status' => OrderStatus::Cancelled, 'cancelled_by' => $this->customer->id, 'created_at' => now()->subDay()]);
        $this->order(['status' => OrderStatus::Failed, 'created_at' => now()->subDay()]);

        [$f, $t] = Analytics::range('7');
        $r = Analytics::report($f, $t);

        $this->assertSame(4, $r['orders']['total']);
        $this->assertEquals(50.0, $r['orders']['completion_rate']);
        $this->assertEquals(25.0, $r['orders']['cancel_rate']);
        $this->assertSame(1, $r['orders']['cancel_by']['customer']);
        $this->assertEquals(15.0, $r['times']['prep']);
        $this->assertEquals(15.0, $r['times']['road']);
        $this->assertEquals(20.0, $r['times']['avg_speed_kmh']); // 5 كم في 15 دقيقة
        $this->assertEquals(42.5, $r['times']['total']);
        $this->assertEquals(100.0, $r['customers']['returning_rate']);
        $this->assertEquals(100.0, $r['customers']['repeat_in_period']);
        $this->assertSame(50.0, (float) $r['money']['sales']);
        $this->assertSame(7, count($r['daily']));
        $this->assertSame('مطاعم', $r['sections'][0]['name']);

        // اللوحة + تطبيق الإدارة
        $this->actingAs($this->admin)->get(AnalyticsReport::getUrl())->assertOk()->assertSee('السرعة والأوقات')->assertSee('صافي المنصة');
    }

    public function test_admin_app_endpoints_hide_money_without_finance(): void
    {
        $staff = User::create(['name' => 'موظف', 'phone' => '0934444444', 'password' => 'x12345678',
            'role' => 'admin', 'permissions' => ['orders.view'], 'is_active' => true]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/admin/monitor')->assertOk()->assertJsonStructure(['kpis', 'drivers', 'problems', 'sections']);
        $r = $this->getJson('/api/v1/admin/analytics?range=30')->assertOk()->json();
        $this->assertArrayNotHasKey('money', $r);

        Sanctum::actingAs($this->admin);
        $this->assertArrayHasKey('money', $this->getJson('/api/v1/admin/analytics?from=2026-01-01&to=2026-01-31')->assertOk()->json());
    }
}
