<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\EmergencyCenter;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Emergency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** v83: مركز الطوارئ */
class EmergencyCenterTest extends TestCase
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

    private function user(string $role, string $phone): User
    {
        return User::create(['name' => $role, 'phone' => $phone, 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_nothing_blocked_by_default(): void
    {
        $this->assertSame([], Emergency::active());
        $c = $this->user('customer', '0911111111');
        Sanctum::actingAs($c);
        $this->getJson('/api/v1/wallet', ['X-App' => 'customer'])->assertOk();
        $e = $this->getJson('/api/v1/app/content?app=customer')->assertOk()->json('emergency');
        $this->assertFalse($e['locked']);
        $this->assertSame(0, $e['min_build']);
    }

    public function test_app_lock_blocks_only_that_app_and_content_stays_open(): void
    {
        Emergency::set(['app_driver'], true, $this->admin);

        $d = $this->user('driver', '0922222222');
        Sanctum::actingAs($d);
        $this->getJson('/api/v1/driver/orders', ['X-App' => 'driver'])->assertStatus(503)
            ->assertJsonPath('emergency.locked', true)
            ->assertJsonPath('emergency.switch', 'app_driver');
        // المسارات المشتركة من تطبيق السائق كمان
        $this->getJson('/api/v1/wallet', ['X-App' => 'driver'])->assertStatus(503);

        // التطبيق يقدر يعرف إنه مقفول
        $this->getJson('/api/v1/app/content?app=driver')->assertOk()->assertJsonPath('emergency.locked', true);
        $this->getJson('/api/v1/app/content?app=customer')->assertOk()->assertJsonPath('emergency.locked', false);

        // الزبون يكمّل عادي
        $c = $this->user('customer', '0911111111');
        Sanctum::actingAs($c);
        $this->getJson('/api/v1/wallet', ['X-App' => 'customer'])->assertOk();

        // سجل + تنبيه للإدارة
        $this->assertTrue(ActivityLog::where('action', 'emergency.on')->exists());
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());

        Emergency::set(['app_driver'], false, $this->admin);
        Sanctum::actingAs($d);
        $this->assertNotSame(503, $this->getJson('/api/v1/driver/orders', ['X-App' => 'driver'])->status());
    }

    public function test_partial_switches(): void
    {
        $c = $this->user('customer', '0911111111');
        Sanctum::actingAs($c);
        $h = ['X-App' => 'customer'];

        Emergency::set(['payments', 'wallet', 'orders', 'auth', 'uploads'], true);

        $this->postJson('/api/v1/payments/checkout', [], $h)->assertStatus(503)->assertJsonPath('emergency.switch', 'payments');
        $this->postJson('/api/v1/wallet/redeem', ['code' => '1'], $h)->assertStatus(503)->assertJsonPath('emergency.switch', 'wallet');
        $this->postJson('/api/v1/orders', ['payment_method' => 'cash'], $h)->assertStatus(503)->assertJsonPath('emergency.switch', 'orders');
        $this->postJson('/api/v1/auth/otp', ['phone' => '0911111111'], $h)->assertStatus(503)->assertJsonPath('emergency.switch', 'auth');
        $this->post('/api/v1/support/tickets', ['image' => UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')], $h + ['Accept' => 'application/json'])
            ->assertStatus(503)->assertJsonPath('emergency.switch', 'uploads');

        // القراءة تكمّل: الزبون يشوف طلباته ومحفظته
        $this->getJson('/api/v1/orders', $h)->assertOk();
        $this->getJson('/api/v1/wallet', $h)->assertOk();

        $this->assertEqualsCanonicalizing(['payments', 'wallet', 'orders', 'auth', 'uploads'],
            $this->getJson('/api/v1/app/content')->json('emergency.blocked'));

        // الدفع بالمحفظة يتوقف مع «تجميد المحافظ» حتى لو الطلبات شغّالة
        Emergency::set(['orders'], false);
        $this->postJson('/api/v1/orders', ['payment_method' => 'wallet'], $h)->assertJsonPath('emergency.switch', 'wallet');
        $this->postJson('/api/v1/orders', ['payment_method' => 'card'], $h)->assertJsonPath('emergency.switch', 'payments');
        $this->postJson('/api/v1/orders', ['payment_method' => 'cash'], $h)->assertStatus(422); // وصل للتحقق العادي
    }

    public function test_merchant_panel_locked_with_store_app(): void
    {
        Emergency::set(['app_store'], true);
        $this->get('/merchant/login')->assertStatus(503)->assertSee('متوقف مؤقتاً');
        Emergency::set(['app_store'], false);
        $this->get('/merchant/login')->assertOk();
    }

    public function test_revoke_tokens_by_role_and_disable_admins(): void
    {
        $c = $this->user('customer', '0911111111');
        $d = $this->user('driver', '0922222222');
        $other = User::create(['name' => 'موظف', 'phone' => '0933333333', 'password' => 'x12345678',
            'role' => 'admin', 'permissions' => ['orders.view'], 'is_active' => true]);
        $c->createToken('mobile');
        $d->createToken('app');
        $other->createToken('admin-app:phone', ['admin']);

        $this->assertSame(1, Emergency::revokeTokens('customer'));
        $this->assertSame(2, PersonalAccessToken::count());
        $this->assertSame(1, Emergency::revokeTokens('admin'));
        $this->assertSame(1, PersonalAccessToken::count());

        $this->assertSame(1, Emergency::disableOtherAdmins($this->admin));
        $this->assertFalse($other->fresh()->is_active);
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame(1, Emergency::restoreAdmins());
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_panel_page_super_admin_only(): void
    {
        $limited = User::create(['name' => 'موظف', 'phone' => '0933333333', 'password' => 'x12345678',
            'role' => 'admin', 'permissions' => ['orders.view', 'settings.manage'], 'is_active' => true]);
        $this->actingAs($limited)->get(EmergencyCenter::getUrl())->assertForbidden();
    }

    public function test_panel_page_actions(): void
    {
        $this->actingAs($this->admin);
        $this->get(EmergencyCenter::getUrl())->assertOk()->assertSee('مركز الطوارئ')->assertSee('إيقاف الدفع الإلكتروني');

        Livewire::test(EmergencyCenter::class)->call('toggle', 'payments');
        $this->assertTrue(Emergency::on('payments'));

        Livewire::test(EmergencyCenter::class)->set('message', 'صيانة قصيرة')->call('saveMessage');
        $this->assertSame('صيانة قصيرة', Emergency::message());

        Livewire::test(EmergencyCenter::class)
            ->callAction('lockAll', ['confirm' => 'غلط'])->assertHasFormErrors();
        $this->assertFalse(Emergency::on('app_customer'));
        Livewire::test(EmergencyCenter::class)->callAction('lockAll', ['confirm' => 'قفل']);
        $this->assertTrue(Emergency::on('app_customer'));
        $this->assertTrue(Emergency::on('app_admin'));

        Livewire::test(EmergencyCenter::class)
            ->set('builds.customer.min', '25')
            ->set('builds.customer.android', 'https://play.google.com/store/apps/details?id=ly.azanx')
            ->call('saveBuilds')->assertHasNoErrors();
        $e = $this->getJson('/api/v1/app/content?app=customer')->json('emergency');
        $this->assertSame(25, $e['min_build']);
        $this->assertSame('صيانة قصيرة', $e['message']);

        Livewire::test(EmergencyCenter::class)->callAction('unlockAll');
        $this->assertSame([], Emergency::active());
    }

    public function test_artisan_command(): void
    {
        $this->artisan('emergency', ['action' => 'on', 'targets' => ['apps']])->assertSuccessful();
        $this->assertTrue(Emergency::on('app_store'));
        $this->assertFalse(Emergency::on('payments'));
        $this->artisan('emergency', ['action' => 'on', 'targets' => ['nope']])->assertFailed();
        $this->artisan('emergency', ['action' => 'off', 'targets' => ['all']])->assertSuccessful();
        $this->assertSame([], Emergency::active());
        $this->artisan('emergency', ['action' => 'min-build', 'targets' => ['driver', '7']])->assertSuccessful();
        $this->assertSame(7, Emergency::minBuild('driver'));
        $this->artisan('emergency', ['action' => 'admins-off', '--keep' => $this->admin->id, '--force' => true])->assertSuccessful();
        $this->artisan('emergency', ['action' => 'status'])->assertSuccessful();
    }
}
