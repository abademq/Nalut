<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
    }

    private function admin(?array $permissions, string $phone = '0910000009'): User
    {
        return User::create(['name' => 'Admin', 'phone' => $phone, 'email' => $phone.'@a.ly', 'password' => bcrypt('x'),
            'role' => UserRole::Admin->value, 'is_active' => true, 'permissions' => $permissions]);
    }

    // ===== صلاحيات لوحة التحكم =====

    public function test_limited_admin_cannot_open_unrelated_resources(): void
    {
        $this->actingAs($this->admin(['users.view', 'users.manage']));

        $this->get('/admin/users')->assertOk();
        foreach (['/admin/orders', '/admin/wallet-transactions', '/admin/stores', '/admin/products',
            '/admin/recharge-cards', '/admin/delivery-zones', '/admin/app-settings', '/admin/banners'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_drivers_map_requires_orders_or_users_view(): void
    {
        $this->actingAs($this->admin(['cards.manage']));
        $this->getJson('/admin-api/drivers-map')->assertForbidden();
        $this->get('/admin/drivers-map')->assertForbidden();
    }

    public function test_accountant_sees_finance_but_not_users_management(): void
    {
        $this->actingAs($this->admin(['finance.view', 'finance.manage', 'orders.view']));

        $this->get('/admin/wallet-transactions')->assertOk();
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/orders/create')->assertForbidden(); // عرض فقط
    }

    public function test_users_manager_cannot_create_or_edit_admins(): void
    {
        $limited = $this->admin(['users.view', 'users.manage']);
        $super = $this->admin(null, '0910000008');
        $this->actingAs($limited);

        $this->assertFalse(UserResource::canEdit($super));
        $this->assertTrue(UserResource::canEdit($this->customer));
        $this->get("/admin/users/{$super->id}/edit")->assertForbidden();

        // دور «إدارة» ما يظهرش في الفورم
        $html = $this->get('/admin/users/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('value="admin"', $html);
    }

    public function test_super_admin_has_everything(): void
    {
        $this->actingAs($this->admin(null));
        foreach (['/admin/orders', '/admin/wallet-transactions', '/admin/users', '/admin/app-settings'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    // ===== الدفع الإلكتروني =====

    private function cardOrder(bool $paid): Order
    {
        return Order::create([
            'code' => 'C'.random_int(1, 9999), 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'status' => OrderStatus::Pending, 'payment_method' => 'card', 'is_paid' => $paid,
            'address_details' => 'x', 'address_lat' => 31.87, 'address_lng' => 10.98, 'customer_phone' => '1', 'total' => 30,
        ]);
    }

    public function test_unpaid_card_order_cannot_be_accepted_or_delivered(): void
    {
        $order = $this->cardOrder(false);

        try {
            app(OrderService::class)->transition($order, OrderStatus::Preparing, $this->store->owner, ['prep_time_minutes' => 10]);
            $this->fail('should block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('بانتظار تأكيد الدفع', $e->errors()['status'][0]);
        }

        // حتى الإدارة بالقوة
        $this->expectException(ValidationException::class);
        app(OrderService::class)->transition($order->fresh(), OrderStatus::Delivered, null, ['force' => true]);
    }

    public function test_unpaid_card_orders_hidden_from_store(): void
    {
        $this->cardOrder(false);
        $paid = $this->cardOrder(true);

        Sanctum::actingAs($this->store->owner);
        $ids = collect($this->getJson('/api/v1/store/orders?status=active')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$paid->id], $ids);
        $this->assertSame(1, $this->getJson('/api/v1/store/summary')->json('today.pending'));
    }

    public function test_paid_card_order_can_be_accepted(): void
    {
        $order = app(OrderService::class)->transition($this->cardOrder(true), OrderStatus::Preparing, $this->store->owner, ['prep_time_minutes' => 10]);
        $this->assertSame(OrderStatus::Preparing, $order->status);
    }

    // ===== تكرار السطر لتجاوز المخزون =====

    public function test_duplicate_lines_cannot_bypass_stock_or_max(): void
    {
        \App\Models\DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
        $p = $this->store->products()->create(['name' => 'برجر', 'price' => 10, 'is_available' => true,
            'track_stock' => true, 'stock_quantity' => 3, 'max_per_order' => 5]);
        $a = $this->customer->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $a->id, 'items' => [
            ['product_id' => $p->id, 'quantity' => 2], ['product_id' => $p->id, 'quantity' => 2],
        ]])->assertStatus(422);

        $p->update(['track_stock' => false]);
        $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $a->id, 'items' => [
            ['product_id' => $p->id, 'quantity' => 3], ['product_id' => $p->id, 'quantity' => 3],
        ]])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    // ===== Seeder =====

    public function test_seeder_refuses_production_and_has_no_fixed_password(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('db:seed', ['--force' => true]);
        $this->assertDatabaseMissing('users', ['phone' => '0910000000']);

        $this->app['env'] = 'testing';
        \Illuminate\Support\Facades\DB::table('stores')->delete();
        \Illuminate\Support\Facades\DB::table('users')->delete();
        $this->artisan('db:seed');
        $admin = User::where('phone', '0910000000')->first();
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password', $admin->password));
    }
}
