<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class CouponAdminTest extends TestCase {
    use RefreshDatabase;
    public function test_create_all_coupon_types(): void {
        $a = User::create(['name'=>'a','phone'=>'0910000000','role'=>UserRole::Admin->value,'is_active'=>true]);
        $this->actingAs($a,'web');
        $r = $this->get(\App\Filament\Resources\Coupons\CouponResource::getUrl('create'));
        $r->assertOk();
        foreach (['percent', 'fixed', 'free_delivery'] as $i => $type) {
            \Livewire\Livewire::test(\App\Filament\Resources\Coupons\Pages\CreateCoupon::class)
                ->fillForm(['code' => 'C'.$i, 'type' => $type, 'value' => 10, 'min_order' => 0, 'per_user_limit' => 1])
                ->call('create')->assertHasNoFormErrors();
        }
        $this->assertSame(3, \App\Models\Coupon::count());
        $this->assertEquals(0, \App\Models\Coupon::where('type', 'free_delivery')->first()->value);
    }

    public function test_driver_capacity_from_drivers_page(): void
    {
        $a = User::create(['name' => 'a', 'phone' => '0910000000', 'role' => UserRole::Admin->value, 'is_active' => true]);
        $d = User::create(['name' => 'س', 'phone' => '0915000001', 'role' => UserRole::Driver->value, 'is_active' => true]);
        $d->driverProfile()->create(['is_approved' => true]);
        $this->actingAs($a, 'web');

        $this->get(\App\Filament\Resources\Drivers\DriverResource::getUrl())->assertOk()->assertSee('طلب واحد');

        \Livewire\Livewire::test(\App\Filament\Resources\Drivers\Pages\ListDrivers::class)
            ->callTableAction('driverSettings', $d, ['multi_order_mode' => 'same_store', 'max_active_orders' => 3])
            ->assertHasNoTableActionErrors();

        $p = $d->driverProfile->fresh();
        $this->assertSame('same_store', $p->multi_order_mode);
        $this->assertSame(3, $p->max_active_orders);
    }
}
