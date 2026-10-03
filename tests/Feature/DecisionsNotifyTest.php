<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\IssueDecisionsSettings;
use App\Models\Campaign;
use App\Models\DriverProfile;
use App\Models\FailureReason;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\DeliveryIssueService;
use App\Support\IssueDecisions;
use App\Support\Options;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** v85: قرارات البلاغات قابلة للتعديل · إجراء ما بعد مؤقت التسليم · إشعارات من تطبيق الإدارة */
class DecisionsNotifyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $driver;

    private User $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Cache::flush();
        $this->admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->customer = User::create(['name' => 'زبون', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true,
            'fcm_token' => 'tok-c']);
        $this->driver = User::create(['name' => 'علي', 'phone' => '0912222222', 'role' => UserRole::Driver->value, 'is_active' => true,
            'fcm_token' => 'tok-d']);
        DriverProfile::create(['user_id' => $this->driver->id, 'is_approved' => true, 'is_online' => true]);
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_active' => true]);
        $this->order = Order::create([
            'code' => 'D1', 'customer_id' => $this->customer->id, 'store_id' => $store->id, 'driver_id' => $this->driver->id,
            'status' => OrderStatus::OnTheWay, 'address_details' => 'x', 'address_lat' => 31.8, 'address_lng' => 10.9,
            'customer_phone' => '1', 'total' => 30, 'subtotal' => 25, 'wallet_paid' => 30,
        ]);
    }

    /** @var list<string> نصوص الإشعارات اللي انبعتت (FCM مش مضبوط في الاختبار فيتسجّل في اللوق) */
    private array $pushes = [];

    private function capturePushes(): void
    {
        $this->pushes = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            if (str_contains($e->message, 'FCM not configured')) {
                $this->pushes[] = (string) ($e->context['body'] ?? '');
            }
        });
    }

    private function assertPushed(string $text): void
    {
        $this->assertTrue(collect($this->pushes)->contains(fn ($b) => str_contains($b, $text)), "ما انبعتش إشعار فيه: $text");
    }

    private function openIssue()
    {
        return app(DeliveryIssueService::class)->report($this->order, $this->driver, FailureReason::firstOrFail(), null, null, null);
    }

    public function test_decisions_editable_and_disabled_ones_rejected(): void
    {
        IssueDecisions::save('cancelled', ['enabled' => false]);
        IssueDecisions::save('continue', ['enabled' => true, 'label' => 'كمّل يا بطل', 'driver_message' => 'كمّل الطلب {code}', 'customer_message' => 'طلبك {code} راجع في الطريق']);

        $this->assertSame(['continue' => 'كمّل يا بطل', 'reassign' => 'إسناده لسائق آخر', 'failed' => 'فشل التسليم'], IssueDecisions::options());

        $issue = $this->openIssue();
        try {
            app(DeliveryIssueService::class)->resolve($issue, $this->admin, 'cancelled');
            $this->fail('قرار موقوف لازم يترفض');
        } catch (ValidationException) {
        }

        $this->capturePushes();
        app(DeliveryIssueService::class)->resolve($issue->fresh(), $this->admin, 'continue');
        $this->assertPushed('كمّل الطلب D1');
        $this->assertPushed('طلبك D1 راجع في الطريق');

        // تطبيق الإدارة ياخذ القائمة المعدّلة
        $this->order->update(['status' => OrderStatus::OnTheWay]);
        $this->openIssue();
        Sanctum::actingAs($this->admin);
        $this->assertSame('كمّل يا بطل', $this->getJson("/api/v1/admin/orders/{$this->order->id}")->json('issue.resolutions.continue'));
    }

    public function test_settings_page_saves_decisions_and_handover(): void
    {
        $this->actingAs($this->admin);
        $this->get(IssueDecisionsSettings::getUrl())->assertOk()->assertSee('بعد انتهاء مؤقت التسليم')->assertSee('قرار البلاغ');

        Livewire::test(IssueDecisionsSettings::class)
            ->set('data.handover.handover__expired_action', 'report_issue')
            ->set('data.handover.handover__driver_message', 'كلّم الدعم')
            ->set('data.handover.delivery__handover_wait_minutes', 3)
            ->set('data.decisions.failed.label', 'ما تسلّمش')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('report_issue', Options::get('handover.expired_action'));
        $this->assertSame(3, Options::get('delivery.handover_wait_minutes'));
        $this->assertSame('ما تسلّمش', IssueDecisions::label('failed'));

        foreach (['continue', 'reassign', 'failed', 'cancelled'] as $k) {
            Livewire::test(IssueDecisionsSettings::class)->set("data.decisions.$k.enabled", false);
        }
        $c = Livewire::test(IssueDecisionsSettings::class);
        foreach (['continue', 'reassign', 'failed', 'cancelled'] as $k) {
            $c->set("data.decisions.$k.enabled", false);
        }
        $c->call('save')->assertHasErrors();
    }

    public function test_report_issue_action_blocks_door_and_expiry_notifies(): void
    {
        Setting::put('opt.handover.expired_action', 'report_issue');
        Sanctum::actingAs($this->driver);
        $this->postJson("/api/v1/driver/orders/{$this->order->id}/status", ['status' => 'awaiting_handover'])->assertOk();
        $this->travel(6)->minutes();

        $h = $this->getJson('/api/v1/driver/orders')->json('data.0.handover');
        $this->assertSame('report_issue', $h['expired_action']);
        $this->assertStringContainsString('تعذّر التسليم', (string) $h['leave_at_door_blocker']);
        $this->assertSame(Options::get('handover.driver_message'), $h['expired_message']);

        $this->capturePushes();
        $this->artisan('orders:handover-expired')->assertSuccessful();
        $this->assertNotNull($this->order->fresh()->handover_expired_at);
        $this->assertPushed(Options::get('handover.driver_message'));
        $this->assertPushed(Options::get('handover.customer_message'));

        $before = count($this->pushes);
        $this->artisan('orders:handover-expired')->assertSuccessful();
        $this->assertCount($before, $this->pushes); // مرة وحدة بس
    }

    public function test_admin_app_sends_notifications(): void
    {
        $this->customer->update(['marketing_opt_out' => true]);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/me')->assertJsonPath('user.can.messages', true);

        // عرض: الزبون رافض العروض → ما يوصلهوش. تنبيه خدمي → يوصله
        $this->postJson('/api/v1/admin/notifications', ['target_role' => 'customer', 'kind' => 'promo', 'title' => 'عرض', 'body' => 'x', 'dry_run' => true])
            ->assertOk()->assertJsonPath('recipients', 0);
        $this->postJson('/api/v1/admin/notifications', ['target_role' => 'customer', 'kind' => 'service', 'title' => 'صيانة', 'body' => 'الخدمة ترجع 8', 'dry_run' => true])
            ->assertOk()->assertJsonPath('recipients', 1);

        $this->postJson('/api/v1/admin/notifications', ['target_role' => 'customer', 'kind' => 'service', 'title' => 'صيانة', 'body' => 'الخدمة ترجع 8'])
            ->assertCreated()->assertJsonPath('queued', true);
        $c = Campaign::latest('id')->first();
        $this->assertSame('queued', $c->status);
        $this->assertSame('service', $c->audience_params['kind']);

        $this->capturePushes();
        $this->artisan('campaigns:send-due')->assertSuccessful();
        $this->assertSame('done', $c->fresh()->status);
        $this->assertPushed('الخدمة ترجع 8');

        // لرقم واحد (سائق)
        $this->postJson('/api/v1/admin/notifications', ['target_role' => 'driver', 'kind' => 'service', 'phone' => '091-2222222', 'title' => 'تنبيه', 'body' => 'تعال للمكتب'])
            ->assertOk()->assertJsonPath('sent', true);
        $this->postJson('/api/v1/admin/notifications', ['target_role' => 'driver', 'kind' => 'service', 'phone' => '0925555555', 'title' => 'x', 'body' => 'y'])
            ->assertStatus(422);

        $this->assertCount(1, $this->getJson('/api/v1/admin/notifications')->assertOk()->json('data'));
    }

    public function test_notifications_need_permission(): void
    {
        $staff = User::create(['name' => 'موظف', 'phone' => '0934444444', 'password' => 'x12345678',
            'role' => 'admin', 'permissions' => ['orders.view'], 'is_active' => true]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/admin/notifications')->assertForbidden();
        $this->postJson('/api/v1/admin/notifications', ['target_role' => 'customer', 'kind' => 'service', 'title' => 'x', 'body' => 'y'])->assertForbidden();
    }
}
