<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\AppLinks;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppLinksTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::create(['name' => 'م', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم نالوت', 'slug' => 'm', 'is_active' => true, 'cover' => 'stores/c.jpg']);
    }

    public function test_assetlinks_lists_package_and_fingerprints(): void
    {
        $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);

        config(['applinks.android_sha256' => ['AA:BB'], 'applinks.android_package' => 'ly.nalut.customer']);
        $this->getJson('/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.package_name', 'ly.nalut.customer')
            ->assertJsonPath('0.target.sha256_cert_fingerprints.0', 'AA:BB');
    }

    public function test_store_and_product_pages_with_preview_and_app_intent(): void
    {
        $p = $this->store->products()->create(['name' => 'برجر', 'price' => 12, 'is_available' => true]);

        $this->get("/s/{$this->store->id}")->assertOk()
            ->assertSee('og:title', false)->assertSee('مطعم نالوت')
            ->assertSee('storage/stores/c.jpg', false)
            ->assertSee("intent://s/{$this->store->id}#Intent;scheme=nalut", false);

        $this->get("/s/{$this->store->id}/p/{$p->id}")->assertOk()
            ->assertSee('برجر')->assertSee('12.00 د.ل')
            ->assertSee("intent://s/{$this->store->id}/p/{$p->id}#Intent", false);

        $this->get('/go/wallet')->assertOk();
        $this->get('/go/nothing')->assertNotFound();
    }

    public function test_product_of_other_store_or_inactive_store_404(): void
    {
        $other = Store::create(['user_id' => $this->store->user_id, 'name' => 'ثاني', 'slug' => 'x', 'is_active' => true]);
        $p = $other->products()->create(['name' => 'x', 'price' => 1]);

        $this->get("/s/{$this->store->id}/p/{$p->id}")->assertNotFound();

        $this->store->update(['is_active' => false]);
        $this->get("/s/{$this->store->id}")->assertNotFound();
    }

    public function test_admin_page_and_share_actions(): void
    {
        $admin = User::create(['name' => 'a', 'phone' => '0910000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web');

        $this->get(AppLinks::getUrl())->assertOk()->assertSee('روابط الشاشات');
        \Livewire\Livewire::test(AppLinks::class)->assertSet('data.wallet', url('/go/wallet'));

        \Livewire\Livewire::test(\App\Filament\Resources\Stores\Pages\ListStores::class)
            ->mountTableAction('shareLink', $this->store)
            ->assertTableActionDataSet(['url' => url("/s/{$this->store->id}")]);
    }
}
