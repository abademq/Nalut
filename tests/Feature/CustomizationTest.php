<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\BrandingSettings;
use App\Filament\Pages\OperationsSettings;
use App\Filament\Resources\AppTexts\AppTextResource;
use App\Models\AppText;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\GeoService;
use App\Services\OrderService;
use App\Support\Options;
use App\Support\Texts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomizationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Product $product;
    private User $customer;
    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
        $this->product = $this->store->products()->create(['name' => 'كعكة', 'price' => 10, 'is_available' => true, 'track_stock' => true, 'stock_quantity' => 2]);

        $this->customer = User::create(['name' => 'ز', 'phone' => '0913000001', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->address = $this->customer->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);
    }

    private function placeOrder(int $qty): Order
    {
        Sanctum::actingAs($this->customer);
        $id = $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $this->address->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty]]])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    // ===== المخزون =====

    public function test_cancel_returns_stock_and_shows_sold_out_product_again(): void
    {
        $order = $this->placeOrder(2);
        $p = $this->product->fresh();
        $this->assertSame(0, $p->stock_quantity);
        $this->assertFalse($p->is_available);
        $this->assertNotNull($p->sold_out_at);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $p = $this->product->fresh();
        $this->assertSame(2, $p->stock_quantity);
        $this->assertTrue($p->is_available, 'المنتج لازم يرجع يظهر');
        $this->assertNull($p->sold_out_at);
    }

    public function test_stock_is_restored_only_once(): void
    {
        $order = $this->placeOrder(1);
        $svc = app(OrderService::class);
        $svc->transition($order, OrderStatus::Cancelled);

        // الإدارة رجّعته وألغته مرة ثانية
        $svc->transition($order->fresh(), OrderStatus::Pending, null, ['force' => true]);
        $svc->transition($order->fresh(), OrderStatus::Cancelled, null, ['force' => true]);

        $this->assertSame(2, $this->product->fresh()->stock_quantity);
    }

    public function test_manually_hidden_product_stays_hidden_after_restore(): void
    {
        $order = $this->placeOrder(1);
        $this->product->fresh()->update(['is_available' => false]); // المتجر خبّاه بيده

        app(OrderService::class)->transition($order, OrderStatus::Cancelled);

        $p = $this->product->fresh();
        $this->assertSame(2, $p->stock_quantity);
        $this->assertFalse($p->is_available);
    }

    public function test_failed_after_pickup_restores_only_when_enabled(): void
    {
        $order = $this->placeOrder(1);
        $order->forceFill(['picked_up_at' => now(), 'status' => OrderStatus::OnTheWay])->save();

        app(OrderService::class)->transition($order, OrderStatus::Failed, null, ['reason' => 'x']);
        $this->assertSame(1, $this->product->fresh()->stock_quantity);

        Setting::put('opt.stock.restore_after_pickup', '1');
        $order2 = $this->placeOrder(1);
        $order2->forceFill(['picked_up_at' => now(), 'status' => OrderStatus::OnTheWay])->save();
        app(OrderService::class)->transition($order2, OrderStatus::Failed, null, ['reason' => 'x']);
        $this->assertSame(1, $this->product->fresh()->stock_quantity);
    }

    public function test_refilling_stock_brings_sold_out_product_back(): void
    {
        $this->placeOrder(2);
        $this->product->fresh()->update(['stock_quantity' => 5, 'is_available' => false]);

        $this->assertTrue($this->product->fresh()->is_available);
    }

    // ===== النصوص =====

    public function test_fill_removes_empty_vars_with_separator(): void
    {
        $this->assertSame('تم إلغاء طلبك', Texts::fill('تم إلغاء طلبك: {reason}', ['reason' => '']));
        $this->assertSame('تم إلغاء طلبك: المتجر مقفل', Texts::fill('تم إلغاء طلبك: {reason}', ['reason' => 'المتجر مقفل']));
        $this->assertSame('طلب 15 جاهز', Texts::fill('طلب {code} جاهز', ['code' => 15]));
    }

    public function test_admin_can_override_notification_and_status_texts(): void
    {
        Texts::sync();

        AppText::for('server')->where('key', 'notify.customer.preparing')->first()
            ->update(['value' => 'طلبك في المطبخ 👨‍🍳 — {prep} دقيقة']);
        AppText::for('server')->where('key', 'status.on_the_way')->first()
            ->update(['value' => 'السائق جاي']);

        $order = $this->placeOrder(1);
        $order->update(['prep_time_minutes' => 25]);

        $body = (new \ReflectionMethod(OrderService::class, 'customerBody'))
            ->invoke(app(OrderService::class), $order, OrderStatus::Preparing);

        $this->assertSame('طلبك في المطبخ 👨‍🍳 — 25 دقيقة', $body);
        $this->assertSame('السائق جاي', OrderStatus::OnTheWay->label());

        // الرجوع للأصل
        AppText::for('server')->where('key', 'status.on_the_way')->first()->update(['value' => '']);
        $this->assertSame('في الطريق إليك', OrderStatus::OnTheWay->label());
    }

    public function test_server_messages_use_overrides(): void
    {
        Texts::sync();
        AppText::for('server')->where('key', 'msg.stock_left')->first()
            ->update(['value' => 'باقي {left} بس من {name}']);

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $this->address->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]]])
            ->assertStatus(422)->assertJsonPath('errors.items.0', 'باقي 2 بس من كعكة');
    }

    public function test_sync_keeps_overrides_and_marks_removed_texts(): void
    {
        Texts::sync();
        $row = AppText::for('server')->where('key', 'msg.cart_empty')->first();
        $row->update(['value' => 'سلتك فاضية']);

        AppText::create(['app' => 'server', 'group' => 'x', 'key' => 'old.key', 'default' => 'قديم']);

        Texts::sync();

        $this->assertSame('سلتك فاضية', $row->fresh()->value);
        $this->assertFalse(AppText::for('server')->where('key', 'old.key')->first()->is_used);
    }

    public function test_app_content_returns_texts_options_and_receipt(): void
    {
        AppText::create(['app' => 'store', 'group' => 'orders_tab', 'key' => 'قبول', 'default' => 'قبول', 'value' => 'اقبل الطلب']);
        AppText::create(['app' => 'store', 'group' => 'orders_tab', 'key' => 'رفض', 'default' => 'رفض']);
        Setting::put('opt.orders.prep_choices', '15,5,30');
        Setting::put('receipt.customer.footer', 'بالهناء والشفاء');

        $res = $this->getJson('/api/v1/app/content?app=store')->assertOk();

        $this->assertSame(['قبول' => 'اقبل الطلب'], $res->json('texts'));
        $this->assertSame([5, 15, 30], $res->json('options')['orders.prep_choices']);
        $this->assertSame('بالهناء والشفاء', $res->json('receipt.customer.footer'));
        $this->assertTrue($res->json('receipt.store.show_phone'));
        $this->assertTrue($res->json('receipt.customer.show_phone')); // نسخة السائق
        $this->assertArrayNotHasKey('banners', $res->json());

        // تطبيق الزبون القديم (بدون ?app) يكمّل يوصله الإعلانات
        $this->getJson('/api/v1/app/content')->assertOk()->assertJsonStructure(['banners', 'about', 'texts']);
    }

    // ===== الخيارات =====

    public function test_options_change_behaviour(): void
    {
        Setting::put('opt.delivery.base_fee', '7');
        Setting::put('opt.delivery.fee_per_km', '0');
        $this->assertEquals(7, GeoService::deliveryFee(3));

        Setting::put('opt.orders.customer_cancel_until', 'never');
        $order = $this->placeOrder(1);
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(422);

        Setting::put('opt.wallet.card_digits', '9');
        $this->assertSame(9, strlen(\App\Models\RechargeCard::generateCode()));
    }

    public function test_driver_status_update_stores_location_for_tracking(): void
    {
        $driver = User::create(['name' => 'س', 'phone' => '0915000001', 'role' => UserRole::Driver->value, 'is_active' => true]);
        $driver->driverProfile()->create(['is_approved' => true, 'is_online' => true]);
        $order = $this->placeOrder(1);
        $order->forceFill(['driver_id' => $driver->id, 'status' => OrderStatus::PickedUp, 'is_paid' => true])->save();

        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/driver/orders/{$order->id}/status", ['status' => 'on_the_way', 'lat' => 31.861, 'lng' => 10.99])
            ->assertOk();

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/orders/{$order->id}/track")->assertOk()
            ->assertJsonPath('status', 'on_the_way')
            ->assertJsonPath('driver_location.lat', 31.861);
    }

    // ===== صفحات اللوحة =====

    public function test_admin_pages_render(): void
    {
        $admin = User::create(['name' => 'مدير', 'phone' => '0910000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        Texts::sync();
        $this->actingAs($admin, 'web');

        $this->get(OperationsSettings::getUrl())->assertOk()->assertSee('مدة التحضير الافتراضية');
        $this->get(BrandingSettings::getUrl())->assertOk()->assertSee('نسخة السائق');
        $this->get(AppTextResource::getUrl())->assertOk()->assertSee('حالات الطلب');
    }

    public function test_operations_page_saves(): void
    {
        $admin = User::create(['name' => 'مدير', 'phone' => '0910000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web');

        \Livewire\Livewire::test(OperationsSettings::class)
            ->set('data.orders__default_prep_minutes', 35)
            ->set('data.orders__prep_choices', '20, 10,35')
            ->set('data.stock__restore_after_pickup', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(35, Options::get('orders.default_prep_minutes'));
        $this->assertSame([10, 20, 35], Options::get('orders.prep_choices'));
        $this->assertTrue(Options::get('stock.restore_after_pickup'));
    }

    public function test_branding_page_saves_receipt_options(): void
    {
        $admin = User::create(['name' => 'مدير', 'phone' => '0910000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web');

        \Livewire\Livewire::test(BrandingSettings::class)
            ->set('data.header', 'أسرع توصيل في نالوت')
            ->set('data.store.show_prices', false)
            ->set('data.auto_print', 'store')
            ->call('save')
            ->assertHasNoErrors();

        $r = BrandingSettings::receipt();
        $this->assertSame('أسرع توصيل في نالوت', $r['header']);
        $this->assertFalse($r['store']['show_prices']);
        $this->assertTrue($r['customer']['show_prices']);
        $this->assertSame('store', $r['auto_print']);
    }
}
