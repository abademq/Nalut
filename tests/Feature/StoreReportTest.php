<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $customer;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.local_timezone' => 'Africa/Tripoli']);

        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم نالوت', 'slug' => 'matam']);

        Sanctum::actingAs($owner);
    }

    /** طلب بتاريخ UTC محدد */
    private function order(string $status, string $utc, float $subtotal, string $payment = 'cash', array $items = []): Order
    {
        $o = Order::create([
            'code' => 'T'.(++$this->seq), 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'status' => $status, 'payment_method' => $payment,
            'address_details' => 'x', 'address_lat' => 31.8, 'address_lng' => 10.9, 'customer_phone' => '0913333333',
            'subtotal' => $subtotal, 'total' => $subtotal + 5, 'delivery_fee' => 5,
            'commission_amount' => round($subtotal * 0.15, 2), 'store_earning' => round($subtotal * 0.85, 2),
        ]);
        $o->forceFill(['created_at' => Carbon::parse($utc, 'UTC')])->save();

        foreach ($items as [$name, $qty, $price]) {
            OrderItem::create(['order_id' => $o->id, 'name' => $name, 'unit_price' => $price, 'quantity' => $qty, 'line_total' => $qty * $price]);
        }

        return $o;
    }

    public function test_daily_report_uses_libya_day_and_counts_only_delivered_as_sales(): void
    {
        // 2026-09-25 بتوقيت ليبيا = من 2026-09-24 22:00 لـ 2026-09-25 21:59:59 UTC
        $this->order('delivered', '2026-09-24 22:30:00', 100, 'cash', [['برجر', 2, 30], ['بيبسي', 4, 10]]);
        $this->order('delivered', '2026-09-25 12:00:00', 50, 'wallet', [['برجر', 1, 30], ['بطاطا', 2, 10]]);
        $this->order('cancelled', '2026-09-25 13:00:00', 70);
        $this->order('failed', '2026-09-25 14:00:00', 20);
        $this->order('preparing', '2026-09-25 20:00:00', 40);
        // برا اليوم الليبي
        $this->order('delivered', '2026-09-24 21:59:00', 999);
        $this->order('delivered', '2026-09-25 22:00:00', 999);

        $r = $this->getJson('/api/v1/store/reports/daily?date=2026-09-25')->assertOk();

        $r->assertJsonPath('date', '2026-09-25')
            ->assertJsonPath('orders.total', 5)
            ->assertJsonPath('orders.delivered', 2)
            ->assertJsonPath('orders.cancelled', 1)
            ->assertJsonPath('orders.failed', 1)
            ->assertJsonPath('orders.active', 1);

        $this->assertEquals(150, $r->json('sales.gross'));
        $this->assertEquals(22.5, $r->json('sales.commission'));
        $this->assertEquals(127.5, $r->json('sales.net'));
        $this->assertEquals(75, $r->json('sales.average'));

        // الترتيب بالكمية: بيبسي 4 ثم برجر 3 (مجموع طلبين)
        $this->assertSame('بيبسي', $r->json('top_products.0.name'));
        $this->assertSame(4, $r->json('top_products.0.quantity'));
        $this->assertSame('برجر', $r->json('top_products.1.name'));
        $this->assertSame(3, $r->json('top_products.1.quantity'));
        $this->assertEquals(90, $r->json('top_products.1.amount'));

        $methods = collect($r->json('by_payment'))->keyBy('method');
        $this->assertSame(1, $methods['cash']['orders']);
        $this->assertEquals(50, $methods['wallet']['amount']);

        // أول طلب 22:30 UTC = 00:30 بتوقيت ليبيا
        $this->assertSame('00:30', $r->json('list.0.time'));
        $this->assertCount(5, $r->json('list'));
    }

    public function test_history_filter_returns_only_final_orders(): void
    {
        $this->order('delivered', now()->toDateTimeString(), 10);
        $this->order('cancelled', now()->toDateTimeString(), 10);
        $this->order('failed', now()->toDateTimeString(), 10);
        $this->order('preparing', now()->toDateTimeString(), 10);

        $res = $this->getJson('/api/v1/store/orders?status=history')->assertOk();
        $statuses = collect($res->json('data'))->pluck('status')->sort()->values()->all();

        $this->assertSame(['cancelled', 'delivered', 'failed'], $statuses);
    }

    public function test_orders_filter_by_date_and_code(): void
    {
        $a = $this->order('delivered', '2026-09-24 22:30:00', 10); // 25 محلياً
        $this->order('delivered', '2026-09-23 10:00:00', 10);

        $this->getJson('/api/v1/store/orders?status=history&date=2026-09-25')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $a->code);

        $this->getJson('/api/v1/store/orders?q='.$a->code)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_report_only_includes_own_store(): void
    {
        $other = User::create(['name' => 'آخر', 'phone' => '0914444444', 'role' => UserRole::Store->value, 'is_active' => true]);
        $otherStore = Store::create(['user_id' => $other->id, 'name' => 'آخر', 'slug' => 'akhar']);
        Order::create([
            'code' => 'OTHER', 'customer_id' => $this->customer->id, 'store_id' => $otherStore->id, 'status' => 'delivered',
            'address_details' => 'x', 'address_lat' => 1, 'address_lng' => 1, 'customer_phone' => '1', 'subtotal' => 500,
        ]);

        $this->getJson('/api/v1/store/reports/daily')->assertOk()->assertJsonPath('orders.total', 0);
    }
}
