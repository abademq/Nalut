<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\AdminAlertSettings;
use App\Filament\Pages\AppThemeSettings;
use App\Models\User;
use App\Services\AdminAlerts;
use App\Support\AdminAlertTypes;
use App\Support\Duration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** v79: ألوان التطبيقات · تنبيهات لوحة التحكم · تقريب المدة */
class ThemeAlertsDurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
    }

    public function test_theme_saved_in_panel_reaches_apps(): void
    {
        $this->actingAs($this->admin);
        $this->get(AppThemeSettings::getUrl())->assertOk()->assertSee('مظهر التطبيقات')->assertSee('تأكيد الطلب');

        Livewire::test(AppThemeSettings::class)
            ->set('data.accent', '#c2185b')
            ->call('save')
            ->assertHasNoErrors();

        foreach (['customer', 'store', 'driver', 'admin'] as $app) {
            $theme = $this->getJson("/api/v1/app/content?app=$app")->assertOk()->json('theme');
            $this->assertSame('#C2185B', $theme['accent']);
            $this->assertSame('#075C52', $theme['primary']);
            $this->assertSame(1, $theme['version']);
        }

        Livewire::test(AppThemeSettings::class)->set('data.accent', 'red')->call('save')->assertHasErrors();
        Livewire::test(AppThemeSettings::class)->callAction('reset');
        $this->assertSame('#FF7900', $this->getJson('/api/v1/app/content')->json('theme.accent'));
    }

    public function test_admin_alert_types_control_bell_sound_and_push(): void
    {
        $this->actingAs($this->admin);
        $this->get(AdminAlertSettings::getUrl())->assertOk()->assertSee('بلاغ من سائق')->assertSee('طلب جديد');
        $this->get('/admin/notification-settings')->assertOk()->assertSee('تنبيهات لوحة التحكم');

        // «تذكرة جديدة»: بدون صوت، بنغمة «جرس»
        Livewire::test(AdminAlertSettings::class)
            ->set('data.ticket_new.sound', false)
            ->set('data.stuck_pending.enabled', false)
            ->set('data.stuck_pending.push', false)
            ->set('data.order_failed.tone', 'bell')
            ->call('save')->assertHasNoErrors();

        $this->assertFalse(AdminAlertTypes::config('ticket_new')['sound']);
        $this->assertSame('bell', AdminAlertTypes::config('order_failed')['tone']);

        // مطفي: ما يوصلش
        $this->assertFalse(AdminAlerts::send('طلب عالق', 'x', null, 'warning', 'stuck-pending:1'));
        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());

        AdminAlerts::send('فشل', 'x', null, 'danger', 'order-failed:5');
        $poll = $this->getJson('/admin-api/alerts')->assertOk();
        $this->assertTrue($poll->json('latest.sound'));
        $this->assertStringContainsString('tone_bell.wav', $poll->json('latest.tone'));

        $this->travel(5)->seconds();
        AdminAlerts::send('تذكرة', 'x', null, 'info', 'ticket-new:9');
        $this->assertFalse($this->getJson('/admin-api/alerts')->json('latest.sound'));

        // «طلب جديد»: الافتراضي تطبيق الإدارة بس — ما يظهرش في الجرس
        $before = $this->admin->fresh()->unreadNotifications()->count();
        AdminAlerts::pushOnly('طلب جديد', 'x', ['order_id' => '3']);
        $this->assertSame($before, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_duration_is_rounded_in_arabic(): void
    {
        $this->assertSame('20 ساعة و29 دقيقة', Duration::minutes(1228.8333333333));
        $this->assertSame('20 ساعة و15 دقيقة', Duration::minutes(1215));
        $this->assertSame('دقيقتين', Duration::minutes(2.2));
        $this->assertSame('5 دقائق', Duration::minutes(5));
        $this->assertSame('ساعة', Duration::minutes(60));
        $this->assertSame('يومين و3 ساعات', Duration::minutes(51 * 60));
        $this->assertSame('45 ثانية', Duration::seconds(45));
    }
}
