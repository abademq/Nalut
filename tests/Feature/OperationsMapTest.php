<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeliveryZone;
use App\Models\DriverProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsMapTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'phone' => '0910000009', 'email' => 'a@a.ly', 'password' => bcrypt('x'),
            'role' => UserRole::Admin->value, 'is_active' => true, 'permissions' => ['orders.view']]);
    }

    private function driver(string $phone, bool $online): User
    {
        $u = User::create(['name' => 'سائق '.$phone, 'phone' => $phone, 'role' => UserRole::Driver->value, 'is_active' => true]);
        DriverProfile::create(['user_id' => $u->id, 'is_approved' => true, 'is_online' => $online]);

        return $u;
    }

    public function test_map_shows_live_and_last_known_drivers_stores_and_zones(): void
    {
        $zone = DeliveryZone::create(['name' => 'وسط نالوت', 'center_lat' => 31.87, 'center_lng' => 10.98, 'radius_km' => 3, 'is_active' => true]);
        $owner = User::create(['name' => 'متجر', 'phone' => '0911111111', 'role' => UserRole::Store->value, 'is_active' => true]);
        Store::create(['user_id' => $owner->id, 'name' => '<b>مطعم</b>', 'slug' => 'm', 'is_open' => true, 'is_active' => true,
            'lat' => 31.868, 'lng' => 10.981, 'delivery_zone_id' => $zone->id]);
        Store::create(['user_id' => $owner->id, 'name' => 'بدون موقع', 'slug' => 'n', 'is_open' => true, 'is_active' => true]);

        $live = $this->driver('0921111111', true);
        DriverLocationService::put($live->id, 31.86, 10.97);

        // طفّى من ساعتين — يطلع في آخر موقع
        $off = $this->driver('0922222222', false);
        DriverProfile::where('user_id', $off->id)->update(['current_lat' => 31.85, 'current_lng' => 10.96, 'location_updated_at' => now()->subHours(2)]);

        // آخر موقع من يومين — أقدم من 24 ساعة، ما يطلعش
        $old = $this->driver('0923333333', false);
        DriverProfile::where('user_id', $old->id)->update(['current_lat' => 31.84, 'current_lng' => 10.95, 'location_updated_at' => now()->subDays(2)]);

        $this->actingAs($this->admin());
        $res = $this->getJson('/admin-api/drivers-map')->assertOk();

        $drivers = collect($res->json('drivers'))->keyBy('id');
        $this->assertSame('online', $drivers[$live->id]['state']);
        $this->assertSame('offline', $drivers[$off->id]['state']);
        $this->assertEquals(31.85, $drivers[$off->id]['lat']);
        $this->assertFalse($drivers->has($old->id));
        $this->assertSame(1, $res->json('counts.online'));
        $this->assertSame(2, $res->json('counts.offline'));

        $this->assertCount(1, $res->json('stores'));
        $this->assertSame('وسط نالوت', $res->json('stores.0.zone'));
        $this->assertTrue($res->json('stores.0.open'));
        $this->assertSame(1, $res->json('counts.stores_open'));

        $this->assertCount(1, $res->json('zones'));
        $this->assertEquals(3, $res->json('zones.0.radius_km'));

        // الصفحة نفسها تنفتح
        $this->get('/admin/drivers-map')->assertOk()->assertSee('خريطة العمليات');
    }

    public function test_last_seen_can_be_turned_off(): void
    {
        \App\Models\Setting::put('opt.tracking.map_last_seen_hours', '0');
        Cache::flush();

        $off = $this->driver('0922222222', false);
        DriverProfile::where('user_id', $off->id)->update(['current_lat' => 31.85, 'current_lng' => 10.96, 'location_updated_at' => now()->subMinutes(10)]);

        $this->actingAs($this->admin());
        $this->getJson('/admin-api/drivers-map')->assertOk()->assertJsonCount(0, 'drivers');
    }

    public function test_location_updates_persist_last_known_position_throttled(): void
    {
        $d = $this->driver('0921111111', true);

        DriverLocationService::put($d->id, 31.1, 10.1);
        DriverLocationService::put($d->id, 31.2, 10.2); // خلال الدقيقة — ما يتكتبش

        $p = DriverProfile::where('user_id', $d->id)->first();
        $this->assertEquals(31.1, $p->current_lat);
        $this->assertNotNull($p->location_updated_at);

        Cache::forget("driver:loc:saved:{$d->id}");
        DriverLocationService::put($d->id, 31.3, 10.3);
        $this->assertEquals(31.3, $p->fresh()->current_lat);
    }
}
