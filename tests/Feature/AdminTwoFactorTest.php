<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\Options;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** v87: التحقق بخطوتين لحسابات الإدارة */
class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['otp.test_numbers' => ['0919999999' => '482913']]);
        $this->admin = User::create(['name' => 'مدير', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
    }

    public function test_panel_works_normally_when_disabled(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk();
    }

    public function test_panel_requires_code_when_enabled(): void
    {
        Setting::put('opt.security.admin_2fa', '1');
        $this->actingAs($this->admin);

        $this->get('/admin')->assertRedirect(route('admin.2fa'));
        $this->get('/admin-api/alerts')->assertRedirect(route('admin.2fa'));
        $this->get('/monitor')->assertForbidden();

        $this->get(route('admin.2fa'))->assertOk()->assertSee('091•••••99');

        $this->from(route('admin.2fa'))->post(route('admin.2fa.verify'), ['code' => '000000'])
            ->assertRedirect(route('admin.2fa'))->assertSessionHas('error');
        $this->get('/admin')->assertRedirect(route('admin.2fa'));

        // رمز جديد بعد المحاولة الغلط
        $this->post(route('admin.2fa.resend'));
        $this->get(route('admin.2fa'));
        $this->post(route('admin.2fa.verify'), ['code' => '482913', 'trust' => '1'])->assertRedirect();
        $this->get('/admin')->assertOk();
        $this->get('/monitor')->assertOk();
    }

    public function test_admin_without_phone_is_told(): void
    {
        Setting::put('opt.security.admin_2fa', '1');
        $this->admin->update(['phone' => '123']);
        $this->actingAs($this->admin)->get(route('admin.2fa'))->assertOk()->assertSee('ما فيهش رقم هاتف');
    }

    public function test_admin_app_login_needs_code(): void
    {
        // بدون التفعيل: توكن مباشرة
        $this->postJson('/api/v1/admin/login', ['login' => 'a@a.ly', 'password' => 'secret123'])->assertOk()->assertJsonStructure(['token']);

        Setting::put('opt.security.admin_2fa', '1');
        Cache::flush();
        $res = $this->postJson('/api/v1/admin/login', ['login' => 'a@a.ly', 'password' => 'secret123'])->assertOk()
            ->assertJsonPath('two_factor', true)->assertJsonMissingPath('token');
        $challenge = $res->json('challenge');

        $this->postJson('/api/v1/admin/login/verify', ['challenge' => $challenge, 'code' => '111111'])->assertStatus(422);
        $this->postJson('/api/v1/admin/login/verify', ['challenge' => 'garbage', 'code' => '482913'])->assertStatus(422);

        $this->postJson('/api/v1/admin/login/resend', ['challenge' => $challenge])->assertStatus(429); // مهلة بين الرسائل
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/admin/login/resend', ['challenge' => $challenge])->assertOk();
        $this->postJson('/api/v1/admin/login/verify', ['challenge' => $challenge, 'code' => '482913'])->assertOk()
            ->assertJsonStructure(['token', 'user']);

        // المهلة 10 دقائق
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/admin/login/verify', ['challenge' => $challenge, 'code' => '482913'])->assertStatus(422);
    }

    public function test_emergency_command_turns_it_off(): void
    {
        Setting::put('opt.security.admin_2fa', '1');
        $this->artisan('emergency', ['action' => '2fa-off'])->assertSuccessful();
        $this->assertFalse(Options::get('security.admin_2fa'));
    }

    public function test_offsite_backup_status_alerts(): void
    {
        $file = storage_path('app/backup-status.json');
        @unlink($file);
        $this->artisan('server:check')->assertSuccessful();
        $this->assertSame(0, $this->admin->fresh()->notifications()->count()); // ما تضبطش = بدون تنبيه

        file_put_contents($file, json_encode(['ok' => true, 'at' => now()->subHours(40)->toIso8601String()]));
        $this->artisan('server:check')->assertSuccessful();
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());

        file_put_contents($file, json_encode(['ok' => true, 'at' => now()->toIso8601String()]));
        Cache::flush();
        $this->artisan('server:check')->assertSuccessful();
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());
        @unlink($file);
    }
}
