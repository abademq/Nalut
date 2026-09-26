<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Messaging\CampaignResource;
use App\Filament\Resources\Messaging\MessageLogResource;
use App\Filament\Resources\Messaging\MessageTemplateResource;
use App\Filament\Resources\Messaging\ReportSubscriptionResource;
use App\Models\Campaign;
use App\Models\DeliveryZone;
use App\Models\FailureReason;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\ReportSubscription;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Reports\StoreReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Store $store;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()->whenEmpty(Http::response(['messages' => [['id' => 'wamid.X']]])),
            'dev.resala.ly/*'      => Http::response(['pin' => '4821', 'id' => 'r1']),
        ]);

        config([
            'messaging.whatsapp.token' => 'wa-token',
            'messaging.whatsapp.phone_number_id' => '555',
            'otp.driver' => 'resala', 'otp.resala.token' => 'r-token', 'otp.length' => 4,
            'otp.test_numbers' => [], 'otp.debug' => false,
        ]);

        $this->admin = User::create(['name' => 'مدير', 'phone' => '0910000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $owner = User::create(['name' => 'صاحب', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم نالوت', 'slug' => 'm', 'phone' => '0925555555',
            'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        DeliveryZone::create(['name' => 'z', 'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 10, 'is_active' => true]);
        $this->customer = User::create(['name' => 'علي', 'phone' => '0913000001', 'role' => UserRole::Customer->value, 'is_active' => true]);
    }

    private function order(string $status = 'delivered', array $extra = []): Order
    {
        return Order::create($extra + [
            'code' => 'T'.random_int(1000, 99999), 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'status' => $status, 'address_details' => 'x', 'address_lat' => 31.8, 'address_lng' => 10.9,
            'customer_phone' => $this->customer->phone, 'subtotal' => 100, 'total' => 105, 'delivery_fee' => 5,
            'commission_amount' => 15, 'store_earning' => 85,
        ]);
    }

    // ===== التسجيل برقم موجود =====

    public function test_signup_with_existing_phone_is_rejected_before_sending(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => $this->customer->phone, 'purpose' => 'signup'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'الرقم هذا مسجّل من قبل. ادخل بكلمة المرور أو برمز التحقق.');

        Http::assertNothingSent();
    }

    public function test_verify_with_create_account_on_existing_phone_fails(): void
    {
        config(['otp.test_numbers' => [$this->customer->phone => '1234']]);
        $this->postJson('/api/v1/auth/otp', ['phone' => $this->customer->phone])->assertOk();

        $this->postJson('/api/v1/auth/verify', ['phone' => $this->customer->phone, 'code' => '1234', 'create_account' => true, 'name' => 'ثاني'])
            ->assertStatus(422);

        $this->assertSame('علي', $this->customer->fresh()->name);
        $this->assertSame(1, User::where('phone', $this->customer->phone)->count());
    }

    public function test_otp_login_for_unknown_phone_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '0914444444', 'purpose' => 'login'])->assertStatus(422);
    }

    // ===== رمز التحقق على واتساب =====

    public function test_otp_goes_via_whatsapp_when_enabled(): void
    {
        Setting::put('opt.otp.channel', 'whatsapp_sms');

        $this->postJson('/api/v1/auth/otp', ['phone' => '0914444444'])->assertOk()->assertJsonPath('channel', 'whatsapp');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com/v21.0/555/messages')
            && $r['to'] === '218914444444'
            && $r['template']['name'] === 'otp_code'
            && $r['template']['components'][1]['sub_type'] === 'url');
        $this->assertDatabaseHas('message_logs', ['context' => 'otp', 'status' => 'sent']);
    }

    public function test_customer_can_force_sms(): void
    {
        Setting::put('opt.otp.channel', 'whatsapp_sms');

        $this->postJson('/api/v1/auth/otp', ['phone' => '0914444444', 'channel' => 'sms'])->assertOk()->assertJsonPath('channel', 'sms');
    }

    public function test_whatsapp_failure_falls_back_to_sms(): void
    {
        Setting::put('opt.otp.channel', 'whatsapp_sms');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'not on whatsapp']], 400),
            'dev.resala.ly/*'      => Http::response(['pin' => '4821', 'id' => 'r1']),
        ]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0914444444'])->assertOk()->assertJsonPath('channel', 'sms');
        $this->assertDatabaseHas('message_logs', ['context' => 'otp', 'status' => 'failed']);
    }

    // ===== تنبيهات الإدارة =====

    public function test_failed_order_alerts_admins_once(): void
    {
        $o = $this->order('on_the_way', ['picked_up_at' => now()]);
        app(OrderService::class)->transition($o, OrderStatus::Failed, null, ['reason' => 'الزبون ما ردّش']);

        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertStringContainsString($o->code, $this->admin->notifications()->first()->data['title']);
    }

    public function test_driver_issue_under_review_alerts_admins(): void
    {
        $driver = User::create(['name' => 'س', 'phone' => '0915000001', 'role' => UserRole::Driver->value, 'is_active' => true]);
        $driver->driverProfile()->create(['is_approved' => true, 'is_online' => true]);
        $reason = FailureReason::create(['label' => 'حادث', 'hold_for_review' => true, 'open_support' => true, 'is_active' => true]);
        $o = $this->order('assigned', ['driver_id' => $driver->id]);

        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/driver/orders/{$o->id}/issue", ['reason_id' => $reason->id])
            ->assertCreated()->assertJsonPath('support_expected', true);

        $this->assertStringContainsString('بلاغ', $this->admin->notifications()->first()->data['title']);
    }

    public function test_stuck_orders_command_alerts(): void
    {
        $o = $this->order('pending');
        $o->forceFill(['created_at' => now()->subMinutes(15)])->save();

        $this->artisan('orders:check-stuck')->assertOk();
        $this->artisan('orders:check-stuck')->assertOk(); // مرة وحدة بس

        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_alerts_poll_endpoint(): void
    {
        $o = $this->order('on_the_way');
        app(OrderService::class)->transition($o, OrderStatus::Failed, null, ['force' => true]);

        $this->actingAs($this->admin, 'web')->getJson('/admin-api/alerts')
            ->assertOk()->assertJsonPath('unread', 1);

        $this->actingAs($this->customer, 'web')->getJson('/admin-api/alerts')->assertForbidden();
    }

    // ===== التقارير =====

    public function test_report_vars_include_sales_balance_and_last_payout(): void
    {
        $this->order('delivered');
        $this->order('cancelled');
        app(\App\Services\WalletService::class)->credit($this->store->owner, 300, 'store_earning');
        app(\App\Services\WalletService::class)->settle($this->store->owner, 120, 'payout', 'تسكير', $this->admin);

        $v = StoreReport::build($this->store, 'today');

        $this->assertSame(2, $v['orders']);
        $this->assertSame(1, $v['delivered']);
        $this->assertSame('100.00', $v['sales']);
        $this->assertSame('85.00', $v['net']);
        $this->assertSame('180.00', $v['balance']);
        $this->assertSame('120.00', $v['last_payout']);
    }

    public function test_report_subscription_schedule_and_send(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00', 'Africa/Tripoli')->utc());

        $t = MessageTemplate::create(['name' => 'ملخص', 'channel' => 'whatsapp', 'provider_ref' => 'store_summary',
            'params' => ['{store}', '{orders}', '{sales} د.ل'], 'purpose' => 'report']);

        $s = ReportSubscription::create(['name' => 'يومي', 'store_id' => $this->store->id, 'recipient' => 'store',
            'message_template_id' => $t->id, 'period' => 'today', 'frequency' => 'daily', 'send_time' => '23:00']);

        $this->assertSame('2026-09-26 23:00', $s->next_run_at->setTimezone('Africa/Tripoli')->format('Y-m-d H:i'));

        $this->order('delivered');
        Carbon::setTestNow(Carbon::parse('2026-09-26 23:02', 'Africa/Tripoli')->utc());
        $this->artisan('reports:send-due')->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && $r['to'] === '218925555555'
            && $r['template']['components'][0]['parameters'][2]['text'] === '100.00 د.ل');

        $s->refresh();
        $this->assertSame('sent', $s->last_status);
        $this->assertSame('2026-09-27 23:00', $s->next_run_at->setTimezone('Africa/Tripoli')->format('Y-m-d H:i'));

        Carbon::setTestNow();
    }

    public function test_weekly_and_once_schedules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00', 'Africa/Tripoli')->utc()); // السبت
        $t = MessageTemplate::create(['name' => 'x', 'channel' => 'sms', 'provider_ref' => '7', 'purpose' => 'report']);

        $w = ReportSubscription::create(['name' => 'أسبوعي', 'phone' => '0912222222', 'recipient' => 'custom',
            'message_template_id' => $t->id, 'frequency' => 'weekly', 'weekday' => 7, 'send_time' => '09:00']);
        $this->assertSame('2026-09-27 09:00', $w->next_run_at->setTimezone('Africa/Tripoli')->format('Y-m-d H:i'));

        $once = ReportSubscription::create(['name' => 'مرة', 'phone' => '0912222222', 'recipient' => 'custom',
            'message_template_id' => $t->id, 'frequency' => 'once', 'send_at' => now()->addHour()]);
        $once->sendNow();
        $this->assertFalse($once->fresh()->is_active);
        $this->assertNull($once->fresh()->next_run_at);

        Carbon::setTestNow();
    }

    // ===== الحملات =====

    public function test_campaign_audiences_and_opt_out(): void
    {
        $this->order('delivered');
        $old = User::create(['name' => 'قديم', 'phone' => '0913000002', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->order('delivered', ['customer_id' => $old->id])->forceFill(['created_at' => now()->subDays(90)])->save();
        User::create(['name' => 'رافض', 'phone' => '0913000003', 'role' => UserRole::Customer->value, 'is_active' => true, 'marketing_opt_out' => true]);

        $t = MessageTemplate::create(['name' => 'عرض', 'channel' => 'whatsapp', 'provider_ref' => 'promo', 'params' => ['{name}']]);

        $all = new Campaign(['audience' => 'all', 'message_template_id' => $t->id]);
        $this->assertSame(2, $all->recipients()->count());

        $active = new Campaign(['audience' => 'active', 'audience_params' => ['days' => 30]]);
        $this->assertSame(['0913000001'], $active->recipients()->pluck('phone')->all());

        $inactive = new Campaign(['audience' => 'inactive', 'audience_params' => ['days' => 30]]);
        $this->assertSame(['0913000002'], $inactive->recipients()->pluck('phone')->all());

        $numbers = new Campaign(['audience' => 'numbers', 'audience_params' => ['numbers' => "0911111111\n0922222222, 0911111111"]]);
        $this->assertSame(2, $numbers->recipients()->count());
    }

    public function test_campaign_runs_from_queue_once(): void
    {
        $t = MessageTemplate::create(['name' => 'عرض', 'channel' => 'whatsapp', 'provider_ref' => 'promo', 'params' => ['{name}']]);
        $c = Campaign::create(['title' => 'خصم', 'message_template_id' => $t->id, 'audience' => 'all', 'status' => 'queued']);

        $this->artisan('campaigns:send-due')->assertOk();
        $this->artisan('campaigns:send-due')->assertOk();

        $c->refresh();
        $this->assertSame('done', $c->status);
        $this->assertSame(1, $c->sent);
        $this->assertSame(1, MessageLog::where('context', 'campaign')->count());
        Http::assertSent(fn (Request $r) => ($r['template']['components'][0]['parameters'][0]['text'] ?? null) === 'علي');
    }

    public function test_unconfigured_channel_is_skipped_not_crashing(): void
    {
        config(['messaging.whatsapp.token' => null]);
        $t = MessageTemplate::create(['name' => 'عرض', 'channel' => 'whatsapp', 'provider_ref' => 'promo']);
        $log = app(\App\Services\Messaging\Messenger::class)->send($t, '0912345678', [], 'campaign');

        $this->assertSame('skipped', $log->status);
    }

    // ===== اللوحة =====

    public function test_admin_pages_render(): void
    {
        $this->actingAs($this->admin, 'web');
        $t = MessageTemplate::create(['name' => 'ملخص', 'channel' => 'sms', 'provider_ref' => '12', 'purpose' => 'report', 'params' => ['{orders}']]);
        ReportSubscription::create(['name' => 'يومي', 'store_id' => $this->store->id, 'message_template_id' => $t->id]);
        Campaign::create(['title' => 'خصم', 'message_template_id' => $t->id, 'audience' => 'all']);

        foreach ([MessageTemplateResource::class, CampaignResource::class, ReportSubscriptionResource::class, MessageLogResource::class] as $r) {
            $this->get($r::getUrl())->assertOk();
        }
    }

    public function test_arabic_validation_messages(): void
    {
        $owner = $this->store->owner;
        Sanctum::actingAs($owner);
        $o = $this->order('pending');

        $this->postJson("/api/v1/store/orders/{$o->id}/status", ['status' => 'preparing', 'prep_time_minutes' => 0])
            ->assertStatus(422)->assertJsonPath('errors.prep_time_minutes.0', 'مدة التحضير لازم يكون من 1 لـ 600.');

        $this->postJson("/api/v1/store/orders/{$o->id}/status", ['status' => 'preparing', 'prep_time_minutes' => 1])->assertOk();
    }
}
