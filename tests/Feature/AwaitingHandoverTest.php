<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** v84: «بانتظار التسليم» — مؤقت الزبون، وبعده يتترك أمام الباب بصورة */
class AwaitingHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('public');

        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->driver = User::create(['name' => 'علي', 'phone' => '0912222222', 'role' => UserRole::Driver->value, 'is_active' => true]);
        DriverProfile::create(['user_id' => $this->driver->id, 'is_approved' => true, 'is_online' => true]);
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم نالوت', 'slug' => 'm', 'is_active' => true]);

        $this->order = Order::create([
            'code' => 'H1', 'customer_id' => $this->customer->id, 'store_id' => $store->id, 'driver_id' => $this->driver->id,
            'status' => OrderStatus::OnTheWay, 'address_details' => 'حي الشهداء', 'address_lat' => 31.87, 'address_lng' => 10.98,
            'customer_phone' => '1', 'total' => 30, 'subtotal' => 25,
        ]);
    }

    private function arrive(): void
    {
        Sanctum::actingAs($this->driver);
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/status", ['status' => 'awaiting_handover'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_handover')
            ->assertJsonPath('data.status_label', 'بانتظار التسليم');
    }

    private function prepaid(): void
    {
        // مدفوع بالكامل من المحفظة — ما فيش نقد يتحصّل
        $this->order->update(['wallet_paid' => 30]);
    }

    public function test_arrival_starts_timer_from_settings(): void
    {
        Setting::put('opt.delivery.handover_wait_minutes', '7');
        $this->arrive();

        $o = $this->order->fresh();
        $this->assertNotNull($o->arrived_at);
        $this->assertEqualsWithDelta(7 * 60, $o->handover_deadline_at->diffInSeconds($o->arrived_at, true), 2);

        Sanctum::actingAs($this->customer);
        $h = $this->getJson("/api/v1/orders/{$o->id}")->assertOk()->json('data.handover');
        $this->assertSame(7, $h['wait_minutes']);
        $this->assertGreaterThan(400, $h['seconds_left']);

        // الخيار يوصل للتطبيقات
        $this->assertSame(7, $this->getJson('/api/v1/app/content')->json('options')['delivery.handover_wait_minutes']);
    }

    public function test_normal_handover_after_arrival(): void
    {
        $this->arrive();
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/status", ['status' => 'delivered'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.left_at_door', false);
    }

    public function test_cannot_leave_before_deadline_or_without_photo(): void
    {
        $this->prepaid();
        $this->arrive();
        $url = "/api/v1/driver/orders/{$this->order->id}/leave-at-door";

        $this->post($url, ['photo' => UploadedFile::fake()->image('door.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->travel(6)->minutes();
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->assertSame(OrderStatus::AwaitingHandover, $this->order->fresh()->status);
    }

    public function test_leave_at_door_with_photo_after_deadline(): void
    {
        $this->prepaid();
        $this->arrive();
        $this->travel(6)->minutes();

        $res = $this->post("/api/v1/driver/orders/{$this->order->id}/leave-at-door",
            ['photo' => UploadedFile::fake()->image('door.jpg'), 'lat' => 31.87, 'lng' => 10.98],
            ['Accept' => 'application/json'])->assertOk();

        $res->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.left_at_door', true)
            ->assertJsonPath('data.status_label', 'تُرك أمام الباب');

        $o = $this->order->fresh();
        $this->assertNotNull($o->left_at_door_at);
        Storage::disk('public')->assertExists($o->door_photo);
        $this->assertStringContainsString('تُرك أمام الباب', (string) $o->statusLogs()->where('to_status', 'delivered')->value('note'));

        // base64 من التطبيق (زي صور الدعم) يخدم كمان
        $o2 = $this->order->replicate(['code'])->fill(['code' => 'H2', 'status' => OrderStatus::AwaitingHandover,
            'handover_deadline_at' => now()->subMinute(), 'left_at_door_at' => null, 'door_photo' => null]);
        $o2->save();
        $img = UploadedFile::fake()->image('d.jpg', 50, 50);
        $b64 = 'data:image/jpeg;base64,'.base64_encode(file_get_contents($img->getPathname()));
        $this->postJson("/api/v1/driver/orders/{$o2->id}/leave-at-door", ['photo' => $b64])->assertOk()
            ->assertJsonPath('data.left_at_door', true);
        $this->postJson("/api/v1/driver/orders/{$o2->id}/leave-at-door", ['photo' => 'data:image/jpeg;base64,xxxx'])->assertStatus(422);

        // الزبون يشوف الصورة، المتجر لا
        Sanctum::actingAs($this->customer);
        $this->assertNotNull($this->getJson("/api/v1/orders/{$o->id}")->json('data.door_photo_url'));
        Sanctum::actingAs($o->store->owner);
        $this->assertArrayNotHasKey('door_photo_url', $this->getJson('/api/v1/store/orders')->json('data.0') ?? []);

    }

    public function test_admin_panel_shows_door_photo(): void
    {
        $this->order->update(['status' => OrderStatus::Delivered, 'arrived_at' => now()->subMinutes(6),
            'left_at_door_at' => now(), 'door_photo' => 'door-photos/x.jpg']);
        $admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin)->get(OrderResource::getUrl('view', ['record' => $this->order]))
            ->assertOk()->assertSee('صورة الإثبات')->assertSee('تُرك أمام الباب');
    }

    public function test_cash_order_cannot_be_left_unless_allowed(): void
    {
        $this->arrive();
        $this->travel(6)->minutes();
        $url = "/api/v1/driver/orders/{$this->order->id}/leave-at-door";

        Sanctum::actingAs($this->driver);
        $blocker = $this->getJson('/api/v1/driver/orders')->json('data.0.handover.leave_at_door_blocker');
        $this->assertStringContainsString('نقدي', (string) $blocker);

        $this->post($url, ['photo' => UploadedFile::fake()->image('door.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Setting::put('opt.delivery.leave_at_door_cash', '1');
        $this->post($url, ['photo' => UploadedFile::fake()->image('door.jpg')], ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_failed_still_possible_and_pickup_never_uses_it(): void
    {
        $this->arrive();
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/status", ['status' => 'failed', 'reason' => 'ما ردّش'])
            ->assertOk()->assertJsonPath('data.status', 'failed');

        $pickup = $this->order->replicate(['code'])->fill(['code' => 'P1', 'fulfillment' => 'pickup', 'status' => OrderStatus::Ready]);
        $pickup->save();
        $this->assertFalse(OrderService::canMove($pickup, OrderStatus::Ready, OrderStatus::AwaitingHandover));
    }
}
