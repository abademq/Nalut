<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\TicketCategories\Pages\ManageTicketCategories;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\SupportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('public');
        $this->customer = User::create(['name' => 'أيوب', 'phone' => '0913333333', 'role' => UserRole::Customer->value, 'is_active' => true]);
        $this->admin = User::create(['name' => 'مدير', 'phone' => '0900000000', 'email' => 'a@a.ly', 'password' => 'x', 'role' => UserRole::Admin->value, 'is_active' => true]);
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    public function test_customer_opens_ticket_and_chats_with_admin(): void
    {
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/support/categories')->assertOk()->assertJsonPath('enabled', true)
            ->assertJsonPath('categories.0.key', 'order');

        $res = $this->withHeader('X-App', 'customer')->postJson('/api/v1/support/tickets', [
            'category' => 'app', 'body' => 'التطبيق يسكّر لحاله لما نفتح السلة', 'image' => $this->png(),
        ])->assertCreated();
        $id = $res->json('data.id');
        $t = Ticket::findOrFail($id);
        $this->assertSame('TK'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), $t->code);
        $this->assertTrue($t->admin_unread);
        $this->assertNotNull($res->json('data.messages.0.image'));
        Storage::disk('public')->assertExists($t->messages()->first()->image);

        // الإدارة: تشوفها وترد
        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel('admin');
        Livewire::test(ListTickets::class)->assertOk()->assertCanSeeTableRecords([$t]);
        Livewire::test(ViewTicket::class, ['record' => $id])->assertOk()
            ->assertSee('التطبيق يسكّر')
            ->set('reply.body', 'حدّث التطبيق من المتجر وقولنا')
            ->call('send');
        $t->refresh();
        $this->assertSame('answered', $t->status);
        $this->assertFalse($t->admin_unread);
        $this->assertSame(1, $t->user_unread);

        // الزبون يشوف الرد (باسم الدعم الفني بس) وينقرا
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/support/tickets')->assertJsonPath('unread', 1);
        $this->getJson("/api/v1/support/tickets/$id")->assertOk()
            ->assertJsonPath('data.messages.1.from', 'staff')
            ->assertJsonPath('data.messages.1.sender', 'الدعم الفني');
        $this->assertSame(0, $t->fresh()->user_unread);

        // يرد ← ترجع مفتوحة للإدارة
        $this->postJson("/api/v1/support/tickets/$id/messages", ['body' => 'تمام خدم'])->assertCreated();
        $this->assertSame('open', $t->fresh()->status);
        $this->assertTrue($t->fresh()->admin_unread);

        $this->postJson("/api/v1/support/tickets/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_privacy_and_limits(): void
    {
        $other = User::create(['name' => 'ثاني', 'phone' => '0915555555', 'role' => UserRole::Customer->value, 'is_active' => true]);
        Sanctum::actingAs($this->customer);
        $id = $this->postJson('/api/v1/support/tickets', ['category' => 'other', 'body' => 'سؤال عام'])->assertCreated()->json('data.id');

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/support/tickets/$id")->assertNotFound();
        $this->postJson("/api/v1/support/tickets/$id/messages", ['body' => 'x'])->assertNotFound();

        // طلب مش تابعله
        $owner = User::create(['name' => 'م', 'phone' => '0911111111', 'role' => 'store', 'is_active' => true]);
        $store = Store::create(['user_id' => $owner->id, 'name' => 's', 'slug' => 's', 'lat' => 31.8, 'lng' => 10.9]);
        $order = Order::create(['code' => 'Z1', 'customer_id' => $this->customer->id, 'store_id' => $store->id, 'status' => 'pending',
            'address_details' => 'x', 'address_lat' => 31.8, 'address_lng' => 10.9, 'customer_phone' => '1', 'total' => 5, 'subtotal' => 5]);
        $this->postJson('/api/v1/support/tickets', ['category' => 'order', 'body' => 'مشكلة طلب', 'order_id' => $order->id])->assertStatus(422);
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/support/tickets', ['category' => 'order', 'body' => 'مشكلة طلب', 'order_id' => $order->id])
            ->assertCreated()->assertJsonPath('data.order_code', 'Z1');

        // الحد: 3 مفتوحة
        $this->postJson('/api/v1/support/tickets', ['category' => 'other', 'body' => 'ثالثة'])->assertCreated();
        $this->postJson('/api/v1/support/tickets', ['category' => 'other', 'body' => 'رابعة'])->assertStatus(422);

        // مش صورة
        Setting::put('opt.support.max_open', '10');
        $this->postJson('/api/v1/support/tickets', ['category' => 'other', 'body' => 'ملف', 'image' => base64_encode('not an image')])->assertStatus(422);

        // أكثر من 5 تذاكر في 10 دقايق = تمهّل
        $this->postJson('/api/v1/support/tickets', ['category' => 'other', 'body' => 'كثير'])->assertStatus(429);
    }

    public function test_disabled_support(): void
    {
        Setting::put('opt.support.enabled', '0');
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/support/categories')->assertJsonPath('enabled', false);
        $this->postJson('/api/v1/support/tickets', ['category' => 'other', 'body' => 'موقوف'])->assertStatus(422);
    }

    public function test_driver_sees_only_driver_tickets_and_auto_close(): void
    {
        $this->customer->update(['roles' => ['customer', 'driver']]);
        Sanctum::actingAs($this->customer);
        $this->withHeader('X-App', 'driver')->postJson('/api/v1/support/tickets', ['category' => 'earnings', 'body' => 'أرباحي ناقصة'])->assertCreated();
        $this->withHeader('X-App', 'customer')->getJson('/api/v1/support/tickets')->assertJsonCount(0, 'data');
        $this->withHeader('X-App', 'driver')->getJson('/api/v1/support/tickets')->assertJsonCount(1, 'data');

        $t = Ticket::first();
        $t->update(['status' => 'answered', 'last_message_at' => now()->subDays(8)]);
        app(SupportService::class)->autoClose();
        $this->assertSame('closed', $t->fresh()->status);
    }

    public function test_operator_without_permission_cannot_see_tickets(): void
    {
        $op = User::create(['name' => 'ع', 'phone' => '0916666666', 'email' => 'o@a.ly', 'password' => 'x', 'role' => UserRole::Admin->value,
            'is_active' => true, 'permissions' => ['orders.view']]);
        $this->actingAs($op, 'web');
        $this->get('/admin/tickets')->assertForbidden();
    }

    public function test_admin_edits_categories_and_apps_follow(): void
    {
        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ManageTicketCategories::class)->assertOk()
            ->callAction('create', ['app' => 'customer', 'label' => 'تأخير في التوصيل', 'is_active' => true])
            ->assertHasNoActionErrors();
        $new = TicketCategory::firstWhere('label', 'تأخير في التوصيل');
        $this->assertNotEmpty($new->key);

        // نوقفو «اقتراح» ونغيّرو اسم «شي آخر»
        TicketCategory::where('app', 'customer')->where('key', 'suggestion')->update(['is_active' => false]);

        Sanctum::actingAs($this->customer);
        $keys = collect($this->getJson('/api/v1/support/categories')->json('categories'))->pluck('key');
        $this->assertTrue($keys->contains($new->key));
        $this->assertFalse($keys->contains('suggestion'));
        $this->postJson('/api/v1/support/tickets', ['category' => 'suggestion', 'body' => 'موقوف'])->assertStatus(422);

        $id = $this->postJson('/api/v1/support/tickets', ['category' => 'other', 'body' => 'سؤال'])->assertCreated()->json('data.id');
        TicketCategory::where('app', 'customer')->where('key', 'other')->update(['label' => 'أسئلة عامة']);
        $this->getJson("/api/v1/support/tickets/$id")->assertJsonPath('data.category_label', 'أسئلة عامة');

        // عليه تذاكر: ما ينحذفش
        $this->actingAs($this->admin, 'web');
        $other = TicketCategory::where('app', 'customer')->where('key', 'other')->first();
        Livewire::test(ManageTicketCategories::class)
            ->callTableAction('delete', $other);
        $this->assertNotNull($other->fresh());
        Livewire::test(ManageTicketCategories::class)
            ->callTableAction('delete', $new);
        $this->assertNull($new->fresh());
    }
}
