<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
    }

    private function burger(): Product
    {
        return $this->store->products()->create(['name' => 'برجر', 'price' => 18, 'is_available' => true,
            'ingredients' => [['name' => 'لحم', 'removable' => false], ['name' => 'بصل', 'removable' => true], ['name' => 'مايونيز', 'removable' => true]]]);
    }

    private function customer(): array
    {
        $c = User::create(['name' => 'ز', 'phone' => '0913000001', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $a = $c->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);
        Sanctum::actingAs($c);

        return [$c, $a];
    }

    public function test_store_app_saves_ingredients_from_json_string(): void
    {
        Sanctum::actingAs($this->owner);
        $res = $this->post('/api/v1/store/products', [
            'name' => 'شاورما', 'price' => 12,
            'ingredients' => json_encode([['name' => ' ثوم ', 'removable' => true], ['name' => 'خبز', 'removable' => false], ['name' => 'ثوم'], '']),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame([['name' => 'ثوم', 'removable' => true], ['name' => 'خبز', 'removable' => false]], $res->json('data.ingredients'));

        $id = $res->json('data.id');
        $this->post("/api/v1/store/products/$id", ['ingredients' => json_encode(['مخلل'])], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.ingredients', [['name' => 'مخلل', 'removable' => true]]);
        // تعديل بدون المكوّنات ما يمسحهمش
        $this->post("/api/v1/store/products/$id", ['price' => 13], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.ingredients.0.name', 'مخلل');
    }

    public function test_customer_orders_without_ingredients_and_it_shows_everywhere(): void
    {
        $p = $this->burger();
        [, $a] = $this->customer();

        $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk();

        $res = $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $a->id,
            'items' => [['product_id' => $p->id, 'quantity' => 2, 'remove' => ['بصل', 'مايونيز']]]])->assertCreated();

        $this->assertSame('بدون: بصل، مايونيز', $res->json('data.items.0.options_text'));
        $this->assertEquals(36, $res->json('data.items.0.line_total'));
        $this->assertTrue($res->json('data.items.0.options.0.removed'));

        // إعادة الطلب ترجع «بدون» زي ما هي
        $order = Order::first();
        $this->postJson("/api/v1/orders/{$order->id}/reorder")->assertOk()
            ->assertJsonPath('lines.0.remove', ['بصل', 'مايونيز']);
    }

    public function test_essential_ingredient_cannot_be_removed(): void
    {
        $p = $this->burger();
        [, $a] = $this->customer();

        $this->postJson('/api/v1/orders', ['store_id' => $this->store->id, 'address_id' => $a->id,
            'items' => [['product_id' => $p->id, 'quantity' => 1, 'remove' => ['لحم']]]])
            ->assertStatus(422)->assertJsonPath('errors.items.0', '«لحم» ما ينشالش من «برجر» — المتجر غيّر المكوّنات، راجع الصنف وعاود.');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_saved_cart_keeps_removals_and_drops_stale_ones(): void
    {
        $p = $this->burger();
        $this->customer();

        $id = $this->postJson('/api/v1/saved-carts', ['name' => 'غدا', 'store_id' => $this->store->id,
            'items' => [['product_id' => $p->id, 'quantity' => 1, 'remove' => ['بصل', 'مايونيز']]]])->assertCreated()->json('data.id');

        $p->update(['ingredients' => [['name' => 'بصل', 'removable' => true]]]);
        $this->getJson("/api/v1/saved-carts/$id")->assertOk()->assertJsonPath('lines.0.remove', ['بصل']);
    }

    public function test_admin_edits_ingredients(): void
    {
        $p = $this->burger();
        $this->actingAs(User::create(['name' => 'a', 'phone' => '0900000000', 'role' => UserRole::Admin->value, 'is_active' => true]));

        Livewire::test(EditProduct::class, ['record' => $p->id])
            ->assertOk()
            ->fillForm(['ingredients' => [['name' => 'جبنة', 'removable' => true], ['name' => 'خبز', 'removable' => false]]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(['جبنة'], $p->fresh()->removableIngredients());
    }
}
