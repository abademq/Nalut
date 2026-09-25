<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0912345678';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'otp.driver' => 'resala',
            'otp.length' => 4,
            'otp.debug' => false,
            'otp.resend_cooldown' => 60,
            'otp.max_per_hour' => 5,
            'otp.max_attempts' => 5,
            'otp.test_numbers' => [],
            'otp.resala.token' => 'test-token',
            'otp.resala.test' => false,
            'otp.resala.base_url' => 'https://dev.resala.ly/api/v1',
        ]);
    }

    private function fakeResala(string $pin = '4821', int $status = 201, ?array $body = null): void
    {
        Http::fake([
            'dev.resala.ly/*' => Http::response($body ?? [
                'id' => 'abc', 'pin' => $pin, 'code' => '218', 'number' => '912345678', 'content' => "Nalut: {$pin}",
            ], $status),
        ]);
    }

    private function requestOtp(string $phone = self::PHONE)
    {
        return $this->postJson('/api/v1/auth/otp', ['phone' => $phone]);
    }

    private function verify(string $code, array $extra = [])
    {
        return $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $code] + $extra);
    }

    public function test_sends_pin_through_resala_with_international_number(): void
    {
        $this->fakeResala();

        $this->requestOtp()->assertOk()->assertJson(['channel' => 'sms', 'debug_code' => null]);

        Http::assertSent(function (Request $r) {
            return str_starts_with($r->url(), 'https://dev.resala.ly/api/v1/pins?')
                && str_contains($r->url(), 'len=4')
                && ! str_contains($r->url(), 'test')
                && $r['phone'] === '218912345678'
                && $r->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_code_is_stored_hashed_not_plain(): void
    {
        $this->fakeResala('4821');
        $this->requestOtp();

        $stored = DB::table('otp_codes')->value('code');
        $this->assertNotSame('4821', $stored);
        $this->assertSame(64, strlen($stored));
    }

    public function test_test_mode_adds_test_flag(): void
    {
        config(['otp.resala.test' => true]);
        $this->fakeResala();
        $this->requestOtp()->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/pins?test&'));
    }

    public function test_correct_code_creates_account_and_returns_token(): void
    {
        $this->fakeResala('4821');
        $this->requestOtp();

        $this->verify('4821', ['create_account' => true, 'name' => 'زبون'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'phone', 'role']]);

        $this->assertDatabaseHas('users', ['phone' => self::PHONE]);
    }

    public function test_code_cannot_be_reused(): void
    {
        $this->fakeResala('4821');
        $this->requestOtp();
        $this->verify('4821', ['create_account' => true, 'name' => 'زبون'])->assertOk();

        $this->verify('4821')->assertStatus(422);
    }

    public function test_wrong_code_counts_attempts_and_locks_after_max(): void
    {
        $this->fakeResala('4821');
        $this->requestOtp();

        for ($i = 1; $i <= 4; $i++) {
            $this->verify('0000')->assertStatus(422)->assertJsonPath('errors.code.0', 'الرمز غير صحيح. باقيلك '.(5 - $i).' محاولات.');
        }

        $this->verify('0000')->assertStatus(422)->assertJsonPath('errors.code.0', 'تجاوزت عدد المحاولات. اطلب رمز جديد.');

        // حتى الرمز الصحيح ما عادش يقبل
        $this->verify('4821')->assertStatus(422);
    }

    public function test_resend_cooldown(): void
    {
        $this->fakeResala();
        $this->requestOtp()->assertOk();
        $this->requestOtp()->assertStatus(422);

        $this->travel(61)->seconds();
        $this->requestOtp()->assertOk();

        Http::assertSentCount(2);
    }

    public function test_hourly_limit_per_phone(): void
    {
        $this->fakeResala();
        config(['otp.resend_cooldown' => 0]);

        for ($i = 0; $i < 5; $i++) {
            $this->requestOtp()->assertOk();
        }

        $this->requestOtp()->assertStatus(422)->assertJsonPath('errors.phone.0', 'طلبت رموز كثيرة. حاول بعد ساعة.');
    }

    public function test_new_code_invalidates_previous(): void
    {
        config(['otp.resend_cooldown' => 0]);
        Http::fakeSequence('dev.resala.ly/*')
            ->push(['pin' => '1111'], 201)
            ->push(['pin' => '2222'], 201);

        $this->requestOtp();
        $this->requestOtp();

        $this->verify('1111')->assertStatus(422);
        $this->verify('2222', ['create_account' => true, 'name' => 'x'])->assertOk();
    }

    public function test_provider_failure_returns_503_without_leaking_details(): void
    {
        $this->fakeResala(status: 400, body: ['message' => 'Insufficient credit']);

        $this->requestOtp()
            ->assertStatus(503)
            ->assertJson(['message' => 'تعذّر إرسال رمز التحقق حالياً. حاول بعد شوية.']);

        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_foreign_or_invalid_numbers_are_rejected_before_sending(): void
    {
        Http::fake();

        $this->requestOtp('00447911123456')->assertStatus(422);
        $this->requestOtp('0971234567')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_test_numbers_use_fixed_code_without_sending(): void
    {
        Http::fake();
        config(['otp.test_numbers' => [self::PHONE => '1234']]);

        $this->requestOtp()->assertOk()->assertJson(['channel' => 'test']);
        $this->verify('1234', ['create_account' => true, 'name' => 'Reviewer'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_debug_code_returned_only_in_dev_mode(): void
    {
        config(['otp.driver' => 'log', 'otp.debug' => true]);

        $code = $this->requestOtp()->assertOk()->json('debug_code');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);
        $this->verify($code, ['create_account' => true, 'name' => 'x'])->assertOk();
    }

    public function test_password_login_works(): void
    {
        User::create([
            'name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value,
            'is_active' => true, 'password' => bcrypt('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', ['phone' => '0911111111', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'store');
    }

    public function test_api_without_token_returns_401_not_500(): void
    {
        $this->get('/api/v1/me')->assertStatus(401);
    }

    public function test_app_hash_is_passed_to_resala_autofill(): void
    {
        $this->fakeResala();

        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE, 'app_hash' => 'FA+9qCX9VSu'])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'autofill=FA%2B9qCX9VSu'));
    }

    public function test_invalid_app_hash_rejected(): void
    {
        Http::fake();
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE, 'app_hash' => 'bad hash!'])->assertStatus(422);
        Http::assertNothingSent();
    }
}
