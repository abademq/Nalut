<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Merchant\Resources\Products\Pages\EditProduct;
use App\Filament\Merchant\Resources\Products\Pages\ListProducts;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDuplicateSectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private MenuSection $shawarma;

    private MenuSection $offers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::create(['name' => 'م', 'phone' => '0911111111', 'password' => 'secret123', 'role' => UserRole::Store->value, 'is_active' => true]);
        $this->store = Store::create(['user_id' => $this->owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8686, 'lng' => 10.9817]);
        $this->shawarma = MenuSection::create(['store_id' => $this->store->id, 'name' => 'شاورما', 'sort' => 1]);
        $this->offers = MenuSection::create(['store_id' => $this->store->id, 'name' => 'العروض', 'sort' => 0]);
    }

    private function product(): Product
    {
        Storage::disk('public')->put('products/a.jpg', 'img');
        $p = $this->store->products()->create(['name' => 'شاورما دجاج', 'price' => 12, 'is_available' => true,
            'menu_section_id' => $this->shawarma->id, 'images' => ['products/a.jpg'], 'ingredients' => [['name' => 'بصل', 'removable' => true]]]);
        $o = $p->options()->create(['name' => 'الإضافات', 'type' => 'multi', 'max_choices' => 3]);
        $o->values()->create(['name' => 'زيادة صوص', 'extra_price' => 1, 'is_available' => true]);

        return $p;
    }

    public function test_store_app_duplicates_product_with_everything(): void
    {
        $p = $this->product();
        $p->syncExtraSections([$this->offers->id]);
        Sanctum::actingAs($this->owner);

        $res = $this->postJson("/api/v1/store/products/{$p->id}/duplicate")->assertCreated();
        $copy = Product::findOrFail($res->json('data.id'));

        $this->assertSame('شاورما دجاج (نسخة)', $copy->name);
        $this->assertFalse($copy->is_visible);
        $this->assertSame(1, $copy->options()->count());
        $this->assertSame('زيادة صوص', $copy->options()->first()->values()->first()->name);
        $this->assertSame($p->ingredients, $copy->ingredients);
        $this->assertSame([$this->shawarma->id, $this->offers->id], $copy->sectionIds());
        // صورة مستقلة: حذف صورة الأصل ما يأثرش على النسخة
        $this->assertNotSame($p->images[0], $copy->images[0]);
        Storage::disk('public')->assertExists($copy->images[0]);
        // الأصل ما تغيّرش
        $this->assertTrue($p->fresh()->is_visible);
    }

    public function test_cannot_duplicate_other_store_product(): void
    {
        $other = User::create(['name' => 'ث', 'phone' => '0912222222', 'role' => UserRole::Store->value, 'is_active' => true]);
        Store::create(['user_id' => $other->id, 'name' => 'ثاني', 'slug' => 'o', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);
        $p = $this->product();
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/store/products/{$p->id}/duplicate")->assertForbidden();
    }

    public function test_product_shows_in_several_sections(): void
    {
        Sanctum::actingAs($this->owner);
        $foreign = MenuSection::create(['store_id' => Store::create(['user_id' => User::create(['name' => 'x', 'phone' => '0913333333', 'role' => 'store', 'is_active' => true])->id, 'name' => 'x', 'slug' => 'x', 'lat' => 31.8, 'lng' => 10.9])->id, 'name' => 'غريب']);

        $id = $this->post('/api/v1/store/products', ['name' => 'وجبة', 'price' => 20, 'menu_section_id' => $this->shawarma->id,
            'extra_section_ids' => json_encode([$this->offers->id, $foreign->id, $this->shawarma->id])], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        // أقسام متجر ثاني والقسم الأساسي ما يتحطوش كإضافية
        $this->assertSame([$this->shawarma->id, $this->offers->id], Product::find($id)->sectionIds());

        $customer = User::create(['name' => 'ز', 'phone' => '0914444444', 'role' => UserRole::Customer->value, 'is_active' => true]);
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk()
            ->assertJsonPath('data.products.0.section_ids', [$this->shawarma->id, $this->offers->id]);

        // تغيير القسم الأساسي لـ «العروض» يشيله من الإضافية
        Sanctum::actingAs($this->owner);
        $this->post("/api/v1/store/products/$id", ['menu_section_id' => $this->offers->id], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([$this->offers->id], Product::find($id)->sectionIds());

        // تفريغ الإضافية
        $this->post("/api/v1/store/products/$id", ['extra_section_ids' => '[]'], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.section_ids', [$this->offers->id]);
    }

    public function test_merchant_panel_duplicate_and_extra_sections(): void
    {
        $p = $this->product();
        Filament::setCurrentPanel('merchant');
        $this->actingAs($this->owner, 'web');

        Livewire::test(EditProduct::class, ['record' => $p->id])
            ->fillForm(['extraSections' => [$this->offers->id]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame([$this->shawarma->id, $this->offers->id], $p->fresh()->sectionIds());

        Livewire::test(ListProducts::class)->callTableAction('duplicate', $p)->assertRedirect();
        $this->assertSame(2, $this->store->products()->count());
    }
}
