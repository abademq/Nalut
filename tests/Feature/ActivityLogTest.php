<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $roles, string $phone): User
    {
        return User::create(['name' => 'علي', 'phone' => $phone, 'password' => Hash::make('secret1'),
            'roles' => $roles, 'is_active' => true, 'email' => $phone.'@a.ly']);
    }

    public function test_api_action_logged_with_changes_and_without_secrets(): void
    {
        $owner = $this->user(['store'], '0911111111');
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);
        ActivityLog::query()->delete();

        $this->withHeader('X-App', 'store')->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/store/toggle-open')->assertOk();

        $log = ActivityLog::latest('id')->first();
        $this->assertSame('store', $log->app);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertStringContainsString('فتح/إغلاق المتجر', $log->description);
        $this->assertSame(200, $log->status);
        $this->assertSame('is_open', array_key_first($log->properties['changes'][0]['changes']));
        $this->assertSame($store->id, $log->store_id);
        $this->assertSame(1, ActivityLog::count());   // سطر واحد للعملية مش سطر لكل جدول

        // الدخول: كلمة المرور ما تنكتبش
        $this->withHeader('X-App', 'store')->postJson('/api/v1/auth/login', ['phone' => '0911111111', 'password' => 'secret1'])->assertOk();
        $login = ActivityLog::latest('id')->first();
        $this->assertSame($owner->id, $login->user_id);
        $this->assertSame('•••', $login->properties['request']['password']);

        // فشل
        $this->postJson('/api/v1/auth/login', ['phone' => '0911111111', 'password' => 'wrong'])->assertStatus(422);
        $this->assertSame(422, ActivityLog::latest('id')->first()->status);
    }

    public function test_client_events_from_apps(): void
    {
        $c = $this->user(['customer'], '0912222222');

        $this->withHeader('X-App', 'customer')->actingAs($c, 'sanctum')->postJson('/api/v1/activity', ['events' => [
            ['action' => 'cart.add', 'label' => 'بيتزا ×2', 'data' => ['store_id' => 5, 'product_id' => 9, 'qty' => 2], 'at' => now()->subMinute()->valueOf()],
            ['action' => 'store.view', 'label' => 'مطعم الواحة', 'data' => ['store_id' => 5]],
        ]])->assertOk()->assertJsonPath('saved', 2);

        $add = ActivityLog::where('action', 'app.cart.add')->first();
        $this->assertSame('إضافة للسلة: بيتزا ×2', $add->description);
        $this->assertSame('customer', $add->app);
        $this->assertSame(5, $add->store_id);
        // طلب /activity نفسه ما يتسجّلش
        $this->assertSame(0, ActivityLog::where('action', 'like', 'api.%activity%')->count());

        $this->postJson('/api/v1/activity', ['events' => [['action' => 'Bad Action!']]])->assertStatus(422);
    }

    public function test_admin_changes_logged_as_separate_rows_and_page_renders(): void
    {
        $admin = User::create(['name' => 'Admin', 'phone' => '0910000001', 'email' => 'a@a.ly', 'password' => 'x', 'roles' => ['admin'], 'is_active' => true]);
        $owner = $this->user(['store'], '0913333333');
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm3', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);
        $p = Product::create(['store_id' => $store->id, 'name' => 'بيتزا', 'price' => 20, 'is_available' => true]);

        $this->actingAs($admin);
        $p->update(['price' => 25]);

        $log = ActivityLog::where('action', 'model.updated')->latest('id')->first();
        $this->assertSame('admin', $log->app);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertEquals([20, 25], array_map('floatval', $log->properties['changes']['price']));
        $this->assertSame($store->id, $log->store_id);

        $this->get('/admin/activity')->assertOk()->assertSee('سجل النشاط')->assertSee('بيتزا');
        $this->get('/admin/activity?filters[user_id][value]='.$admin->id)->assertOk();

        \Livewire\Livewire::test(\App\Filament\Resources\ActivityLogs\ListActivityLogs::class)
            ->mountTableAction('details', $log)
            ->assertHasNoErrors();

        $html = view('filament.activity-details', ['log' => $log])->render();
        $this->assertStringContainsString('التغييرات', $html);
        $this->assertStringContainsString('السعر', $html);

        // سطر من التطبيق (قائمة جداول)
        $api = ActivityLog::create(['app' => 'store', 'action' => 'api.x', 'description' => 'd', 'status' => 422,
            'properties' => ['changes' => [['model' => 'منتج', 'id' => 1, 'event' => 'updated', 'changes' => ['is_available' => [true, false]]]],
                'request' => ['name' => 'x', 'password' => '•••'], 'errors' => ['name' => ['غلط']]]]);
        $html = view('filament.activity-details', ['log' => $api])->render();
        $this->assertStringContainsString('تعديل', $html);
        $this->assertStringContainsString('غلط', $html);
    }

    public function test_limited_admin_needs_permission_and_prune_works(): void
    {
        $limited = User::create(['name' => 'L', 'phone' => '0910000002', 'email' => 'l@a.ly', 'password' => 'x',
            'roles' => ['admin'], 'permissions' => ['orders.view'], 'is_active' => true]);
        $this->actingAs($limited)->get('/admin/activity')->assertForbidden();

        ActivityLog::create(['app' => 'system', 'action' => 'x', 'description' => 'old', 'created_at' => now()->subDays(200)]);
        ActivityLog::create(['app' => 'system', 'action' => 'x', 'description' => 'new']);
        $this->artisan('activity:prune')->assertSuccessful();
        $this->assertSame(0, ActivityLog::where('description', 'old')->count());
        $this->assertSame(1, ActivityLog::where('description', 'new')->count());
    }
}
