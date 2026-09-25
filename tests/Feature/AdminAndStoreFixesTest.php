<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\AppSettings;
use App\Models\Banner;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAndStoreFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $customer;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->store = Store::create([
            'user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true,
            'lat' => 31.8686, 'lng' => 10.9817, 'phone' => '0921234567',
        ]);
        DeliveryZone::create(['name' => 'نالوت', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'phone' => '0910000000', 'email' => 'a@a.ly', 'password' => bcrypt('x'),
            'role' => UserRole::Admin->value, 'is_active' => true]);
    }

    // ===== قسم المنتج =====

    public function test_product_section_can_be_changed_and_cleared(): void
    {
        Sanctum::actingAs($this->owner);
        $a = $this->store->sections()->create(['name' => 'برجر', 'sort' => 1]);
        $b = $this->store->sections()->create(['name' => 'مشروبات', 'sort' => 2]);
        $p = $this->store->products()->create(['name' => 'x', 'price' => 5, 'menu_section_id' => $a->id]);

        $this->post("/api/v1/store/products/{$p->id}", ['menu_section_id' => $b->id], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($b->id, $p->fresh()->menu_section_id);

        $this->post("/api/v1/store/products/{$p->id}", ['menu_section_id' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertNull($p->fresh()->menu_section_id);

        // تعديل بدون القسم ما يمسحوش
        $p->refresh()->update(['menu_section_id' => $a->id]);
        $this->post("/api/v1/store/products/{$p->id}", ['name' => 'y'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($a->id, $p->fresh()->menu_section_id);
    }

    public function test_cannot_move_product_to_other_stores_section(): void
    {
        Sanctum::actingAs($this->owner);
        $other = Store::create(['user_id' => $this->customer->id, 'name' => 'o', 'slug' => 'o']);
        $foreign = $other->sections()->create(['name' => 'x', 'sort' => 1]);
        $p = $this->store->products()->create(['name' => 'x', 'price' => 5]);

        $this->post("/api/v1/store/products/{$p->id}", ['menu_section_id' => $foreign->id], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ===== جاهز بعد ما السائق قبل =====

    public function test_store_can_mark_ready_after_driver_assigned(): void
    {
        $driver = User::create(['name' => 'سائق', 'phone' => '0912222222', 'role' => UserRole::Driver->value, 'is_active' => true]);
        $order = Order::create([
            'code' => 'X1', 'customer_id' => $this->customer->id, 'store_id' => $this->store->id, 'driver_id' => $driver->id,
            'status' => OrderStatus::Assigned, 'address_details' => 'x', 'address_lat' => 31.87, 'address_lng' => 10.98,
            'customer_phone' => '1', 'accepted_at' => now()->subMinutes(30), 'prep_time_minutes' => 20,
        ]);

        Sanctum::actingAs($this->owner);
        $res = $this->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'ready'])->assertOk();

        $this->assertSame('assigned', $res->json('data.status'));
        $this->assertNotNull($res->json('data.ready_at'));
        $this->assertNotNull($order->fresh()->ready_at);

        // مرة ثانية = خطأ واضح
        $this->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'ready'])->assertStatus(422);
    }

    public function test_prep_notification_has_no_clock_time(): void
    {
        $order = Order::create([
            'code' => 'X2', 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'status' => OrderStatus::Pending, 'address_details' => 'x', 'address_lat' => 31.87, 'address_lng' => 10.98, 'customer_phone' => '1',
        ]);
        $order = app(OrderService::class)->transition($order, OrderStatus::Preparing, $this->owner, ['prep_time_minutes' => 15]);

        $body = (new \ReflectionMethod(OrderService::class, 'customerBody'))->invoke(app(OrderService::class), $order, OrderStatus::Preparing);
        $this->assertStringContainsString('سيكون جاهز خلال 15 دقيقة تقديرياً', $body);
        $this->assertStringNotContainsString('الساعة', $body);
    }

    // ===== العنوان الافتراضي =====

    public function test_default_address_flow(): void
    {
        Sanctum::actingAs($this->customer);
        $first = $this->postJson('/api/v1/addresses', ['details' => 'أ', 'lat' => 31.87, 'lng' => 10.98])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/addresses', ['details' => 'ب', 'lat' => 31.87, 'lng' => 10.98])->assertCreated()->json('data.id');

        // الأول صار افتراضي تلقائياً
        $this->assertSame($first, $this->getJson('/api/v1/addresses')->json('data.0.id'));

        $this->postJson("/api/v1/addresses/{$second}/default")->assertOk();
        $list = $this->getJson('/api/v1/addresses')->json('data');
        $this->assertSame($second, $list[0]['id']);
        $this->assertTrue($list[0]['is_default']);
        $this->assertFalse($list[1]['is_default']);

        // مسح الافتراضي = الباقي ياخذ مكانه
        $this->deleteJson("/api/v1/addresses/{$second}")->assertOk();
        $this->assertTrue($this->getJson('/api/v1/addresses')->json('data.0.is_default'));
    }

    // ===== الإعلانات و«عن التطبيق» =====

    public function test_app_content_returns_live_banners_and_about(): void
    {
        Banner::create(['title' => 'عرض', 'sort' => 2, 'is_active' => true, 'store_id' => $this->store->id]);
        Banner::create(['title' => 'أول', 'sort' => 1, 'is_active' => true]);
        Banner::create(['title' => 'موقوف', 'is_active' => false]);
        Banner::create(['title' => 'منتهي', 'is_active' => true, 'ends_at' => now()->subDay()]);
        Banner::create(['title' => 'لسه', 'is_active' => true, 'starts_at' => now()->addDay()]);
        Setting::put('about.phone', '0910000001');

        $res = $this->getJson('/api/v1/app/content')->assertOk();
        $this->assertSame(['أول', 'عرض'], collect($res->json('banners'))->pluck('title')->all());
        $this->assertSame($this->store->id, $res->json('banners.1.store_id'));
        $this->assertSame('0910000001', $res->json('about.phone'));
        $this->assertSame('توصيل نالوت', $res->json('about.name'));
    }

    public function test_store_detail_has_phone(): void
    {
        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk()->assertJsonPath('data.phone', '0921234567');
    }

    // ===== لوحة التحكم: الصفحات تنفتح بدون أخطاء =====

    public function test_admin_pages_render(): void
    {
        $this->actingAs($this->admin());
        $zone = DeliveryZone::first();

        foreach ([
            '/admin/stores/create', "/admin/stores/{$this->store->id}/edit",
            '/admin/delivery-zones/create', "/admin/delivery-zones/{$zone->id}/edit",
            '/admin/banners', '/admin/banners/create', '/admin/app-settings',
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $html = $this->get('/admin/delivery-zones/create')->getContent();
        $this->assertStringContainsString('data.center_lat', $html);
        $this->assertStringContainsString('data.radius_km', $html);
    }

    public function test_app_settings_page_saves(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AppSettings::class)
            ->set('data.name', 'نالوت إكسبرس')
            ->set('data.whatsapp', '218910000000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('نالوت إكسبرس', AppSettings::values()['name']);
        $this->assertSame('218910000000', AppSettings::values()['whatsapp']);
    }

    public function test_store_requires_location_in_admin(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(\App\Filament\Resources\Stores\Pages\CreateStore::class)
            ->fillForm(['name' => 'جديد', 'user_id' => $this->owner->id, 'lat' => null, 'lng' => null])
            ->call('create')
            ->assertHasFormErrors(['lat' => 'required', 'lng' => 'required']);
    }
}
