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

    public function test_sold_out_product_opens_only_with_new_stock(): void
    {
        $owner = $this->store->owner ?? User::find($this->store->user_id);
        $this->product->update(['stock_quantity' => 0]);
        $p = $this->product->fresh();
        $this->assertFalse($p->is_available);

        // من الكود أو اللوحة: ما يتفتحش
        $p->update(['is_available' => true]);
        $this->assertFalse($p->fresh()->is_available);

        // من تطبيق المتجر: رسالة واضحة
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/store/products/{$p->id}", ['is_available' => true])
            ->assertStatus(422)->assertJsonPath('errors.is_available.0', Product::OUT_OF_STOCK_MESSAGE);

        // كمية جديدة: يتفتح
        $this->postJson("/api/v1/store/products/{$p->id}", ['stock_quantity' => 5, 'is_available' => true])->assertOk()
            ->assertJsonPath('data.is_available', true);
        $this->assertNull($p->fresh()->sold_out_at);

        // منتج جديد بكمية صفر ومفتوح: مرفوض
        $this->postJson('/api/v1/store/products', ['name' => 'x', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 0, 'is_available' => true])
            ->assertStatus(422);
    }

    public function test_product_states_are_clear(): void
    {
        $p = $this->product->fresh(); // بكمية 2
        $p->update(['low_stock_alert' => 2]);
        $this->assertSame('low', $p->fresh()->state());

        $p->update(['stock_quantity' => 10]);
        $this->assertSame('available', $p->fresh()->state());

        // موقوف بالإيد: يقعد موقوف حتى مع كمية
        $p->update(['is_available' => false]);
        $this->assertSame('stopped', $p->fresh()->state());
        $p->update(['stock_quantity' => 20]);
        $this->assertSame('stopped', $p->fresh()->state());

        // نفد: الكمية صفر — حتى لو كان موقوف قبلها
        $p->update(['stock_quantity' => 0]);
        $this->assertSame('sold_out', $p->fresh()->state());

        // منتج بدون تتبّع: يا متوفر يا موقوف
        $q = $this->store->products()->create(['name' => 'قهوة', 'price' => 3, 'is_available' => true]);
        $this->assertSame('available', $q->state());
        $q->update(['is_available' => false]);
        $this->assertSame('stopped', $q->fresh()->state());
    }

    public function test_customer_sees_available_left_or_unavailable_and_hidden_is_gone(): void
    {
        [$c, $a] = $this->customer('0913000007');
        Sanctum::actingAs($c);
        $get = fn () => collect($this->getJson('/api/v1/stores/'.$this->store->id)->json('data.products'))->firstWhere('id', $this->product->id);

        // المتجر ما حددش تنبيه: «متوفر» بدون عدد
        $this->assertNull($get()['left']);
        $this->product->update(['low_stock_alert' => 3]);
        $this->assertSame(2, $get()['left']); // «متوفر 2 قطع فقط»

        // خلص: غير متوفر (الصور تقعد)
        $this->product->update(['stock_quantity' => 0]);
        $this->assertFalse($get()['is_available']);
        $this->assertNull($get()['left']);
        $this->assertSame('sold_out', $get()['state']);

        // مخفي: ما يبانش ولا يتطلب
        $this->product->update(['stock_quantity' => 5, 'is_visible' => false]);
        $this->assertNull($get());
        $this->order([$c, $a], 1)->assertStatus(422);

        // المتجر يرجّعه من تطبيقه
        Sanctum::actingAs(User::find($this->store->user_id));
        $this->postJson('/api/v1/store/products/'.$this->product->id, ['is_visible' => true])->assertOk()->assertJsonPath('data.is_visible', true);
    }
}
