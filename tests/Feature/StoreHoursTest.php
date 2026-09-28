<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeliveryZone;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreHoursTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->owner = User::create(['name' => 'م', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true,
            'lat' => 31.8686, 'lng' => 10.9817, 'opens_at' => '10:00', 'closes_at' => '22:00']);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $local): void
    {
        Carbon::setTestNow(Carbon::parse("2026-10-01 $local", 'Africa/Tripoli')->utc());
    }

    public function test_early_manual_open_then_hours_take_over(): void
    {
        $this->at('08:00');
        $this->assertFalse($this->store->isAcceptingOrders());
        $this->assertStringContainsString('يفتح الساعة 10:00', $this->store->statusText());

        $this->assertTrue($this->store->toggleManual());
        $this->assertSame('10:00', $this->store->force_open_until->timezone('Africa/Tripoli')->format('H:i'));

        $this->at('09:30');
        $this->assertTrue($this->store->fresh()->isAcceptingOrders());
        $this->at('15:00');
        $this->assertTrue($this->store->fresh()->isAcceptingOrders());
        $this->at('22:30');
        $this->assertFalse($this->store->fresh()->isAcceptingOrders());
    }

    public function test_late_manual_open_is_capped(): void
    {
        $this->at('22:30');
        $this->assertTrue($this->store->toggleManual());
        // الحد الافتراضي 6 ساعات: لين 04:30
        $this->assertSame('04:30', $this->store->force_open_until->timezone('Africa/Tripoli')->format('H:i'));
        $this->assertStringContainsString('خارج الأوقات لين 04:30', $this->store->statusText());

        $this->at('23:59');
        $this->assertTrue($this->store->fresh()->isAcceptingOrders());
        Carbon::setTestNow(Carbon::parse('2026-10-02 05:00', 'Africa/Tripoli')->utc());
        $this->assertFalse($this->store->fresh()->isAcceptingOrders());
    }

    public function test_manual_close_inside_hours_and_reopen(): void
    {
        $this->at('12:00');
        $this->assertFalse($this->store->toggleManual());
        $this->assertFalse($this->store->fresh()->is_open);
        $this->assertSame('مغلق (يدوياً)', $this->store->fresh()->statusText());
        $this->assertTrue($this->store->fresh()->toggleManual());
        $this->assertNull($this->store->fresh()->force_open_until);
    }

    public function test_customer_can_quote_but_not_order_when_closed_and_store_app_toggle(): void
    {
        $this->at('08:00');
        $p = $this->store->products()->create(['name' => 'برجر', 'price' => 20, 'is_available' => true]);
        $c = User::create(['name' => 'ز', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $a = $c->addresses()->create(['label' => 'x', 'details' => 'x', 'lat' => 31.87, 'lng' => 10.98]);
        Sanctum::actingAs($c);
        $body = ['store_id' => $this->store->id, 'address_id' => $a->id, 'items' => [['product_id' => $p->id, 'quantity' => 1]]];

        $this->getJson("/api/v1/stores/{$this->store->id}")->assertJsonPath('data.is_accepting', false)
            ->assertJsonPath('data.opens_at', '10:00');
        $this->postJson('/api/v1/orders/quote', $body)->assertOk()
            ->assertJsonPath('store_accepting', false)
            ->assertJsonPath('store_closed_message', fn ($m) => str_contains($m, 'يفتح الساعة 10:00'));
        $this->postJson('/api/v1/orders', $body)->assertStatus(422);

        // المتجر يفتح بدري من التطبيق
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/store/toggle-open')->assertOk()->assertJsonPath('is_accepting', true);
        $this->getJson('/api/v1/store/summary')->assertJsonPath('store.is_accepting', true);

        Sanctum::actingAs($c);
        $this->postJson('/api/v1/orders/quote', $body)->assertJsonPath('store_accepting', true);
        $this->postJson('/api/v1/orders', $body)->assertCreated();
    }
}
