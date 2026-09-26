<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Support\Sounds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiRoleSoundsCartsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $roles, string $phone = '0911111111'): User
    {
        return User::create(['name' => 'علي', 'phone' => $phone, 'password' => Hash::make('secret1'),
            'roles' => $roles, 'is_active' => true]);
    }

    public function test_one_phone_many_roles(): void
    {
        $u = $this->user(['customer', 'driver']);

        $this->assertTrue($u->hasRole(UserRole::Customer));
        $this->assertTrue($u->hasRole('driver'));
        $this->assertFalse($u->hasRole('store'));
        $this->assertSame('driver', $u->role->value);            // الأساسي = الأهم
        $this->assertSame(1, User::withRole('customer')->count());
        $this->assertSame(1, User::withRole('driver')->count());
    }

    public function test_driver_app_login_with_multi_role_account(): void
    {
        $this->user(['customer', 'driver']);

        $res = $this->withHeader('X-App', 'driver')
            ->postJson('/api/v1/auth/login', ['phone' => '0911111111', 'password' => 'secret1', 'fcm_token' => 'drv-token'])
            ->assertOk();

        $this->assertSame('driver', $res->json('user.role'));
        $this->assertEqualsCanonicalizing(['customer', 'driver'], $res->json('user.roles'));

        $u = User::first();
        $this->assertSame('drv-token', $u->pushTokenFor('driver'));
        $this->assertNull($u->pushTokenFor('customer'));
        $this->assertNotNull($u->driverProfile);

        // نفس الحساب من تطبيق الزبون — توكن منفصل
        $this->withHeader('X-App', 'customer')
            ->postJson('/api/v1/auth/login', ['phone' => '0911111111', 'password' => 'secret1', 'fcm_token' => 'cus-token'])
            ->assertOk()->assertJsonPath('user.role', 'customer');

        $u->refresh();
        $this->assertSame('cus-token', $u->pushTokenFor('customer'));
        $this->assertSame('drv-token', $u->pushTokenFor('driver'));
    }

    public function test_customer_app_adds_customer_role_but_driver_app_needs_admin(): void
    {
        $this->user(['driver'], '0912222222');
        $this->user(['customer'], '0913333333');

        $this->withHeader('X-App', 'customer')
            ->postJson('/api/v1/auth/login', ['phone' => '0912222222', 'password' => 'secret1'])
            ->assertOk()->assertJsonPath('user.role', 'customer');
        $this->assertTrue(User::where('phone', '0912222222')->first()->hasRole('customer'));

        $this->withHeader('X-App', 'driver')
            ->postJson('/api/v1/auth/login', ['phone' => '0913333333', 'password' => 'secret1'])
            ->assertStatus(422);
    }

    public function test_route_role_middleware_accepts_extra_roles(): void
    {
        $u = $this->user(['customer', 'store']);
        Store::create(['user_id' => $u->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);

        $this->actingAs($u, 'sanctum')->getJson('/api/v1/store/orders')->assertOk();
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/favorites')->assertOk();
    }

    public function test_sounds_from_admin_settings(): void
    {
        $this->assertSame(['channel_id' => 'orders'], Sounds::android('customer'));

        Setting::put('sound.customer', 'chime');
        $this->assertSame(['channel_id' => 'orders_chime', 'sound' => 'tone_chime'], Sounds::android('customer'));

        $this->getJson('/api/v1/app/content?app=customer')
            ->assertOk()->assertJsonPath('sound.tone', 'chime');
        $this->assertStringContainsString('sounds/tone_chime.wav', (string) Sounds::url('customer'));
    }

    public function test_customer_saved_carts(): void
    {
        $owner = $this->user(['store'], '0914444444');
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm2', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);
        $a = Product::create(['store_id' => $store->id, 'name' => 'بيتزا', 'price' => 20, 'is_available' => true]);
        $b = Product::create(['store_id' => $store->id, 'name' => 'عصير', 'price' => 5, 'is_available' => true]);
        $c = $this->user(['customer'], '0915555555');

        $id = $this->actingAs($c, 'sanctum')->postJson('/api/v1/saved-carts', [
            'name' => 'عشاء الجمعة', 'store_id' => $store->id,
            'items' => [['product_id' => $a->id, 'quantity' => 2], ['product_id' => $b->id, 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('data.total', 55)->json('data.id');

        $b->update(['is_available' => false]);

        $this->getJson('/api/v1/saved-carts')->assertOk()
            ->assertJsonPath('data.0.missing', 1)->assertJsonPath('data.0.total', 40);

        $this->getJson("/api/v1/saved-carts/$id")->assertOk()
            ->assertJsonCount(1, 'lines')->assertJsonPath('missing.0', 'عصير');

        // سلة زبون ثاني ما تنفتحش
        $other = $this->user(['customer'], '0916666666');
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/saved-carts/$id")->assertNotFound();

        Setting::put('opt.carts.saved_max', '1');
        $this->actingAs($c, 'sanctum')->postJson('/api/v1/saved-carts', [
            'name' => 'ثانية', 'store_id' => $store->id, 'items' => [['product_id' => $a->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->deleteJson("/api/v1/saved-carts/$id")->assertOk();
        $this->assertDatabaseCount('saved_carts', 0);
    }

    public function test_admin_pages_render(): void
    {
        $admin = User::create(['name' => 'Admin', 'phone' => '0910000001', 'email' => 'a@a.ly', 'password' => 'x',
            'roles' => ['admin', 'customer'], 'is_active' => true]);
        $multi = $this->user(['customer', 'driver'], '0917777777');

        $this->actingAs($admin);
        $this->get('/admin/notification-sounds')->assertOk()->assertSee('أصوات الإشعارات');
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertOk()->assertSee('الأدوار');
        $this->get("/admin/users/{$multi->id}/edit")->assertOk();
        $this->get('/admin/drivers')->assertOk();
    }

    public function test_admin_sets_multiple_roles(): void
    {
        $admin = User::create(['name' => 'Admin', 'phone' => '0910000001', 'email' => 'a@a.ly', 'password' => 'x',
            'roles' => ['admin'], 'is_active' => true]);
        $u = $this->user(['customer'], '0918888888');
        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $u->id])
            ->fillForm(['roles' => ['customer', 'driver']])
            ->call('save')
            ->assertHasNoFormErrors();

        $u->refresh();
        $this->assertEqualsCanonicalizing(['customer', 'driver'], $u->roleValues());
        $this->assertNotNull($u->driverProfile);
        $this->assertFalse($u->driverProfile->is_approved);
    }
}
