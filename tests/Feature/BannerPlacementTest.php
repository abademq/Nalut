<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Models\AppSection;
use App\Models\Banner;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class BannerPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_banners_are_split_by_placement(): void
    {
        Http::fake();
        $owner = User::create(['name' => 'م', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        $other = Store::create(['user_id' => $owner->id, 'name' => 'ثاني', 'slug' => 'o', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        $section = AppSection::create(['name' => 'مطاعم', 'is_active' => true]);

        Banner::create(['title' => 'رئيسية', 'is_active' => true]);
        Banner::create(['title' => 'الكل', 'placement' => 'everywhere', 'is_active' => true]);
        Banner::create(['title' => 'قسم', 'placement' => 'section', 'app_section_id' => $section->id, 'is_active' => true]);
        Banner::create(['title' => 'متجر', 'placement' => 'store', 'show_store_id' => $store->id, 'is_active' => true]);
        Banner::create(['title' => 'سلة', 'placement' => 'cart', 'is_active' => true]);

        $res = $this->getJson('/api/v1/app/content')->assertOk();
        // النسخ القديمة: الرئيسية بس
        $this->assertEqualsCanonicalizing(['رئيسية', 'الكل'], collect($res->json('banners'))->pluck('title')->all());
        // الجديدة: الكل ما عدا إعلانات صفحات المتاجر
        $all = collect($res->json('all_banners'));
        $this->assertEqualsCanonicalizing(['رئيسية', 'الكل', 'قسم', 'سلة'], $all->pluck('title')->all());
        $this->assertSame($section->id, $all->firstWhere('title', 'قسم')['section_id']);
        $this->assertTrue($res->json('options')['banners.section_fallback'] ?? $res->json('options.banners\.section_fallback') ?? true);

        Sanctum::actingAs(User::create(['name' => 'ز', 'phone' => '0922222222', 'role' => UserRole::Customer->value, 'is_active' => true]));
        $this->assertSame(['متجر'], collect($this->getJson("/api/v1/stores/{$store->id}")->assertOk()->json('banners'))->pluck('title')->all());
        $this->assertSame([], $this->getJson("/api/v1/stores/{$other->id}")->assertOk()->json('banners'));
    }

    public function test_admin_can_create_section_banner(): void
    {
        $admin = User::create(['name' => 'a', 'phone' => '0900000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin);
        $section = AppSection::create(['name' => 'مطاعم', 'is_active' => true]);

        Livewire::test(CreateBanner::class)
            ->fillForm(['title' => 'عرض', 'placement' => 'section', 'app_section_id' => $section->id, 'color' => '#D84315'])
            ->call('create')->assertHasNoFormErrors();

        $b = Banner::firstWhere('title', 'عرض');
        $this->assertSame('section', $b->placement);
        $this->assertSame('قسم: مطاعم', $b->placementLabel());

        Livewire::test(ListBanners::class)->assertOk()->assertSee('قسم: مطاعم');
    }
}
