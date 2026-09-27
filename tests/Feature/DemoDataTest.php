<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_usable_and_removable(): void
    {
        Storage::fake('public');
        $this->artisan('demo:seed --force')->assertSuccessful();
        $this->artisan('demo:seed --force')->assertSuccessful(); // مرتين = نفس النتيجة
        $this->assertSame(8, Store::where('slug', 'like', 'demo-%')->count());

        $c = User::where('phone', '0910000301')->first();
        Sanctum::actingAs($c);
        $store = Store::where('name', 'مطعم الجبل (تجريبي)')->first();
        $p = Product::where('store_id', $store->id)->where('name', 'شاورما دجاج')->with('options.values')->first();
        $big = $p->options->firstWhere('name', 'الحجم')->values->firstWhere('name', 'كبير');
        $kebab = $p->options->firstWhere('name', 'الإضافات')->values->firstWhere('name', 'سيخ كباب إضافي');

        $this->getJson('/api/v1/stores/'.$store->id)->assertOk();
        $order = $this->postJson('/api/v1/orders', ['store_id' => $store->id, 'address_id' => $c->addresses()->first()->id, 'coupon_code' => 'DEMO10',
            'items' => [['product_id' => $p->id, 'quantity' => 1, 'options' => [['id' => $big->id], ['id' => $kebab->id, 'qty' => 3]]]]])
            ->assertCreated()->json('data');
        $this->assertSame(31.0, (float) $order['subtotal']); // 12 + 4 + 15

        // القائمة الجاهزة والكوبون
        $this->assertTrue(Coupon::where('code', 'DEMOOLD')->exists());

        $this->artisan('demo:seed --remove --force')->assertSuccessful();
        $this->assertSame(0, Store::withTrashed()->where('slug', 'like', 'demo-%')->count());
        $this->assertSame(0, User::withTrashed()->where('email', 'like', '%@demo.test')->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Coupon::where('code', 'like', 'DEMO%')->count());
    }
}
