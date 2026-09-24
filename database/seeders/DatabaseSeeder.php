<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\DeliveryZone;
use App\Models\DriverProfile;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DeliveryZone::create([
            'name' => 'نالوت المركز', 'base_fee' => 5, 'fee_per_km' => 1.5,
            'center_lat' => 31.8686, 'center_lng' => 10.9817, 'radius_km' => 15,
        ]);

        $types = collect(['مطاعم', 'مقاهي وحلويات', 'بقالة', 'صيدليات'])
            ->map(fn ($name, $i) => StoreType::create(['name' => $name, 'sort' => $i]));

        User::create([
            'name' => 'مدير النظام', 'phone' => '0910000000',
            'password' => 'password', 'role' => UserRole::Admin->value,
            'phone_verified_at' => now(),
        ]);

        $owner = User::create([
            'name' => 'صاحب المطعم', 'phone' => '0911111111',
            'role' => UserRole::Store->value, 'phone_verified_at' => now(),
        ]);

        $store = Store::create([
            'user_id' => $owner->id,
            'store_type_id' => $types->first()->id,
            'name' => 'مطعم نالوت',
            'slug' => Str::slug('matam-nalut'),
            'phone' => '0911111111',
            'address' => 'شارع الجمهورية، نالوت',
            'lat' => 31.8686, 'lng' => 10.9817,
            'commission_percent' => 15,
            'prep_time_minutes' => 20,
        ]);

        $section = MenuSection::create(['store_id' => $store->id, 'name' => 'الوجبات الرئيسية']);

        foreach ([['برجر لحم', 25], ['شاورما دجاج', 18], ['بيتزا خضار', 30]] as [$name, $price]) {
            Product::create([
                'store_id' => $store->id,
                'menu_section_id' => $section->id,
                'name' => $name,
                'price' => $price,
            ]);
        }

        $driver = User::create([
            'name' => 'سائق تجريبي', 'phone' => '0912222222',
            'role' => UserRole::Driver->value, 'phone_verified_at' => now(),
        ]);

        DriverProfile::create([
            'user_id' => $driver->id, 'vehicle_type' => 'motorcycle',
            'plate_number' => '12-3456', 'is_approved' => true,
        ]);

        User::create([
            'name' => 'زبون تجريبي', 'phone' => '0913333333',
            'role' => UserRole::Customer->value, 'phone_verified_at' => now(),
        ]);
    }
}
