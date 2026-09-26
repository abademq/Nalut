<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AppSection;
use App\Models\Campaign;
use App\Models\DeliveryZone;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReadyCart;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EngagementFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;
    private User $customer;
    private $address;
    private Product $burger;
    private Product $fries;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->owner = User::create(['name' => 'صاحب', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true,
            'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817, 'commission_percent' => 10]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true,
            'base_fee' => 5, 'fee_per_km' => 0]);
        $this->burger = $this->store->products()->create(['name' => 'برجر', 'price' => 20, 'is_available' => true]);
        $this->fries = $this->store->products()->create(['name' => 'بطاطا', 'price' => 10, 'is_available' => true,
            'track_stock' => true, 'stock_quantity' => 5]);

        $this->customer = User::create(['name' => 'علي', 'phone' => '0913000001', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->address = $this->customer->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);
    }

    private function place(array $items, array $extra = []): Order
    {
        Sanctum::actingAs($this->customer);
        $id = $this->postJson('/api/v1/orders', $extra + ['store_id' => $this->store->id, 'address_id' => $this->address->id,
            'items' => $items])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    // ===== المفضلة =====

    public function test_favorites_toggle_and_list_and_flags(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/favorites/toggle', ['type' => 'store', 'id' => $this->store->id])->assertJsonPath('favorited', true);
        $this->postJson('/api/v1/favorites/toggle', ['type' => 'product', 'id' => $this->burger->id])->assertJsonPath('favorited', true);

        $this->getJson('/api/v1/favorites')->assertOk()
            ->assertJsonPath('stores.0.id', $this->store->id)
            ->assertJsonPath('products.0.product.name', 'برجر');

        $this->getJson("/api/v1/stores/{$this->store->id}")->assertJsonPath('data.is_favorite', true)
            ->assertJsonPath('data.products.0.is_favorite', true);

        $this->postJson('/api/v1/favorites/toggle', ['type' => 'store', 'id' => $this->store->id])->assertJsonPath('favorited', false);
        $this->getJson('/api/v1/favorites')->assertJsonCount(0, 'stores');
    }

    // ===== إعادة الطلب والسلات الجاهزة =====

    public function test_reorder_returns_current_prices_and_missing_items(): void
    {
        $o = $this->place([['product_id' => $this->burger->id, 'quantity' => 2, 'note' => 'بدون بصل'],
            ['product_id' => $this->fries->id, 'quantity' => 1]]);
        $this->burger->update(['price' => 25]);
        $this->fries->update(['is_available' => false]);

        $this->postJson("/api/v1/orders/{$o->id}/reorder")->assertOk()
            ->assertJsonPath('store.id', $this->store->id)
            ->assertJsonPath('lines.0.product.price', 25)
            ->assertJsonPath('lines.0.quantity', 2)
            ->assertJsonPath('lines.0.note', 'بدون بصل')
            ->assertJsonPath('missing.0', 'بطاطا');
    }

    public function test_ready_cart_listed_in_store_and_loads_into_cart(): void
    {
        $cart = ReadyCart::create(['store_id' => $this->store->id, 'name' => 'وجبة عائلية',
            'items' => [['product_id' => $this->burger->id, 'quantity' => 3], ['product_id' => $this->fries->id, 'quantity' => 2]]]);

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk()
            ->assertJsonPath('ready_carts.0.name', 'وجبة عائلية')
            ->assertJsonPath('ready_carts.0.price', 80);

        $this->getJson("/api/v1/ready-carts/{$cart->id}")->assertOk()->assertJsonCount(2, 'lines');
    }

    // ===== الأقسام وشريط العروض =====

    public function test_sections_filter_stores_and_announcements(): void
    {
        $food = AppSection::create(['name' => 'مطاعم', 'emoji' => '🍔']);
        $shops = AppSection::create(['name' => 'متاجر']);
        $restType = StoreType::create(['name' => 'مطعم', 'app_section_id' => $food->id, 'is_active' => true]);
        $this->store->update(['store_type_id' => $restType->id]);
        $other = Store::create(['user_id' => $this->owner->id, 'name' => 'بقالة', 'slug' => 'b', 'is_open' => true, 'is_active' => true,
            'store_type_id' => StoreType::create(['name' => 'بقالة', 'app_section_id' => $shops->id, 'is_active' => true])->id]);

        Announcement::create(['text' => 'توصيل مجاني اليوم']);
        Announcement::create(['text' => 'خصم البرجر', 'store_id' => $this->store->id]);
        Announcement::create(['text' => 'منتهي', 'ends_at' => now()->subHour()]);

        $content = $this->getJson('/api/v1/app/content')->assertOk();
        $this->assertSame(['مطاعم', 'متاجر'], array_column($content->json('sections'), 'name'));
        $this->assertSame([$restType->id], $content->json('sections.0.type_ids'));
        $this->assertSame(['توصيل مجاني اليوم'], array_column($content->json('announcements'), 'text'));

        Sanctum::actingAs($this->customer);
        $this->assertSame([$this->store->id], array_column($this->getJson("/api/v1/stores?section={$food->id}")->json('data'), 'id'));
        $this->assertSame([$other->id], array_column($this->getJson("/api/v1/stores?section={$shops->id}")->json('data'), 'id'));
        $this->getJson("/api/v1/stores/{$this->store->id}")->assertJsonPath('announcements.0.text', 'خصم البرجر');
    }

    // ===== النقاط =====

    private function enablePoints(string $redeem, string $earn = 'per_amount', float $rate = 1): void
    {
        Setting::put('opt.points.enabled', '1');
        Setting::put('opt.points.earn_mode', $earn);
        Setting::put('opt.points.earn_rate', (string) $rate);
        Setting::put('opt.points.redeem_mode', $redeem);
        Setting::put('opt.points.point_value', '0.1');
        Setting::put('opt.points.min_redeem', '10');
    }

    public function test_points_earned_on_delivery_per_amount_and_per_order(): void
    {
        $this->enablePoints('wallet');
        $o = $this->place([['product_id' => $this->burger->id, 'quantity' => 2]]);   // 40 د.ل
        app(OrderService::class)->transition($o, OrderStatus::Delivered, null, ['force' => true]);
        $this->assertSame(40, $this->customer->fresh()->points_balance);

        // مرة وحدة بس
        app(\App\Services\PointsService::class)->award($o->fresh());
        $this->assertSame(40, $this->customer->fresh()->points_balance);

        Setting::put('opt.points.earn_mode', 'per_order');
        Setting::put('opt.points.earn_rate', '15');
        $o2 = $this->place([['product_id' => $this->burger->id, 'quantity' => 1]]);
        app(OrderService::class)->transition($o2, OrderStatus::Delivered, null, ['force' => true]);
        $this->assertSame(55, $this->customer->fresh()->points_balance);
    }

    public function test_convert_to_wallet_only_when_mode_is_wallet(): void
    {
        $this->enablePoints('wallet');
        app(\App\Services\PointsService::class)->adjust($this->customer, 100, 'هدية', null);

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/points/convert', ['points' => 5])->assertStatus(422);          // أقل من الحد
        $this->postJson('/api/v1/points/convert', ['points' => 60])->assertOk()->assertJsonPath('balance', 40);
        $this->assertEquals(6, app(\App\Services\WalletService::class)->balance($this->customer));

        // الطلب ما يقبلش النقاط لما الوضع «محفظة»
        $o = $this->place([['product_id' => $this->burger->id, 'quantity' => 1]], ['use_points' => true]);
        $this->assertSame(0, $o->points_used);
    }

    public function test_pay_with_points_at_checkout_and_refund_on_cancel(): void
    {
        $this->enablePoints('checkout');
        app(\App\Services\PointsService::class)->adjust($this->customer, 50, null, null); // = 5 د.ل

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/points/convert', ['points' => 20])->assertStatus(422);  // الوضع مش محفظة

        $q = $this->postJson('/api/v1/orders/quote', ['store_id' => $this->store->id, 'address_id' => $this->address->id,
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1]], 'use_points' => true])->assertOk();
        $this->assertEquals(5, $q->json('points_discount'));
        $this->assertEquals(20, $q->json('total')); // 20 + 5 توصيل − 5 نقاط

        $o = $this->place([['product_id' => $this->burger->id, 'quantity' => 1]], ['use_points' => true]);
        $this->assertSame(50, $o->points_used);
        $this->assertEquals(20, $o->total);
        $this->assertSame(0, $this->customer->fresh()->points_balance);

        $this->postJson("/api/v1/orders/{$o->id}/cancel")->assertOk();
        $this->assertSame(50, $this->customer->fresh()->points_balance);
    }

    // ===== أصناف مش متوفرة =====

    private function markUnavailable(Order $o, array $itemIds)
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson("/api/v1/store/orders/{$o->id}/unavailable-items", ['item_ids' => $itemIds]);
    }

    public function test_store_marks_item_unavailable_and_customer_continues(): void
    {
        $o = $this->place([['product_id' => $this->burger->id, 'quantity' => 1], ['product_id' => $this->fries->id, 'quantity' => 2]]);
        $friesItem = $o->items()->where('product_id', $this->fries->id)->first();

        $this->markUnavailable($o, [$friesItem->id])->assertOk()->assertJsonPath('data.awaiting_customer', true);

        $this->assertFalse($this->fries->fresh()->is_available);
        // المتجر ما يقدرش يقبل لين الزبون يرد
        $this->postJson("/api/v1/store/orders/{$o->id}/status", ['status' => 'preparing'])->assertStatus(422);

        Sanctum::actingAs($this->customer);
        $res = $this->postJson("/api/v1/orders/{$o->id}/substitution", ['action' => 'continue'])->assertOk();

        $this->assertFalse($res->json('data.awaiting_customer'));
        $this->assertEquals(20, $res->json('data.subtotal'));
        $this->assertEquals(25, $res->json('data.total'));
        $o->refresh();
        $this->assertEquals(2, $o->commission_amount);
        $this->assertEquals(18, $o->store_earning);
        $this->assertSame(5, $this->fries->fresh()->stock_quantity); // المحجوز رجع
    }

    public function test_customer_edits_order_gets_cart_and_order_cancelled(): void
    {
        $o = $this->place([['product_id' => $this->burger->id, 'quantity' => 2], ['product_id' => $this->fries->id, 'quantity' => 1]]);
        $this->markUnavailable($o, [$o->items()->where('product_id', $this->fries->id)->value('id')])->assertOk();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$o->id}/substitution", ['action' => 'edit'])->assertOk()
            ->assertJsonCount(1, 'cart.lines')
            ->assertJsonPath('cart.lines.0.product.name', 'برجر')
            ->assertJsonPath('cart.lines.0.quantity', 2);

        $this->assertSame(OrderStatus::Cancelled, $o->fresh()->status);
    }

    public function test_timeout_continues_automatically_or_cancels_if_empty(): void
    {
        $o = $this->place([['product_id' => $this->burger->id, 'quantity' => 1], ['product_id' => $this->fries->id, 'quantity' => 1]]);
        $this->markUnavailable($o, [$o->items()->where('product_id', $this->fries->id)->value('id')]);

        $single = $this->place([['product_id' => $this->burger->id, 'quantity' => 1]]);
        $this->markUnavailable($single, [$single->items()->value('id')]);

        $this->travel(11)->minutes();
        $this->artisan('orders:check-stuck')->assertOk();

        $this->assertNull($o->fresh()->awaiting_customer_at);
        $this->assertEquals(20, $o->fresh()->subtotal);
        $this->assertSame(OrderStatus::Cancelled, $single->fresh()->status);
    }

    // ===== حملات الإشعارات =====

    public function test_push_campaign_to_drivers_and_customers(): void
    {
        $this->customer->update(['fcm_token' => 'tok-c']);
        $driver = User::create(['name' => 'س', 'phone' => '0915000001', 'role' => UserRole::Driver->value, 'is_active' => true, 'fcm_token' => 'tok-d']);
        User::create(['name' => 'بدون توكن', 'phone' => '0913000009', 'role' => UserRole::Customer->value, 'is_active' => true]);

        $c = Campaign::create(['title' => 'عرض', 'channel' => 'push', 'target_role' => 'customer', 'audience' => 'all',
            'push_title' => 'أهلاً {name}', 'push_body' => 'خصم 20%', 'push_link' => url('/s/'.$this->store->id), 'status' => 'queued']);
        $d = Campaign::create(['title' => 'سائقين', 'channel' => 'push', 'target_role' => 'driver', 'audience' => 'all',
            'push_title' => 'تنبيه', 'push_body' => 'ساعة الذروة', 'status' => 'queued']);

        $this->assertSame(1, $c->recipients()->count());
        $this->assertSame($driver->id, $d->recipients()->first()['user']->id);

        $this->artisan('campaigns:send-due')->assertOk();

        $this->assertSame('done', $c->fresh()->status);
        $this->assertSame(1, $c->fresh()->sent);
        $this->assertSame(2, MessageLog::where('channel', 'push')->count());
    }

    public function test_admin_pages_render(): void
    {
        $admin = User::create(['name' => 'a', 'phone' => '0910000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web');
        AppSection::create(['name' => 'مطاعم']);
        Announcement::create(['text' => 'x']);
        ReadyCart::create(['store_id' => $this->store->id, 'name' => 'y', 'items' => [['product_id' => $this->burger->id, 'quantity' => 1]]]);

        foreach ([
            \App\Filament\Resources\Engagement\AppSectionResource::class,
            \App\Filament\Resources\Engagement\AnnouncementResource::class,
            \App\Filament\Resources\Engagement\ReadyCartResource::class,
            \App\Filament\Resources\Messaging\CampaignResource::class,
        ] as $r) {
            $this->get($r::getUrl())->assertOk();
        }

        \Livewire\Livewire::test(\App\Filament\Resources\Messaging\CampaignResource\ManageCampaigns::class)
            ->callAction('create', ['title' => 'إشعار', 'channel' => 'push', 'target_role' => 'store',
                'push_title' => 'مرحبا', 'push_body' => 'نص'])
            ->assertHasNoActionErrors();
        $this->assertSame('push', Campaign::latest('id')->first()->channel);
    }
}
