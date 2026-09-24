<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 20)->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('role', 20)->default('customer')->index();
            $table->boolean('is_active')->default(true);
            $table->string('avatar')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('locale', 5)->default('ar');
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code', 8);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('base_fee', 8, 2)->default(5);
            $table->decimal('fee_per_km', 8, 2)->default(1.5);
            $table->decimal('min_order', 10, 2)->default(0);
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->decimal('radius_km', 6, 2)->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('المنزل');
            $table->string('details');
            $table->string('landmark')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vehicle_type', 30)->default('motorcycle');
            $table->string('plate_number', 30)->nullable();
            $table->string('national_id', 30)->nullable();
            $table->string('id_photo')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_online')->default(false);
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->decimal('cash_in_hand', 10, 2)->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('driver_profiles');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('users');
    }
};
