<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Auth\Login;
use App\Filament\Pages\ServerStatus;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Support\AppCheck;
use App\Support\Recaptcha;
use App\Support\ServerMetrics;
use App\Support\Traffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class AddonsServerSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    private int $sauce;

    private int $kebab;

    private int $large;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
        $this->product = $this->store->products()->create(['name' => 'شاورما', 'price' => 10, 'is_available' => true]);

        $add = $this->product->options()->create(['name' => 'الإضافات', 'type' => 'multi', 'max_choices' => 5]);
        $this->sauce = $add->values()->create(['name' => 'زيادة صوص', 'extra_price' => 0, 'max_qty' => 1])->id;
        $this->kebab = $add->values()->create(['name' => 'سيخ كباب', 'extra_price' => 4, 'max_qty' => 3])->id;
        $size = $this->product->options()->create(['name' => 'الحجم', 'type' => 'single', 'is_required' => true, 'max_choices' => 1]);
        $size->values()->create(['name' => 'عادي', 'extra_price' => 0]);
        $this->large = $size->values()->create(['name' => 'كبير', 'extra_price' => 3])->id;
    }

    private function customer(): array
    {
        $c = User::create(['name' => 'ز', 'phone' => '0913000001', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $a = $c->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);
        Sanctum::actingAs($c);

        return [$c, $a];
    }

    public function test_same_product_twice_with_different_addons_and_notes(): void
    {
        [, $a] = $this->customer();

        $items = [
            // شاورما كبيرة + زيادة صوص + 2 سيخ كباب
            ['product_id' => $this->product->id, 'quantity' => 2, 'note' => 'حار',
                'options' => [['id' => $this->sauce, 'qty' => 1], ['id' => $this->kebab, 'qty' => 2], ['id' => $this->large]]],
            // شاورما ثانية بدون صوص ولا هريسة
            ['product_id' => $this->product->id, 'quantity' => 1, 'note' => 'بدون هريسة ولا صوص',
                'option_value_ids' => [$this->large]],
        ];

        // التسعيرة لازم تحسب الإضافات (كانت تتجاهلها)
        $this->postJson('/api/v1/orders/quote', ['store_id' => $this->store->id, 'address_id' => $a->id, 'items' => $items])
            ->assertOk()->assertJsonPath('subtotal', 2 * (10 + 0 + 8 + 3) + (10 + 3));

        $res = $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $a->id, 'items' => $items])
            ->assertCreated();

        $res->assertJsonPath('data.items.0.line_total', 42)
            ->assertJsonPath('data.items.1.line_total', 13)
            ->assertJsonPath('data.items.1.note', 'بدون هريسة ولا صوص');
        $this->assertSame('الإضافات: زيادة صوص، سيخ كباب ×2 · الحجم: كبير', $res->json('data.items.0.options_text'));
    }

    public function test_reorder_and_saved_cart_keep_addons(): void
    {
        [, $a] = $this->customer();
        $items = [['product_id' => $this->product->id, 'quantity' => 1, 'note' => 'حار',
            'options' => [['id' => $this->kebab, 'qty' => 2], ['id' => $this->large]]]];
        $id = $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $a->id, 'items' => $items])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/orders/$id/reorder")->assertOk()
            ->assertJsonPath('lines.0.options', [['id' => $this->kebab, 'qty' => 2], ['id' => $this->large, 'qty' => 1]])
            ->assertJsonPath('lines.0.note', 'حار');

        $cart = $this->postJson('/api/v1/saved-carts', ['name' => 'غدا', 'store_id' => $this->store->id, 'items' => $items])
            ->assertSuccessful();
        $this->getJson('/api/v1/saved-carts')->assertJsonPath('data.0.total', 21);
        $this->getJson('/api/v1/saved-carts/'.$cart->json('data.id'))
            ->assertJsonPath('lines.0.options.0', ['id' => $this->kebab, 'qty' => 2]);
    }

    public function test_addon_limits(): void
    {
        [, $a] = $this->customer();
        $post = fn (array $opts) => $this->postJson('/api/v1/orders/quote', ['store_id' => $this->store->id, 'address_id' => $a->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'options' => $opts]]]);

        // الصوص مرة وحدة بس
        $post([['id' => $this->sauce, 'qty' => 2], ['id' => $this->large]])->assertStatus(422)
            ->assertJsonPath('errors.items.0', '«زيادة صوص» يتزاد لحد 1 بس.');
        // الحجم إجباري
        $post([['id' => $this->kebab, 'qty' => 1]])->assertStatus(422);
        // الكباب لحد 3
        $post([['id' => $this->kebab, 'qty' => 3], ['id' => $this->large]])->assertOk();

        ProductOptionValue::whereKey($this->kebab)->update(['is_available' => false]);
        $post([['id' => $this->kebab, 'qty' => 1], ['id' => $this->large]])->assertStatus(422);
    }

    public function test_store_app_syncs_addons(): void
    {
        Sanctum::actingAs($this->owner);
        $size = $this->product->options()->where('name', 'الحجم')->first();

        $r = $this->putJson("/api/v1/store/products/{$this->product->id}/options", ['options' => [
            ['name' => 'الإضافات', 'type' => 'multi', 'max_choices' => 4, 'values' => [
                ['name' => 'سيخ كباب', 'extra_price' => 5, 'max_qty' => 4],
                ['name' => 'جبنة', 'extra_price' => 0],
            ]],
            ['id' => $size->id, 'name' => 'الحجم', 'type' => 'single', 'is_required' => true, 'values' => [
                ['name' => 'وسط', 'extra_price' => 0, 'max_qty' => 9],
            ]],
        ]]);
        $r->assertOk()
            ->assertJsonPath('data.options.0.values.0.max_qty', 4)
            ->assertJsonPath('data.options.1.id', $size->id)
            // خيار «وحدة بس» ما يتكررش
            ->assertJsonPath('data.options.1.values.0.max_qty', 1);

        $this->assertSame(2, $this->product->options()->count());
        $this->assertDatabaseMissing('product_option_values', ['id' => $this->sauce]);

        // متجر ثاني ما يقدرش
        $other = User::create(['name' => 'x', 'phone' => '0912222222', 'role' => UserRole::Store->value, 'is_active' => true]);
        Store::create(['user_id' => $other->id, 'name' => 'ب', 'slug' => 'b', 'is_active' => true, 'lat' => 1, 'lng' => 1]);
        Sanctum::actingAs($other);
        $this->putJson("/api/v1/store/products/{$this->product->id}/options", ['options' => []])->assertForbidden();
    }

    public function test_sold_out_product_stays_visible_in_store_page(): void
    {
        $this->customer();
        $this->product->update(['track_stock' => true, 'stock_quantity' => 0, 'is_available' => false, 'sold_out_at' => now()]);

        $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk()
            ->assertJsonPath('data.products.0.id', $this->product->id)
            ->assertJsonPath('data.products.0.sold_out', true)
            ->assertJsonPath('data.products.0.is_available', false);
    }

    public function test_traffic_log_and_server_page(): void
    {
        File::deleteDirectory(Traffic::dir());
        $this->customer();
        $this->getJson('/api/v1/app/content')->assertOk();
        $this->getJson('/api/v1/app/content')->assertOk();

        $t = ServerMetrics::traffic(5);
        $this->assertGreaterThanOrEqual(2, $t['total']);
        $this->assertSame('GET /api/v1/app/content', $t['routes'][0]['route']);

        $admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web')->get(ServerStatus::getUrl())->assertOk()->assertSee('حالة السيرفر')->assertSee('app/content');

        $limited = User::create(['name' => 'م', 'phone' => '0918888888', 'email' => 'b@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true, 'permissions' => ['orders.view']]);
        $this->actingAs($limited, 'web');
        $this->assertFalse(ServerStatus::canAccess());

        File::deleteDirectory(Traffic::dir());
    }

    public function test_verdict_levels(): void
    {
        $traffic = ['total' => 0, 'error_rate' => 0, 'p95_ms' => 0];
        $q = ['oldest_min' => 0, 'failed_last_hour' => 0];
        $this->assertSame('ok', ServerMetrics::verdict([0.2, 0.2, 0.2], 2, 10, ['percent' => 40], ['percent' => 50], $traffic, $q)['level']);
        $this->assertSame('high', ServerMetrics::verdict([4, 3, 3], 2, 95, ['percent' => 40], ['percent' => 50], $traffic, $q)['level']);
        $this->assertSame('warn', ServerMetrics::verdict([0.2, 0.2, 0.2], 2, 10, ['percent' => 85], ['percent' => 50], $traffic, $q)['level']);
    }

    public function test_admin_login_recaptcha(): void
    {
        $admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);

        // بدون مفاتيح: الدخول عادي
        $this->assertFalse(Recaptcha::adminEnabled());
        Livewire::test(Login::class)
            ->set('data.email', 'a@a.ly')->set('data.password', 'secret123')
            ->call('authenticate')->assertHasNoErrors();
        auth()->logout();

        config(['services.recaptcha.site_key' => 'site', 'services.recaptcha.secret_key' => 'secret']);
        $this->assertTrue(Recaptcha::adminEnabled());

        Livewire::test(Login::class)
            ->assertSee('recaptcha')
            ->set('data.email', 'a@a.ly')->set('data.password', 'secret123')
            ->call('authenticate')->assertHasErrors('data.email');
        $this->assertGuest();

        Livewire::test(Login::class)
            ->set('data.email', 'a@a.ly')->set('data.password', 'secret123')->set('captcha', 'tok')
            ->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_app_check_modes(): void
    {
        $send = fn () => $this->postJson('/api/v1/auth/login', ['phone' => '0913000009', 'password' => 'x']);

        // بدون رقم المشروع: معطّل
        $this->assertSame('off', AppCheck::mode());
        $send()->assertStatus(422);

        config(['services.appcheck.project_number' => '123']);
        $this->assertSame('monitor', AppCheck::mode());
        $send()->assertStatus(422); // يعدّ بس
        $this->assertSame(1, AppCheck::stats()['missing']);

        Setting::updateOrCreate(['key' => 'opt.security.app_check'], ['value' => 'enforce']);
        Cache::forget('settings.all');
        $send()->assertStatus(403)->assertJsonPath('app_check', 'missing');
        $this->postJson('/api/v1/auth/login', ['phone' => '0913000009', 'password' => 'x'], ['X-Firebase-AppCheck' => 'bad.token.x'])
            ->assertStatus(403)->assertJsonPath('app_check', 'invalid');

        // تطبيق المتجر برقم متجر حقيقي: معفي — وبرقم زبون: لا
        $this->postJson('/api/v1/auth/login', ['phone' => '0911111111', 'password' => 'x'], ['X-App' => 'store'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['phone' => '0913000009', 'password' => 'x'], ['X-App' => 'store'])->assertStatus(403);
    }
}
