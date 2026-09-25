<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $owner = User::create([
            'name' => 'صاحب المتجر', 'phone' => '0911111111',
            'role' => UserRole::Store->value, 'is_active' => true,
        ]);

        $this->store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'matam']);

        Sanctum::actingAs($owner);
    }

    private function img(string $name = 'a.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 200, 'image/jpeg');
    }

    private function create(array $images): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/store/products', [
            'name' => 'برجر', 'price' => 15, 'images' => $images,
        ], ['Accept' => 'application/json']);
    }

    public function test_create_with_multiple_images(): void
    {
        $res = $this->create([$this->img('1.jpg'), $this->img('2.jpg'), $this->img('3.jpg')])->assertCreated();

        $this->assertCount(3, $res->json('data.images'));
        $this->assertSame($res->json('data.images.0'), $res->json('data.image'));

        $p = Product::first();
        $this->assertCount(3, $p->images);
        $this->assertSame($p->images[0], $p->image);
        foreach ($p->images as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_update_adds_removes_and_sets_main(): void
    {
        $this->create([$this->img(), $this->img()])->assertCreated();
        $p = Product::first();
        [$first, $second] = $p->images;

        $res = $this->post("/api/v1/store/products/{$p->id}", [
            'images'        => [$this->img('new.jpg')],
            'remove_images' => [asset('storage/'.$first)], // يقبل الرابط الكامل
            'main_image'    => $second,
        ], ['Accept' => 'application/json'])->assertOk();

        $p->refresh();
        $this->assertCount(2, $p->images);
        $this->assertSame($second, $p->images[0]);
        $this->assertSame($second, $p->image);
        Storage::disk('public')->assertMissing($first);
        $this->assertCount(2, $res->json('data.images'));
    }

    public function test_max_images_enforced(): void
    {
        $this->create(array_map(fn ($i) => $this->img("$i.jpg"), range(1, 8)))->assertCreated();
        $p = Product::first();

        $this->post("/api/v1/store/products/{$p->id}", ['images' => [$this->img()]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.images.0', 'أقصى عدد 8 صور للمنتج.');

        $this->assertCount(8, $p->fresh()->images);
    }

    public function test_cannot_remove_images_of_other_paths(): void
    {
        $this->create([$this->img()])->assertCreated();
        $p = Product::first();
        Storage::disk('public')->put('stores/999/secret.jpg', 'x');

        $this->post("/api/v1/store/products/{$p->id}", ['remove_images' => ['stores/999/secret.jpg']], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('public')->assertExists('stores/999/secret.jpg');
        $this->assertCount(1, $p->fresh()->images);
    }

    public function test_legacy_single_image_field_still_works(): void
    {
        $this->post('/api/v1/store/products', ['name' => 'x', 'price' => 5, 'image' => $this->img()], ['Accept' => 'application/json'])
            ->assertCreated();

        $p = Product::first();
        $this->assertCount(1, $p->images);
        $this->assertSame($p->images[0], $p->image);
    }

    public function test_updating_without_images_keeps_them(): void
    {
        $this->create([$this->img(), $this->img()])->assertCreated();
        $p = Product::first();
        $before = $p->images;

        $this->post("/api/v1/store/products/{$p->id}", ['name' => 'جديد'], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($before, $p->fresh()->images);
        $this->assertSame('جديد', $p->fresh()->name);
    }
}
