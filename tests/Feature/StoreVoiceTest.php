<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** v88: الأوامر الصوتية في تطبيق المتجر */
class StoreVoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private MenuSection $pastry;

    private MenuSection $meals;

    private MenuSection $drinks;

    private Product $shawarmaMeat;

    private Product $shawarmaChicken;

    private Product $pizza;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::put('opt.voice.enabled', '1');
        $this->owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true,
            'lat' => 31.8686, 'lng' => 10.9817]);
        $this->pastry = $this->store->sections()->create(['name' => 'المعجنات', 'sort' => 1]);
        $this->meals = $this->store->sections()->create(['name' => 'وجبات', 'sort' => 2]);
        $this->drinks = $this->store->sections()->create(['name' => 'مشروبات', 'sort' => 3]);
        $this->shawarmaMeat = $this->store->products()->create(['name' => 'شاورما لحم', 'price' => 10, 'is_available' => true, 'is_visible' => true, 'menu_section_id' => $this->meals->id]);
        $this->shawarmaChicken = $this->store->products()->create(['name' => 'شاورما دجاج', 'price' => 9, 'is_available' => true, 'is_visible' => true, 'menu_section_id' => $this->meals->id]);
        $this->pizza = $this->store->products()->create(['name' => 'بيتزا', 'price' => 15, 'is_available' => false, 'is_visible' => true, 'menu_section_id' => $this->meals->id]);
        Sanctum::actingAs($this->owner);
    }

    private function say(string $text, bool $auto = false)
    {
        return $this->postJson('/api/v1/store/voice/interpret', ['text' => $text, 'auto' => $auto]);
    }

    private function order(string $code, OrderStatus $status): Order
    {
        $c = User::firstOrCreate(['phone' => '0913000001'], ['name' => 'زبون', 'role' => UserRole::Customer->value, 'is_active' => true]);

        return Order::create(['code' => $code, 'customer_id' => $c->id, 'store_id' => $this->store->id, 'status' => $status,
            'address_details' => 'x', 'address_lat' => 31.87, 'address_lng' => 10.98, 'customer_phone' => '1', 'total' => 20, 'subtotal' => 15,
            'payment_method' => 'cash']);
    }

    public function test_disabled_by_admin(): void
    {
        Setting::put('opt.voice.enabled', '0');
        $this->say('الشاورما خلصت')->assertForbidden();
    }

    public function test_product_out_needs_confirmation_then_runs(): void
    {
        $res = $this->say('الشاورما خلصت')->assertOk()
            ->assertJsonPath('source', 'rules')->assertJsonPath('needs_confirm', true)->assertJsonCount(2, 'actions');
        $this->assertStringContainsString('شاورما لحم', $res->json('actions.0.label'));
        $this->assertTrue($this->shawarmaMeat->fresh()->is_available); // ما يتنفّذ شي قبل التأكيد

        // المتجر شال «شاورما دجاج» من القائمة
        $this->postJson('/api/v1/store/voice/execute', ['token' => $res->json('token'), 'skip' => [1]])->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.ok', true);
        $this->assertFalse($this->shawarmaMeat->fresh()->is_available);
        $this->assertTrue($this->shawarmaChicken->fresh()->is_available);
    }

    public function test_all_sections_except_and_back(): void
    {
        $this->drinks->pause(false);
        $res = $this->say('جميع الأقسام متوفرة ما عدا المعجنات')->assertOk();
        $labels = array_column($res->json('actions'), 'label');
        $this->assertContains('إيقاف قسم «المعجنات»', $labels);
        $this->assertContains('تشغيل قسم «مشروبات»', $labels);
        $this->assertContains('قسم وجبات متوفر من قبل', $res->json('notes'));

        $this->postJson('/api/v1/store/voice/execute', ['token' => $res->json('token')])->assertOk();
        $this->assertFalse($this->pastry->fresh()->isOrderable());
        $this->assertTrue($this->drinks->fresh()->isOrderable());

        $this->postJson('/api/v1/store/voice/execute', ['token' => $res->json('token')])->assertOk(); // تكرار آمن
        $this->postJson('/api/v1/store/voice/execute', ['token' => $res->json('token')])->assertOk();
        $this->postJson('/api/v1/store/voice/execute', ['token' => 'x'])->assertStatus(422);
    }

    public function test_section_until_time_and_product_back(): void
    {
        $res = $this->say('وقف قسم المعجنات لين الساعة 23:30، والبيتزا رجعت')->assertOk();
        $this->assertSame(['section', 'product'], array_column($res->json('actions'), 'type'));
        $this->assertSame('23:30', $res->json('actions.0.until'));
        $this->postJson('/api/v1/store/voice/execute', ['token' => $res->json('token')])->assertOk();
        $this->assertNotNull($this->pastry->fresh()->paused_until);
        $this->assertTrue($this->pizza->fresh()->is_available);
    }

    public function test_close_store_always_confirms_even_when_popup_off(): void
    {
        Setting::put('opt.voice.confirm', '0');
        $this->say('سكّر المطعم', true)->assertOk()
            ->assertJsonPath('needs_confirm', true)->assertJsonPath('actions.0.danger', true)->assertJsonMissingPath('executed');
        $this->assertTrue($this->store->fresh()->is_open);

        // بدون تأكيد: أمر عادي يتنفّذ فوراً
        $this->say('الشاورما خلصت', true)->assertOk()
            ->assertJsonPath('needs_confirm', false)->assertJsonPath('executed.message', 'تم ✓')->assertJsonPath('token', null);
        $this->assertFalse($this->shawarmaChicken->fresh()->is_available);
    }

    public function test_order_ready_and_reject(): void
    {
        $o = $this->order('43', OrderStatus::Preparing);
        $res = $this->say('الطلب ٤٣ جاهز')->assertOk()->assertJsonPath('actions.0.label', 'الطلب #43 جاهز');
        $this->postJson('/api/v1/store/voice/execute', ['token' => $res->json('token')])->assertOk()->assertJsonPath('results.0.ok', true);
        $this->assertSame(OrderStatus::Ready, $o->fresh()->status);

        $p = $this->order('44', OrderStatus::Pending);
        $this->say('ارفض الطلب 44 لان المطبخ مزدحم')->assertOk()->assertJsonPath('actions.0.danger', true)
            ->assertJsonPath('actions.0.reason', 'المطبخ مزدحم');

        $this->say('الطلب 99 جاهز')->assertOk()->assertJsonCount(0, 'actions')->assertJsonPath('notes.0', 'ما لقيناش طلب شغّال رقمه 99');
    }

    public function test_cannot_touch_other_stores_and_tokens_are_bound(): void
    {
        $other = User::create(['name' => 'ب', 'phone' => '0911111112', 'role' => UserRole::Store->value, 'is_active' => true]);
        $otherStore = Store::create(['user_id' => $other->id, 'name' => 'ب', 'slug' => 'b', 'is_open' => true, 'is_active' => true, 'lat' => 1, 'lng' => 1]);
        $theirs = $otherStore->products()->create(['name' => 'كسكسي', 'price' => 5, 'is_available' => true, 'is_visible' => true]);

        $this->say('الكسكسي خلص')->assertOk()->assertJsonCount(0, 'actions');

        $token = $this->say('الشاورما خلصت')->json('token');
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/store/voice/execute', ['token' => $token])->assertStatus(422);
        $this->assertTrue($this->shawarmaMeat->fresh()->is_available);
        $this->assertTrue($theirs->fresh()->is_available);
    }

    public function test_ai_mode_validates_ids_and_falls_back(): void
    {
        config(['services.voice_ai.key' => 'test-key']);
        $theirs = Product::create(['store_id' => Store::create(['user_id' => $this->owner->id, 'name' => 'x', 'slug' => 'x', 'lat' => 1, 'lng' => 1])->id,
            'name' => 'غريب', 'price' => 1, 'is_available' => true]);

        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['content' => [['type' => 'text', 'text' => json_encode([
            'actions' => [
                ['type' => 'product', 'id' => $this->shawarmaMeat->id, 'available' => false],
                ['type' => 'product', 'id' => $theirs->id, 'available' => false], // مش للمتجر هذا — ينرمى
                ['type' => 'store', 'open' => false],
            ],
            'unclear' => null,
        ], JSON_UNESCAPED_UNICODE)]]])->push('down', 500)]);

        $res = $this->say('خلاص الشاورما اللحم كملت وسكر المحل')->assertOk()->assertJsonPath('source', 'ai');
        $this->assertSame(['product', 'store'], array_column($res->json('actions'), 'type'));
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'test-key') && str_contains($r['messages'][0]['content'], 'شاورما لحم'));

        // الخدمة طاحت → نرجعو للقواعد
        $this->say('الشاورما خلصت')->assertOk()->assertJsonPath('source', 'rules')->assertJsonCount(2, 'actions');
    }

    public function test_daily_limit(): void
    {
        Setting::put('opt.voice.daily_limit', '10');
        for ($i = 0; $i < 10; $i++) {
            $this->say('البيتزا رجعت')->assertOk();
        }
        $this->say('البيتزا رجعت')->assertStatus(429);
    }

    public function test_options_are_public_for_the_app(): void
    {
        $o = $this->getJson('/api/v1/app/content?app=store')->assertOk()->json('options');
        $this->assertTrue($o['voice.enabled']);
        $this->assertTrue($o['voice.confirm']);
        $this->assertSame('ar-LY', $o['voice.locale']);
        $this->assertArrayNotHasKey('voice.daily_limit', $o);
    }

    public function test_rule_phrases(): void
    {
        $this->order('45', OrderStatus::Pending);
        $cases = [
            'شاورما الدجاج كملت' => ['إيقاف «شاورما دجاج»'],
            'المعجنات مش متوفرة' => ['إيقاف قسم «المعجنات»'],
            'اقبل الطلب 45 في 20 دقيقة' => ['قبول الطلب #45 وبدء التحضير (20 دقيقة)'],
            'اقفل المحل' => ['إغلاق المتجر (ما يستقبلش طلبات)'],
            'كل الأصناف متوفرة' => ['تشغيل «بيتزا»'],
            'البيتزه رجعت' => ['تشغيل «بيتزا»'],
        ];
        foreach ($cases as $text => $labels) {
            $this->assertSame($labels, array_column($this->say($text)->json('actions'), 'label'), $text);
        }
        $this->say('شنو الجو اليوم')->assertOk()->assertJsonCount(0, 'actions')->assertJsonPath('needs_confirm', true);
    }
}
