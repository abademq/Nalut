<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_manifest_and_service_worker(): void
    {
        $this->get('/order')->assertOk()->assertSee('APP_CONFIG', false)->assertSee('/weborder/app.js', false);
        $this->get('/order/store/5/p/9')->assertOk()->assertSee('APP_CONFIG', false);
        $this->get('/order/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/order/');
        $this->get('/order/sw.js')->assertOk()->assertHeader('Content-Type', 'application/javascript');

        // الموقع موقوف من الإعدادات
        Setting::put('opt.web.enabled', '0');
        Cache::flush();
        $this->get('/order')->assertOk()->assertSee('موقوف مؤقتاً')->assertDontSee('APP_CONFIG', false);
    }

    public function test_api_routes_are_not_swallowed(): void
    {
        $this->getJson('/api/v1/app/content')->assertOk()->assertJsonStructure(['about', 'options']);
        $this->get('/up')->assertOk();
    }

    public function test_web_otp_needs_recaptcha_when_configured(): void
    {
        $h = ['X-Client' => 'web', 'X-App' => 'customer'];
        $body = ['phone' => '0925555555', 'purpose' => 'signup'];

        // بدون مفاتيح: يمشي عادي
        $this->postJson('/api/v1/auth/otp', $body, $h)->assertOk();

        config(['services.recaptcha.site_key' => 's', 'services.recaptcha.secret_key' => 'k']);
        Http::fake(fn ($req) => Http::response(['success' => ($req->data()['response'] ?? '') === 'tok']));
        $this->postJson('/api/v1/auth/otp', ['phone' => '0925555556', 'purpose' => 'signup'], $h)
            ->assertStatus(422)->assertJsonPath('recaptcha', true);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0925555557', 'purpose' => 'signup'], $h + ['X-Recaptcha' => 'tok'])->assertOk();

        // الموقع ما يتقفلش بـ App Check لو «منع»
        config(['services.appcheck.project_number' => '123']);
        Setting::put('opt.security.app_check', 'enforce');
        Cache::flush();
        $this->postJson('/api/v1/auth/login', ['phone' => '0925555555', 'password' => 'x'], $h)->assertStatus(422);
    }

    public function test_activity_marks_web_client(): void
    {
        $this->assertSame('web', Activity::currentApp(
            Request::create('/api/v1/orders', 'POST', server: ['HTTP_X_CLIENT' => 'web', 'HTTP_X_APP' => 'customer'])));
    }
}
