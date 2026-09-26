<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(?array $perms): User
    {
        $phone = '091'.random_int(1000000, 9999999);

        return User::create(['name' => 'Admin', 'phone' => $phone, 'email' => $phone.'@a.ly', 'password' => bcrypt('x'),
            'role' => UserRole::Admin->value, 'is_active' => true, 'permissions' => $perms]);
    }

    public function test_empty_permissions_mean_full_access(): void
    {
        $u = $this->admin([]);
        $this->assertTrue($u->fresh()->hasPermission('orders.view'));
        $this->actingAs($u)->get('/admin/orders')->assertOk();
    }

    public function test_limited_admin_is_still_limited(): void
    {
        $u = $this->admin(['orders.view']);
        $this->assertTrue($u->hasPermission('orders.view'));
        $this->assertFalse($u->hasPermission('finance.manage'));
    }
}
