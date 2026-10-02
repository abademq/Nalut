<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Merchant\Auth\Login;
use App\Filament\Merchant\Pages\Dashboard;
use App\Filament\Merchant\Pages\StoreProfile;
use App\Filament\Merchant\Resources\MenuSections\Pages\ManageMenuSections;
use App\Filament\Merchant\Resources\Orders\OrderResource;
use App\Filament\Merchant\Resources\Orders\Pages\ListOrders;
use App\Filament\Merchant\Resources\Orders\Pages\ViewOrder;
use App\Filament\Merchant\Resources\Products\Pages\CreateProduct;
use App\Filament\Merchant\Resources\Products\Pages\EditProduct;
use App\Filament\Merchant\Resources\Products\Pages\ListProducts;
use App\Models\DeliveryZone;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MerchantPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Store $other;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->owner = User::create(['name' => 'صاحب', 'phone' => '0911111111', 'password' => 'secret123', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعمي', 'slug' => 'mine', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817, 'commission_percent' => 10]);
        $otherOwner = User::create(['name' => 'ثاني', 'phone' => '0912222222', 'password' => 'secret123', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->other = Store::create(['user_id' => $otherOwner->id, 'name' => 'متجر غيري', 'slug' => 'other', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
        Filament::setCurrentPanel('merchant');
    }

    private function login(): void
    {
        $this->actingAs($this->owner, 'web');
    }

    private function order(Store $store): Order
    {
        $p = $store->products()->create(['name' => 'برجر', 'price' => 20, 'is_available' => true]);
        $c = User::create(['name' => 'زبون', 'phone' => '0913'.random_int(100000, 999999), 'role' => UserRole::Customer->value, 'is_active' => true]);
        $a = $c->addresses()->create(['label' => 'x', 'details' => 'حي', 'lat' => 31.87, 'lng' => 10.98]);

        return app(OrderService::class)->create($c, ['store_id' => $store->id, 'address_id' => $a->id,
            'items' => [['product_id' => $p->id, 'quantity' => 1]]]);
    }

    public function test_store_owner_logs_in_with_phone_and_password(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['phone' => '0911111111', 'password' => 'secret123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_customer_and_disabled_panel_cannot_log_in(): void
    {
        User::create(['name' => 'ز', 'phone' => '0915555555', 'password' => 'secret123', 'role' => UserRole::Customer->value, 'is_active' => true]);
        Livewire::test(Login::class)
            ->fillForm(['phone' => '0915555555', 'password' => 'secret123'])
            ->call('authenticate')
            ->assertHasFormErrors(['phone']);
        $this->assertGuest();

        Setting::put('opt.merchant.web_enabled', '0');
        $this->assertFalse($this->owner->canAccessPanel(Filament::getPanel('merchant')));
    }

    public function test_store_owner_cannot_open_admin_panel(): void
    {
        $this->login();
        $this->get('/admin')->assertForbidden();
        $this->get('/merchant')->assertOk()->assertSee('مطعمي');
        $this->get('/admin-api/alerts')->assertForbidden();
    }

    public function test_products_are_scoped_to_own_store(): void
    {
        $mine = $this->store->products()->create(['name' => 'صنفي', 'price' => 5, 'is_available' => true]);
        $theirs = $this->other->products()->create(['name' => 'صنف غيري', 'price' => 5, 'is_available' => true]);
        $this->login();

        Livewire::test(ListProducts::class)->assertOk()
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

        foreach (['hidden', 'low_stock'] as $f) {
            Livewire::test(ListProducts::class)->filterTable($f)->assertOk();
        }
        Livewire::test(ListProducts::class)->filterTable('menu_section_id', 0)->assertOk();

        $this->get("/merchant/products/{$theirs->id}/edit")->assertNotFound();
        $this->get("/merchant/products/{$mine->id}/edit")->assertOk();
    }

    public function test_create_and_edit_product_always_in_own_store(): void
    {
        $theirSection = MenuSection::create(['store_id' => $this->other->id, 'name' => 'قسم غيري']);
        $mySection = MenuSection::create(['store_id' => $this->store->id, 'name' => 'مشروبات']);
        $this->login();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'عصير', 'price' => 4, 'menu_section_id' => $mySection->id, 'is_visible' => true, 'is_available' => true])
            ->call('create')->assertHasNoFormErrors();
        $p = Product::firstWhere('name', 'عصير');
        $this->assertSame($this->store->id, $p->store_id);
        $this->assertSame($mySection->id, $p->menu_section_id);

        // قسم متجر ثاني ما ينحطش
        Livewire::test(EditProduct::class, ['record' => $p->id])
            ->fillForm(['menu_section_id' => $theirSection->id])
            ->call('save');
        $this->assertNotSame($theirSection->id, $p->fresh()->menu_section_id);
    }

    public function test_sold_out_product_cannot_be_reopened_without_stock(): void
    {
        $p = $this->store->products()->create(['name' => 'كعكة', 'price' => 5, 'is_available' => true, 'track_stock' => true, 'stock_quantity' => 0]);
        $this->assertFalse($p->fresh()->is_available);
        $this->login();

        // زر «متوفر» مقفول وهو خالص — حتى لو انبعت ما يتفتحش
        Livewire::test(EditProduct::class, ['record' => $p->id])
            ->assertFormFieldIsDisabled('is_available')
            ->fillForm(['is_available' => true])
            ->call('save');
        $this->assertFalse($p->fresh()->is_available);

        Livewire::test(EditProduct::class, ['record' => $p->id])
            ->fillForm(['stock_quantity' => 5])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(5, $p->fresh()->stock_quantity);
        $this->assertTrue($p->fresh()->is_available);
    }

    public function test_menu_sections_scoped_and_created_in_own_store(): void
    {
        MenuSection::create(['store_id' => $this->other->id, 'name' => 'قسم غيري']);
        $this->login();

        Livewire::test(ManageMenuSections::class)->assertOk()->assertDontSee('قسم غيري')
            ->callAction('create', ['name' => 'حلويات'])->assertHasNoActionErrors();
        $this->assertSame($this->store->id, MenuSection::firstWhere('name', 'حلويات')->store_id);

        // إيقاف القسم لين ساعة معيّنة من اللوحة
        $sec = MenuSection::firstWhere('name', 'حلويات');
        Livewire::test(ManageMenuSections::class)
            ->callTableAction('pauseUntil', $sec, ['until' => '16:00'])->assertHasNoTableActionErrors();
        $this->assertFalse($sec->fresh()->isOrderable());
        Livewire::test(ManageMenuSections::class)->assertSee('يتوفر الساعة 16:00');
    }

    public function test_orders_scoped_and_status_actions_work(): void
    {
        $mine = $this->order($this->store);
        $theirs = $this->order($this->other);
        $this->login();

        Livewire::test(ListOrders::class)->assertOk()
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
        $this->get("/merchant/orders/{$theirs->id}")->assertNotFound();

        Livewire::test(ViewOrder::class, ['record' => $mine->id])
            ->callAction('accept', ['prep_time_minutes' => 15]);
        $this->assertSame(OrderStatus::Preparing, $mine->fresh()->status);
        $this->assertSame(15, $mine->fresh()->prep_time_minutes);

        Livewire::test(ViewOrder::class, ['record' => $mine->id])->callAction('ready');
        $this->assertSame(OrderStatus::Ready, $mine->fresh()->status);

        $again = $this->order($this->store);
        Livewire::test(ViewOrder::class, ['record' => $again->id])
            ->callAction('reject', ['reason' => 'المطبخ مسكّر']);
        $this->assertSame(OrderStatus::Cancelled, $again->fresh()->status);
        $this->assertSame('المطبخ مسكّر', $again->fresh()->cancel_reason);
    }

    public function test_money_follows_store_visibility_settings(): void
    {
        $o = $this->order($this->store);
        $this->login();
        Setting::put('opt.show.store.commission', '0');

        $html = OrderResource::moneyHtml($o);
        $this->assertStringNotContainsString('عمولة', $html);
        $this->assertStringContainsString('صافي المتجر', $html);
    }

    public function test_orders_can_be_turned_off_and_pending_endpoint(): void
    {
        $this->order($this->store);
        $this->order($this->other);
        $this->login();

        $this->getJson('/merchant-api/pending')->assertOk()->assertJsonPath('count', 1);

        Setting::put('opt.merchant.orders', '0');
        $this->assertFalse(OrderResource::canAccess());
        $this->getJson('/merchant-api/pending')->assertForbidden();
    }

    public function test_dashboard_and_store_profile(): void
    {
        $this->login();
        Livewire::test(Dashboard::class)->assertOk()->callAction('toggleOpen');
        $this->assertFalse($this->store->fresh()->is_open);

        Livewire::test(StoreProfile::class)->assertOk()
            ->fillForm(['description' => 'أحلى شاورما', 'prep_time_minutes' => 25])
            ->call('save');
        $this->assertSame('أحلى شاورما', $this->store->fresh()->description);
        $this->assertSame('مطعمي', $this->store->fresh()->name);
    }
}
