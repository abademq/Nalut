<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $roles, string $phone = '0911111111', ?string $password = 'secret1'): User
    {
        return User::create(['name' => 'علي', 'phone' => $phone, 'password' => $password ? Hash::make($password) : null,
            'roles' => $roles, 'is_active' => true, 'email' => $phone.'@a.ly']);
    }

    public function test_customer_deletes_account_with_password(): void
    {
        $u = $this->user(['customer']);
        Address::create(['user_id' => $u->id, 'label' => 'البيت', 'details' => 'نالوت', 'lat' => 31.8, 'lng' => 10.9]);
        $token = $u->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me/delete')->assertOk()
            ->assertJsonPath('blockers', [])->assertJsonPath('needs_admin', false)->assertJsonPath('has_password', true);

        $this->withToken($token)->postJson('/api/v1/me/delete', ['password' => 'wrong'])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/me/delete', ['password' => 'secret1', 'reason' => 'ما نحتاجاش'])
            ->assertOk()->assertJsonPath('status', 'deleted');

        $gone = User::withTrashed()->find($u->id);
        $this->assertTrue($gone->trashed());
        $this->assertSame('حساب محذوف', $gone->name);
        $this->assertSame('del-'.$u->id, $gone->phone);
        $this->assertNull($gone->email);
        $this->assertSame(0, Address::where('user_id', $u->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count());

        // السجل ما فيهوش الرقم القديم في التغييرات
        $log = \App\Models\ActivityLog::where('action', 'account.deleted')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('0911111111', json_encode(\App\Models\ActivityLog::where('action', 'like', 'api.me%')->get()->pluck('properties')));

        // الرقم تحرر: تسجيل جديد بنفس الرقم ينجح
        $this->assertFalse(User::where('phone', '0911111111')->exists());
        $this->user(['customer'], '0911111111');
        $this->assertSame(1, User::where('phone', '0911111111')->count());
    }

    public function test_confirm_with_otp_when_no_password(): void
    {
        config(['otp.debug' => true, 'otp.driver' => 'log']);
        $u = $this->user(['customer'], '0912222222', null);
        $token = $u->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/me/delete', [])->assertStatus(422);

        $code = $this->postJson('/api/v1/auth/otp', ['phone' => '0912222222', 'purpose' => 'login'])->json('debug_code');
        $this->assertNotEmpty($code);

        $this->withToken($token)->postJson('/api/v1/me/delete', ['code' => $code])->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertTrue(User::withTrashed()->find($u->id)->trashed());
    }

    public function test_blocked_with_active_order_and_driver_cash(): void
    {
        $owner = $this->user(['store'], '0913333333');
        $store = Store::create(['user_id' => $owner->id, 'name' => 'مطعم', 'slug' => 'm', 'is_open' => true, 'is_active' => true, 'lat' => 31.8, 'lng' => 10.9]);
        $c = $this->user(['customer'], '0914444444');
        Order::create(['code' => 'T1', 'customer_id' => $c->id, 'store_id' => $store->id,
            'status' => OrderStatus::Pending, 'payment_method' => 'cash', 'total' => 12,
            'address_details' => 'x', 'address_lat' => 31.87, 'address_lng' => 10.98, 'customer_phone' => '1']);

        $this->actingAs($c, 'sanctum')->postJson('/api/v1/me/delete', ['password' => 'secret1'])
            ->assertStatus(422)->assertJsonFragment(['عندك 1 طلب شغّال. استنى لين يكمل أو يتلغى.']);
        $this->assertFalse(User::find($c->id)->trashed());

        $d = $this->user(['driver'], '0915555555');
        $d->ensureDriverProfile()->update(['cash_in_hand' => 30]);
        $this->actingAs($d, 'sanctum')->getJson('/api/v1/me/delete')->assertOk()->assertJsonCount(1, 'blockers');
    }

    public function test_store_account_becomes_request_to_admin(): void
    {
        $admin = User::create(['name' => 'Admin', 'phone' => '0910000001', 'email' => 'a@a.ly', 'password' => 'x', 'roles' => ['admin'], 'is_active' => true]);
        $owner = $this->user(['store', 'customer'], '0916666666');

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/me/delete', ['password' => 'secret1'])
            ->assertStatus(202)->assertJsonPath('status', 'requested');

        $this->assertFalse(User::find($owner->id)->trashed());
        $this->assertSame(1, $admin->fresh()->notifications()->count());
    }
}
