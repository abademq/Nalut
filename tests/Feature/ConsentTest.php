<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\LegalDocuments\Pages\EditLegalDocument;
use App\Models\Campaign;
use App\Models\LegalDocument;
use App\Models\User;
use App\Models\UserConsent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_are_public_and_filled(): void
    {
        $this->getJson('/api/v1/legal')->assertOk()->assertJsonPath('data.0.key', 'terms_customer');
        $this->withHeader('X-App', 'driver')->getJson('/api/v1/legal')->assertJsonPath('data.0.key', 'terms_driver');

        $body = $this->getJson('/api/v1/legal/privacy')->assertOk()->json('data.body');
        $this->assertStringNotContainsString('{company}', $body);
        $this->assertStringContainsString('القمرة المظلمة', $body);

        $this->get('/legal/terms-driver')->assertOk()->assertSee('مقدّم خدمة توصيل مستقل', false);
        $this->get('/legal/nothing')->assertNotFound();
    }

    public function test_explicit_consent_flow_and_reconsent(): void
    {
        $u = User::create(['name' => 'ز', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/me/consents')->assertOk()
            ->assertJsonCount(2, 'needed')->assertJsonPath('marketing_asked', false);

        // لازم الاثنين
        $this->postJson('/api/v1/me/consents', ['documents' => ['privacy']])->assertStatus(422);
        $this->postJson('/api/v1/me/consents', ['documents' => ['terms_customer', 'privacy'], 'marketing_opt_in' => false])->assertOk();
        $this->getJson('/api/v1/me/consents')->assertJsonCount(0, 'needed')
            ->assertJsonPath('marketing_opt_in', false)->assertJsonPath('marketing_asked', true);
        $this->assertSame(2, UserConsent::where('user_id', $u->id)->count());

        // الإدارة: تغيير جوهري ← نسخة جديدة
        $admin = User::create(['name' => 'a', 'phone' => '0900000000', 'email' => 'a@a.ly', 'password' => 'x', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $doc = LegalDocument::firstWhere('key', 'privacy');
        Livewire::test(EditLegalDocument::class, ['record' => $doc->id])
            ->fillForm(['body' => $doc->body."\n## بند جديد", 'require_reconsent' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $doc->fresh()->version);

        Sanctum::actingAs($u);
        $this->getJson('/api/v1/me/consents')->assertJsonPath('needed.0.key', 'privacy');
    }

    public function test_marketing_toggle_and_campaign_audience(): void
    {
        $u = User::create(['name' => 'ز', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true, 'fcm_token' => 't']);
        $c = new Campaign(['channel' => 'push', 'audience' => 'all', 'audience_params' => []]);
        // ما اختارش بنفسه ← ما توصلوش العروض
        $this->assertSame(0, $c->customersQuery()->count());

        Sanctum::actingAs($u);
        $this->postJson('/api/v1/me/marketing', ['opt_in' => true])->assertOk();
        $this->assertSame(1, $c->customersQuery()->count());
        $this->postJson('/api/v1/me/marketing', ['opt_in' => false])->assertOk();
        $this->assertSame(0, $c->customersQuery()->count());
    }
}
