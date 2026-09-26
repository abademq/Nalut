<?php

namespace Tests\Feature;

use App\Filament\Pages\ReceiptDesigner;
use App\Models\Setting;
use App\Models\User;
use App\Support\ReceiptLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptDesignerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'phone' => '0910000001', 'email' => 'a@a.ly', 'password' => 'x',
            'roles' => ['admin'], 'is_active' => true]);
    }

    public function test_default_layout_keeps_old_settings(): void
    {
        Setting::put('receipt.store.title', 'نسخة المطبخ');
        Setting::put('receipt.store.show_prices', '0');

        $l = ReceiptLayout::get('store');
        $types = array_column($l['blocks'], 'type');

        $this->assertContains('items', $types);
        $this->assertNotContains('total', $types);                       // الأسعار كانت مطفية
        $title = collect($l['blocks'])->firstWhere('type', 'copy_title');
        $this->assertSame('نسخة المطبخ', $title['text']);
    }

    public function test_sanitize_drops_unknown_and_clamps(): void
    {
        $clean = ReceiptLayout::sanitize(['page' => ['padding_x' => 999], 'blocks' => [
            ['type' => 'hack', 'size' => 10],
            ['type' => 'total', 'size' => 500, 'align' => 'weird', 'label' => str_repeat('x', 200)],
        ]]);

        $this->assertSame(60, $clean['page']['padding_x']);
        $this->assertCount(1, $clean['blocks']);
        $this->assertSame(72, $clean['blocks'][0]['size']);
        $this->assertSame('start', $clean['blocks'][0]['align']);
        $this->assertSame(60, mb_strlen($clean['blocks'][0]['label']));
    }

    public function test_designer_page_saves_and_app_gets_layout(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin/receipt-designer')->assertOk()->assertSee('مصمم الواصل');
        $this->get('/admin/branding-settings')->assertOk();

        $layout = ['page' => ['padding_x' => 4, 'base_size' => 18], 'blocks' => [
            ['type' => 'order_code', 'size' => 40, 'bold' => true, 'inverted' => true],
            ['type' => 'store_name', 'align' => 'start'],
            ['type' => 'items', 'qty_format' => 'x{qty}'],
            ['type' => 'text', 'text' => 'شكراً {customer}'],
        ]];

        Livewire::test(ReceiptDesigner::class)->call('saveLayout', 'customer', $layout)->assertHasNoErrors();

        $res = $this->getJson('/api/v1/app/content?app=store')->assertOk();
        $this->assertSame('order_code', $res->json('receipt.layouts.customer.blocks.0.type'));
        $this->assertTrue($res->json('receipt.layouts.customer.blocks.0.inverted'));
        $this->assertSame('x{qty}', $res->json('receipt.layouts.customer.blocks.2.qty_format'));
        $this->assertSame(18, $res->json('receipt.layouts.customer.page.base_size'));
        // نسخة المتجر ما تأثرتش
        $this->assertNotSame('order_code', $res->json('receipt.layouts.store.blocks.0.type'));

        Livewire::test(ReceiptDesigner::class)->call('resetLayout', 'customer');
        $this->assertNull(Setting::get('receipt.layout.customer'));
    }

    public function test_non_settings_admin_cannot_save(): void
    {
        $limited = User::create(['name' => 'L', 'phone' => '0910000002', 'email' => 'l@a.ly', 'password' => 'x',
            'roles' => ['admin'], 'permissions' => ['orders.view'], 'is_active' => true]);
        $this->actingAs($limited);

        $this->get('/admin/receipt-designer')->assertForbidden();
    }
}
