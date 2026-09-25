<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\RechargeCard;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrdersCoverageCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private Store $store;
    private Product $product;

    // نالوت
    private const IN = [31.8686, 10.9817];
    // طرابلس — برا المنطقة
    private const OUT = [32.8872, 13.1913];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // الإشعارات

        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->store = Store::create([
            'user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true,
            'lat' => self::IN[0], 'lng' => self::IN[1], 'prep_time_minutes' => 20,
        ]);
        $this->product = $this->store->products()->create(['name' => 'برجر', 'price' => 20, 'is_available' => true]);

        DeliveryZone::create([
            'name' => 'نالوت', 'center_lat' => self::IN[0], 'center_lng' => self::IN[1], 'radius_km' => 10, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->customer);
    }

    private function address(array $at): Address
    {
        // مباشرة بدون API — نحاكي عنوان قديم محفوظ قبل تفعيل التغطية
        return $this->customer->addresses()->create([
            'label' => 'x', 'details' => 'حي', 'lat' => $at[0], 'lng' => $at[1],
        ]);
    }

    private function placeOrder(Address $a)
    {
        return $this->postJson('/api/v1/orders', [
            'store_id' => $this->store->id, 'address_id' => $a->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
    }

    // ===== التغطية =====

    public function test_address_outside_zones_is_rejected(): void
    {
        $this->postJson('/api/v1/addresses', ['details' => 'طرابلس', 'lat' => self::OUT[0], 'lng' => self::OUT[1]])
            ->assertStatus(422)
            ->assertJsonPath('errors.lat.0', 'عذراً، خدمتنا غير متوفرة في منطقتك حالياً.');

        $this->postJson('/api/v1/addresses', ['details' => 'نالوت', 'lat' => self::IN[0], 'lng' => self::IN[1]])
            ->assertCreated();
    }

    public function test_coverage_endpoint(): void
    {
        $this->getJson('/api/v1/coverage?lat='.self::IN[0].'&lng='.self::IN[1])->assertOk()->assertJson(['covered' => true, 'zone' => 'نالوت']);
        $this->getJson('/api/v1/coverage?lat='.self::OUT[0].'&lng='.self::OUT[1])->assertOk()->assertJson(['covered' => false]);
    }

    public function test_old_address_outside_zones_cannot_order_or_quote(): void
    {
        $a = $this->address(self::OUT);

        $this->placeOrder($a)->assertStatus(422)->assertJsonPath('errors.address_id.0', 'عذراً، خدمتنا غير متوفرة في منطقتك حالياً.');
        $this->postJson('/api/v1/orders/quote', [
            'store_id' => $this->store->id, 'address_id' => $a->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_updating_address_location_recomputes_zone(): void
    {
        $a = $this->address(self::IN);

        $this->putJson("/api/v1/addresses/{$a->id}", ['lat' => self::OUT[0], 'lng' => self::OUT[1]])->assertStatus(422);
        $this->assertEquals(self::IN[0], $a->fresh()->lat);
    }

    public function test_no_zones_configured_does_not_block(): void
    {
        DeliveryZone::query()->delete();
        $this->placeOrder($this->address(self::OUT))->assertCreated();
    }

    // ===== أرقام الطلبات =====

    public function test_order_numbers_are_sequential(): void
    {
        $a = $this->address(self::IN);

        $first = $this->placeOrder($a)->assertCreated()->json('data.code');
        $this->travel(2)->minutes();
        $second = $this->placeOrder($a)->assertCreated()->json('data.code');

        $this->assertSame('1', $first);
        $this->assertSame('2', $second);
        $this->assertSame('2', Order::find(2)->code);
    }

    // ===== مدة التحضير =====

    public function test_accepting_saves_prep_time_and_exposes_eta(): void
    {
        $order = Order::find($this->placeOrder($this->address(self::IN))->assertCreated()->json('data.id'));

        $this->freezeTime();
        $order = app(OrderService::class)->transition($order, OrderStatus::Preparing, $this->store->owner, ['prep_time_minutes' => 25]);

        $this->assertNotNull($order->accepted_at);
        $this->assertSame(25, (int) $order->prep_time_minutes);
        $this->assertEquals(now()->addMinutes(25)->timestamp, $order->readyEta()->timestamp);

        $res = $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->assertSame(25, $res->json('data.prep_time_minutes'));
        $this->assertSame(25, $res->json('data.minutes_until_ready'));
        $this->assertNotNull($res->json('data.ready_eta'));

        // accepted_at ما يتغيّرش لما نحسبو الجاهزية
        $before = $order->accepted_at->timestamp;
        $order->isAvailableForDrivers();
        $order->minutesUntilReady();
        $this->assertSame($before, $order->accepted_at->timestamp);
    }

    // ===== كروت الشحن =====

    public function test_card_codes_are_digits_only_with_configured_length(): void
    {
        config(['wallet.card_digits' => 8]);
        $code = RechargeCard::generateCode();
        $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', $code);

        config(['wallet.card_digits' => 12]);
        $this->assertMatchesRegularExpression('/^[1-9]\d{11}$/', RechargeCard::generateCode());
        $this->assertSame('1234 5678 9012', RechargeCard::format('123456789012'));
    }

    public function test_redeem_accepts_spaces_and_dashes_and_old_codes(): void
    {
        RechargeCard::create(['code' => '123456789012', 'amount' => 10, 'status' => 'unused']);
        RechargeCard::create(['code' => 'NLT-ABCD-EFGH', 'amount' => 5, 'status' => 'unused']);

        $this->postJson('/api/v1/wallet/redeem', ['code' => '1234 5678-9012'])->assertOk();
        $this->postJson('/api/v1/wallet/redeem', ['code' => 'nlt-abcd-efgh'])->assertOk()->assertJsonPath('balance', 15);
    }

    public function test_redeem_brute_force_is_limited(): void
    {
        config(['wallet.redeem_max_failures_per_user' => 5]);
        RechargeCard::create(['code' => '123456789012', 'amount' => 10, 'status' => 'unused']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/wallet/redeem', ['code' => '99999999999'.$i])->assertStatus(422);
        }

        // حتى الكرت الصحيح يتوقف بعد 5 محاولات خاطئة
        $this->postJson('/api/v1/wallet/redeem', ['code' => '123456789012'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', fn ($m) => str_contains($m, 'محاولات خاطئة كثيرة'));

        $this->assertSame('unused', RechargeCard::first()->status);
    }
}
