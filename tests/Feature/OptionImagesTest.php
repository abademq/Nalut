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

class OptionImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::create(['name' => 'م', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'ملابس', 'slug' => 'c', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);
        $this->product = $this->store->products()->create(['name' => 'تيشيرت', 'price' => 30, 'is_available' => true]);
        Sanctum::actingAs($this->owner);
    }

    private function upload(): string
    {
        return $this->post('/api/v1/store/option-images', ['image' => UploadedFile::fake()->image('red.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->json('path');
    }

    private function sync(array $values)
    {
        return $this->putJson("/api/v1/store/products/{$this->product->id}/options", ['options' => [
            ['name' => 'اللون', 'type' => 'single', 'is_required' => true, 'values' => $values],
        ]]);
    }

    public function test_store_uploads_color_images_and_customer_sees_them(): void
    {
        $red = $this->upload();
        $this->assertStringStartsWith("options/{$this->store->id}/", $red);

        $res = $this->sync([['name' => 'أحمر', 'image' => $red], ['name' => 'أسود']])->assertOk();
        $this->assertStringEndsWith($red, $res->json('data.options.0.values.0.image'));
        $this->assertNull($res->json('data.options.0.values.1.image'));

        // إعادة الحفظ بالرابط الحالي (زي ما يرجعه التطبيق) تخلّيها
        $values = $res->json('data.options.0.values');
        $this->putJson("/api/v1/store/products/{$this->product->id}/options", ['options' => [
            ['id' => $res->json('data.options.0.id'), 'name' => 'اللون', 'type' => 'single', 'values' => [
                ['id' => $values[0]['id'], 'name' => 'أحمر', 'image' => $values[0]['image']],
                ['id' => $values[1]['id'], 'name' => 'أسود', 'image' => null],
            ]],
        ]])->assertOk()->assertJsonPath('data.options.0.values.0.image', $values[0]['image']);

        // الزبون
        Sanctum::actingAs(User::create(['name' => 'ز', 'phone' => '0914444444', 'role' => UserRole::Customer->value, 'is_active' => true]));
        $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk()
            ->assertJsonPath('data.products.0.options.0.values.0.image', $values[0]['image']);
    }

    public function test_foreign_or_fake_paths_are_rejected(): void
    {
        Storage::disk('public')->put('options/999/x.jpg', 'x');
        Storage::disk('public')->put('products/y.jpg', 'x');

        foreach (['options/999/x.jpg', 'products/y.jpg', "options/{$this->store->id}/missing.jpg", "options/{$this->store->id}/../../products/y.jpg"] as $bad) {
            $this->sync([['name' => 'أحمر', 'image' => $bad]])->assertStatus(422);
        }
    }

    public function test_remove_image_and_duplicate_copies_it(): void
    {
        $red = $this->upload();
        $this->sync([['name' => 'أحمر', 'image' => $red]])->assertOk();

        $copy = $this->product->fresh()->duplicate();
        $copyImg = $copy->options->first()->values->first()->image;
        $this->assertNotSame($red, $copyImg);
        Storage::disk('public')->assertExists($copyImg);

        $v = $this->product->options()->first()->values()->first();
        $this->putJson("/api/v1/store/products/{$this->product->id}/options", ['options' => [
            ['id' => $v->product_option_id, 'name' => 'اللون', 'type' => 'single', 'values' => [['id' => $v->id, 'name' => 'أحمر', 'image' => '']]],
        ]])->assertOk();
        $this->assertNull($v->fresh()->image);
    }
}
