<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RebrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_default_texts_are_renamed_but_custom_ones_kept(): void
    {
        Setting::put('about.name', 'توصيل نالوت');
        Setting::put('receipt.header', 'مطعمي الخاص');

        $migration = require database_path('migrations/2026_10_20_000001_rebrand_azanx.php');
        $migration->up();

        $this->assertSame('ازانكس', Setting::get('about.name'));
        $this->assertSame('مطعمي الخاص', Setting::get('receipt.header'));
    }

    public function test_brand_assets_and_panel_logo(): void
    {
        foreach (['azanx-logo.svg', 'azanx-logo-white.svg', 'azanx-mark.svg', 'favicon.png'] as $f) {
            $this->assertFileExists(public_path('brand/'.$f));
        }
        $this->get('/admin/login')->assertOk()->assertSee('brand/azanx-logo.svg', false);
    }
}
