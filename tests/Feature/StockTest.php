<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
        $this->product = $this->store->products()->create(['name' => 'كعكة', 'price' => 10, 'is_available' => true, 'track_stock' => true, 'stock_quantity' => 2]);
    }

    private function customer(string $phone)
    {
        $c = User::create(['name' => 'ز', 'phone' => $phone, 'role' => UserRole::Customer->value, 'is_active' => true]);
        $a = $c->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);

        return [$c, $a];
    }

    private function order(array $who, int $qty)
    {
        Sanctum::actingAs($who[0]);

        return $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $who[1]->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty]]]);
    }

    public function test_more_than_stock_shows_clear_message(): void
    {
        $this->order($this->customer('0913000001'), 3)
            ->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'المتوفر من «كعكة» توّا 2 فقط.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quote_also_reports_stock_problem(): void
    {
        [$c, $a] = $this->customer('0913000001');
        Sanctum::actingAs($c);
        $this->postJson('/api/v1/orders/quote', ['store_id' => $this->store->id, 'address_id' => $a->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]]])->assertStatus(422);
    }

    public function test_last_item_goes_to_first_customer_and_second_gets_clear_message(): void
    {
        $a = $this->customer('0913000001');
        $b = $this->customer('0913000002');

        $this->order($a, 2)->assertCreated();

        // الثاني كان عنده نفس المنتج في السلة
        $this->order($b, 1)
            ->assertStatus(422)
            ->assertJsonPath('errors.items.0', '«كعكة» مش متوفر توّا. شيله من السلة.');

        $p = $this->product->fresh();
        $this->assertSame(0, $p->stock_quantity);
        $this->assertFalse($p->is_available);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_atomic_reservation_blocks_oversell_even_if_check_passed(): void
    {
        // نحاكي السباق: الفحص الأول عدّى، لكن المخزون نقص قبل الحجز
        $reserve = new \ReflectionMethod(OrderService::class, 'reserveStock');
        $line = [['product_id' => $this->product->id, 'quantity' => 2, 'name' => 'كعكة']];

        $this->product->update(['stock_quantity' => 1]);

        try {
            $reserve->invoke(app(OrderService::class), $line);
            $this->fail('should throw');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('حد طلبه قبلك', $e->errors()['items'][0]);
        }

        $this->assertSame(1, $this->product->fresh()->stock_quantity);
    }

    public function test_product_api_exposes_stock_for_apps(): void
    {
        Sanctum::actingAs($this->store->owner);
        $this->getJson('/api/v1/store/products')->assertOk()
            ->assertJsonPath('data.0.track_stock', true)
            ->assertJsonPath('data.0.stock_quantity', 2);
    }
}
