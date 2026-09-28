<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Banner;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\BlurHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlurHashTest extends TestCase
{
    use RefreshDatabase;

    private function png(int $r = 200, int $g = 60, int $b = 30): string
    {
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    public function test_encoder_output_format(): void
    {
        $h = BlurHash::fromBytes($this->png());
        // 4×3 مكوّنات = 4 + 2×12 = 28 حرف، وأول حرف L
        $this->assertSame(28, strlen($h));
        $this->assertSame('L', $h[0]);
        $this->assertNull(BlurHash::fromBytes('not an image'));
    }

    public function test_hashes_are_computed_on_upload_and_sent_to_apps(): void
    {
        Storage::fake('public');
        $owner = User::create(['name' => 'م', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);
        Sanctum::actingAs($owner);

        $res = $this->post('/api/v1/store/products', ['name' => 'برجر', 'price' => 10,
            'images' => [UploadedFile::fake()->createWithContent('a.png', $this->png()), UploadedFile::fake()->createWithContent('b.png', $this->png(10, 10, 200))],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(28, strlen($res->json('data.blurhash')));
        $this->assertCount(2, $res->json('data.image_hashes'));
        $this->assertNotSame($res->json('data.image_hashes.0'), $res->json('data.image_hashes.1'));

        // الشعار والإعلان
        Storage::disk('public')->put('stores/logo.png', $this->png());
        $store->update(['logo' => 'stores/logo.png']);
        $this->assertNotNull($store->fresh()->logo_hash);
        Storage::disk('public')->put('banners/x.png', $this->png());
        $banner = Banner::create(['title' => 'ع', 'image' => 'banners/x.png', 'is_active' => true]);
        $this->assertNotNull($banner->toApp()['blurhash']);

        // الزبون يوصله
        Sanctum::actingAs(User::create(['name' => 'ز', 'phone' => '0914444444', 'role' => UserRole::Customer->value, 'is_active' => true]));
        $this->getJson("/api/v1/stores/{$store->id}")->assertOk()
            ->assertJsonPath('data.logo_blurhash', $store->fresh()->logo_hash)
            ->assertJsonPath('data.products.0.blurhash', $res->json('data.blurhash'));
    }

    public function test_backfill_command_fills_old_images(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/old.png', $this->png());
        $owner = User::create(['name' => 'م', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $store = Store::create(['user_id' => $owner->id, 'name' => 's', 'slug' => 's', 'lat' => 31.8, 'lng' => 10.9]);
        $p = $store->products()->create(['name' => 'قديم', 'price' => 1, 'images' => ['products/old.png']]);
        DB::table('products')->where('id', $p->id)->update(['image_hashes' => null]);

        $this->artisan('images:blurhash')->assertSuccessful();
        $this->assertNotNull(Product::find($p->id)->blurHashFor('products/old.png'));
    }
}
