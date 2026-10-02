<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeliveryZone;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\SupportService;
use App\Services\WalletService;
use App\Support\Options;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** v78: إيقاف قسم كامل · الاستلام من المطعم · تطبيق الإدارة */
class SectionPausePickupAdminAppTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private MenuSection $pastry;

    private Product $pie;

    private Product $shawarma;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true,
            'lat' => 31.8686, 'lng' => 10.9817, 'commission_percent' => 10]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
        $this->pastry = $this->store->sections()->create(['name' => 'المعجنات', 'sort' => 1]);
        $meals = $this->store->sections()->create(['name' => 'وجبات', 'sort' => 2]);
        $this->pie = $this->store->products()->create(['name' => 'فطيرة', 'price' => 5, 'is_available' => true, 'menu_section_id' => $this->pastry->id]);
        $this->shawarma = $this->store->products()->create(['name' => 'شاورما', 'price' => 10, 'is_available' => true, 'menu_section_id' => $meals->id]);
    }

    private function customer(float $wallet = 0): User
    {
        $c = User::create(['name' => 'زبون', 'phone' => '0913000001', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $c->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);
        if ($wallet > 0) {
            app(WalletService::class)->credit($c, $wallet, 'topup_cash');
        }

        return $c;
    }

    // ===== إيقاف القسم =====

    public function test_store_pauses_a_whole_section_visible_but_not_orderable(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/store/sections/{$this->pastry->id}/availability", ['available' => false])
            ->assertOk()->assertJsonPath('data.is_available', false)->assertJsonPath('data.paused_text', 'غير متاح حالياً');

        // الزبون يشوف القسم وأصنافه، بس موقوفين
        $c = $this->customer();
        Sanctum::actingAs($c);
        $res = $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk();
        $section = collect($res->json('data.sections'))->firstWhere('id', $this->pastry->id);
        $this->assertFalse($section['is_available']);
        $pie = collect($res->json('data.products'))->firstWhere('id', $this->pie->id);
        $this->assertFalse($pie['is_available']);
        $this->assertTrue($pie['section_paused']);
        $this->assertTrue(collect($res->json('data.products'))->firstWhere('id', $this->shawarma->id)['is_available']);

        // الطلب يترفض برسالة واضحة
        $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $c->addresses()->first()->id,
            'payment_method' => 'cash', 'items' => [['product_id' => $this->pie->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonFragment(['items' => ['«فطيرة» من قسم «المعجنات» — غير متاح حالياً.']]);

        // تطبيق المتجر يشوف القيمة الأصلية للصنف (فورم التعديل) + حالة القسم
        Sanctum::actingAs($this->owner);
        $p = collect($this->getJson('/api/v1/store/products')->json('data'))->firstWhere('id', $this->pie->id);
        $this->assertTrue($p['is_available']);
        $this->assertTrue($p['section_paused']);
    }

    public function test_section_pause_until_time_reopens_by_itself(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 08:00', 'Africa/Tripoli'));
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/store/sections/{$this->pastry->id}/availability", ['available' => false, 'until' => '16:00'])
            ->assertOk()->assertJsonPath('data.paused_text', 'يتوفر الساعة 16:00');
        $this->assertFalse($this->pastry->fresh()->isOrderable());

        Carbon::setTestNow(Carbon::parse('2026-10-10 16:01', 'Africa/Tripoli'));
        $this->assertTrue($this->pastry->fresh()->isOrderable());
        Carbon::setTestNow();
    }

    // ===== الاستلام من المطعم =====

    public function test_pickup_requires_electronic_payment(): void
    {
        $c = $this->customer(100);
        Sanctum::actingAs($c);
        $base = ['store_id' => $this->store->id, 'fulfillment' => 'pickup', 'items' => [['product_id' => $this->shawarma->id, 'quantity' => 2]]];

        $this->postJson('/api/v1/orders', $base + ['payment_method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        Setting::put('opt.pickup.allow_wallet', '0');
        Cache::flush();
        // بدون بوابة دفع وبدون المحفظة: الاستلام كله يتقفل
        $this->postJson('/api/v1/orders', $base + ['payment_method' => 'wallet'])->assertStatus(422)->assertJsonValidationErrors('fulfillment');
        $this->assertFalse($this->getJson("/api/v1/stores/{$this->store->id}")->json('data.pickup_available'));
    }

    public function test_pickup_full_flow_with_code(): void
    {
        $c = $this->customer(100);
        Sanctum::actingAs($c);

        $q = $this->postJson('/api/v1/orders/quote', ['store_id' => $this->store->id, 'fulfillment' => 'pickup', 'payment_method' => 'wallet',
            'items' => [['product_id' => $this->shawarma->id, 'quantity' => 2]]])->assertOk();
        $this->assertEquals(0, $q->json('delivery_fee'));
        $this->assertTrue($q->json('pickup_available'));

        $res = $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'fulfillment' => 'pickup', 'payment_method' => 'wallet',
            'items' => [['product_id' => $this->shawarma->id, 'quantity' => 2]]])->assertCreated();
        $order = Order::find($res->json('data.id'));
        $this->assertTrue($order->isPickup());
        $this->assertEquals(0, $order->delivery_fee);
        $this->assertEquals(20, $order->total);
        $this->assertTrue($order->is_paid);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $order->pickup_code);
        $this->assertSame($order->pickup_code, $res->json('data.pickup_code'));

        // المتجر ما يشوفش الرمز
        Sanctum::actingAs($this->owner);
        $row = collect($this->getJson('/api/v1/store/orders?status=active')->json('data'))->firstWhere('id', $order->id);
        $this->assertTrue($row['is_pickup']);
        $this->assertArrayNotHasKey('pickup_code', $row);

        $this->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'preparing'])->assertOk();
        // السائقين ما يشوفوهش
        $this->assertFalse($order->fresh()->isAvailableForDrivers());
        $this->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'ready'])->assertOk()
            ->assertJsonPath('data.status_label', 'جاهز — تعال استلمه');
        $this->assertFalse($order->fresh()->isAvailableForDrivers());

        $this->postJson("/api/v1/store/orders/{$order->id}/handover", ['code' => '99999'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson("/api/v1/store/orders/{$order->id}/handover", ['code' => $order->pickup_code])->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        // المستحقات للمتجر، وما فيش سائق
        $this->assertSame(18.0, app(WalletService::class)->balance($this->owner, 'store'));
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_store_can_disable_pickup(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/store/pickup', ['enabled' => false])->assertOk()->assertJsonPath('pickup_enabled', false);

        Sanctum::actingAs($this->customer(100));
        $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'fulfillment' => 'pickup', 'payment_method' => 'wallet',
            'items' => [['product_id' => $this->shawarma->id, 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('fulfillment');
    }

    // ===== تطبيق الإدارة =====

    private function admin(array $permissions = []): User
    {
        return User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'boss@azanx.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true, 'permissions' => $permissions ?: null]);
    }

    public function test_admin_login_only_for_admins_with_password(): void
    {
        $this->admin();
        $this->postJson('/api/v1/admin/login', ['login' => 'boss@azanx.ly', 'password' => 'bad'])->assertStatus(422);
        $res = $this->postJson('/api/v1/admin/login', ['login' => 'boss@azanx.ly', 'password' => 'secret123', 'fcm_token' => 'tok-admin'])
            ->assertOk()->assertJsonPath('user.super', true);
        $this->assertNotEmpty($res->json('token'));
        $this->assertSame('tok-admin', User::where('email', 'boss@azanx.ly')->first()->pushTokenFor('admin'));

        // نفس المسار للمتجر يترفض
        $this->owner->update(['password' => 'secret123']);
        $this->postJson('/api/v1/admin/login', ['login' => '0911111111', 'password' => 'secret123'])->assertStatus(422);
        // والمتجر ما يدخلش لمسارات الإدارة
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/admin/summary')->assertForbidden();
    }

    public function test_admin_handles_orders_tickets_and_settings(): void
    {
        $admin = $this->admin();
        $c = $this->customer();
        Sanctum::actingAs($c);
        $orderId = $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $c->addresses()->first()->id,
            'payment_method' => 'cash', 'items' => [['product_id' => $this->shawarma->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
        $ticket = app(SupportService::class)->open($c, 'customer', 'order', 'وين طلبي؟');

        Sanctum::actingAs($admin);
        $sum = $this->getJson('/api/v1/admin/summary')->assertOk();
        $this->assertSame(1, $sum->json('orders.pending'));
        $this->assertSame(1, $sum->json('tickets.unread'));

        $this->assertCount(1, $this->getJson('/api/v1/admin/orders')->json('data'));
        $this->getJson("/api/v1/admin/orders/$orderId")->assertOk()->assertJsonPath('data.id', $orderId);
        $this->postJson("/api/v1/admin/orders/$orderId/status", ['status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/orders/$orderId/status", ['status' => 'cancelled', 'reason' => 'طلب الزبون'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->getJson('/api/v1/admin/tickets')->assertOk()->assertJsonPath('data.0.code', $ticket->code);
        $this->postJson("/api/v1/admin/tickets/{$ticket->id}/reply", ['body' => 'طلبك في الطريق'])->assertOk()
            ->assertJsonPath('data.status', 'answered')->assertJsonPath('data.messages.1.sender', 'مدير');
        $this->assertFalse($ticket->fresh()->admin_unread);

        $groups = collect($this->getJson('/api/v1/admin/settings')->assertOk()->json('data'));
        $this->assertNotNull($groups->firstWhere('key', 'pickup'));
        $this->postJson('/api/v1/admin/settings', ['values' => ['pickup.enabled' => false, 'orders.default_prep_minutes' => 25]])->assertOk();
        Cache::flush();
        $this->assertFalse((bool) Options::get('pickup.enabled'));
        $this->postJson('/api/v1/admin/settings', ['values' => ['orders.default_prep_minutes' => 9999]])->assertStatus(422);
        $this->postJson('/api/v1/admin/settings', ['values' => ['show.store.balance' => true]])->assertStatus(422);

        $this->postJson("/api/v1/admin/stores/{$this->store->id}/toggle")->assertOk()->assertJsonPath('is_accepting', false);
    }

    public function test_admin_permissions_are_enforced(): void
    {
        $support = $this->admin(['support.manage']);
        Sanctum::actingAs($support);
        $this->getJson('/api/v1/admin/tickets')->assertOk();
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->getJson('/api/v1/admin/me')->assertJsonPath('user.can.support', true)->assertJsonPath('user.can.orders', false);
    }

    public function test_admin_alerts_reach_admin_app_token(): void
    {
        $admin = $this->admin();
        $admin->setPushToken('admin', 'tok-admin');
        $c = $this->customer();
        app(SupportService::class)->open($c, 'customer', 'order', 'مشكلة');

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/alerts')->assertOk()->assertJsonPath('data.0.read', false);
        $this->postJson('/api/v1/admin/alerts/read')->assertOk();
        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
    }
}
