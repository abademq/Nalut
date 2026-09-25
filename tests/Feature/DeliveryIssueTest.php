<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\FailureReason;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\DeliveryIssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryIssueTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;
    private User $customer;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->driver = User::create(['name' => 'علي', 'phone' => '0912222222', 'role' => UserRole::Driver->value, 'is_active' => true]);
        DriverProfile::create(['user_id' => $this->driver->id, 'is_approved' => true, 'is_online' => true]);
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم نالوت', 'slug' => 'm', 'is_active' => true]);

        $this->order = Order::create([
            'code' => 'X1', 'customer_id' => $this->customer->id, 'store_id' => $store->id, 'driver_id' => $this->driver->id,
            'status' => OrderStatus::OnTheWay, 'address_details' => 'حي الشهداء', 'address_lat' => 31.87, 'address_lng' => 10.98,
            'customer_phone' => '1', 'total' => 30, 'subtotal' => 25,
        ]);

        Setting::put('about.support_whatsapp', '218 91-000-0000');
        Sanctum::actingAs($this->driver);
    }

    private function reason(string $label): FailureReason
    {
        return FailureReason::where('label', $label)->firstOrFail();
    }

    public function test_reasons_listed_from_admin_config(): void
    {
        FailureReason::create(['label' => 'موقوف', 'is_active' => false]);
        $labels = collect($this->getJson('/api/v1/driver/failure-reasons')->assertOk()->json('data'))->pluck('label');

        $this->assertContains('تعطّلت المركبة', $labels);
        $this->assertNotContains('موقوف', $labels);
    }

    public function test_review_reason_holds_order_and_returns_whatsapp_ticket(): void
    {
        $res = $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", [
            'reason_id' => $this->reason('تعطّلت المركبة')->id, 'note' => 'العجلة', 'lat' => 31.86, 'lng' => 10.97,
        ])->assertCreated();

        $res->assertJsonPath('ticket', 'T'.$this->order->fresh()->code.'-1')
            ->assertJsonPath('under_review', true)
            ->assertJsonPath('data.under_review', true)
            ->assertJsonPath('data.status', 'on_the_way')
            ->assertJsonPath('data.status_label', 'قيد مراجعة الإدارة');

        $url = $res->json('support_url');
        $this->assertStringStartsWith('https://wa.me/218910000000?text=', $url);
        $text = rawurldecode(substr($url, strpos($url, 'text=') + 5));
        $this->assertStringContainsString('تعطّلت المركبة', $text);
        $this->assertStringContainsString('العجلة', $text);
        $this->assertStringContainsString('maps.google.com/?q=31.86,10.97', $text);

        // الطلب ما يتحرّكش والسائق ما يقدرش يكمّل
        $this->assertSame(OrderStatus::OnTheWay, $this->order->fresh()->status);
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/status", ['status' => 'delivered'])->assertStatus(422);

        // التذكرة ترجع في قائمة طلبات السائق — زر «فتح التذكرة»
        $mine = $this->getJson('/api/v1/driver/orders?status=active')->assertOk();
        $this->assertSame($url, $mine->json('data.0.issue.support_url'));

        // بلاغ ثاني على نفس الطلب ممنوع
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", ['reason_id' => $this->reason('مشكلة أخرى')->id])->assertStatus(422);
    }

    public function test_customer_sees_review_label_but_not_ticket(): void
    {
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", ['reason_id' => $this->reason('تعطّلت المركبة')->id])->assertCreated();

        Sanctum::actingAs($this->customer);
        $res = $this->getJson("/api/v1/orders/{$this->order->id}")->assertOk();
        $res->assertJsonPath('data.status_label', 'قيد مراجعة الإدارة')->assertJsonMissingPath('data.issue');
    }

    public function test_non_review_reason_fails_order_directly(): void
    {
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", [
            'reason_id' => $this->reason('الزبون ما يردّش والعنوان غير واضح')->id,
        ])->assertCreated()->assertJsonPath('under_review', false)->assertJsonPath('support_url', null);

        $this->assertSame(OrderStatus::Failed, $this->order->fresh()->status);
        $this->assertSame('resolved', OrderIssue::first()->status);
    }

    public function test_before_pickup_any_reason_goes_to_review(): void
    {
        $this->order->update(['status' => OrderStatus::Assigned]);

        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", [
            'reason_id' => $this->reason('الزبون رفض الاستلام')->id,
        ])->assertCreated()->assertJsonPath('under_review', true);

        $this->assertSame(OrderStatus::Assigned, $this->order->fresh()->status);
    }

    public function test_no_support_link_without_number_or_when_disabled(): void
    {
        Setting::put('about.support_whatsapp', '');
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", ['reason_id' => $this->reason('تعطّلت المركبة')->id])
            ->assertCreated()->assertJsonPath('support_url', null);
    }

    public function test_other_driver_cannot_report(): void
    {
        $other = User::create(['name' => 'x', 'phone' => '0914444444', 'role' => UserRole::Driver->value, 'is_active' => true]);
        DriverProfile::create(['user_id' => $other->id, 'is_approved' => true]);
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", ['reason_id' => $this->reason('تعطّلت المركبة')->id])->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resolutions')]
    public function test_admin_resolutions(string $resolution, OrderStatus $expected, bool $driverKept): void
    {
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", ['reason_id' => $this->reason('تعطّلت المركبة')->id])->assertCreated();
        $admin = User::create(['name' => 'a', 'phone' => '0910000009', 'email' => 'a@a.ly', 'password' => 'x', 'role' => UserRole::Admin->value, 'is_active' => true]);

        app(DeliveryIssueService::class)->resolve($this->order->openIssue()->first(), $admin, $resolution, 'قرار');

        $order = $this->order->fresh();
        $this->assertSame($expected, $order->status);
        $this->assertSame($driverKept, $order->driver_id === $this->driver->id);
        $this->assertNull($order->openIssue()->first());
        $this->assertSame($resolution, OrderIssue::first()->resolution);
    }

    public static function resolutions(): array
    {
        return [
            'continue'  => ['continue', OrderStatus::OnTheWay, true],
            'reassign'  => ['reassign', OrderStatus::Ready, false],
            'failed'    => ['failed', OrderStatus::Failed, true],
            'cancelled' => ['cancelled', OrderStatus::Cancelled, true],
        ];
    }

    public function test_admin_pages_render_with_open_issue(): void
    {
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/issue", ['reason_id' => $this->reason('تعطّلت المركبة')->id])->assertCreated();
        $admin = User::create(['name' => 'a', 'phone' => '0910000009', 'email' => 'a@a.ly', 'password' => 'x', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web');

        $this->get('/admin/orders')->assertOk()->assertSee('قيد المراجعة');
        $this->get('/admin/failure-reasons')->assertOk()->assertSee('تعطّلت المركبة');
        $this->get('/admin/app-settings')->assertOk()->assertSee('واتساب الدعم الفني');
    }
}
