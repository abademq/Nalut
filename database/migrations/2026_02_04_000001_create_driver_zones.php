<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // مناطق عمل كل سائق
        Schema::create('driver_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['driver_profile_id', 'delivery_zone_id'], 'driver_zone_unique');
        });

        // نخزّن منطقة الطلب باش نقدرو نفلتر الطلبات للسائقين
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_zone_id')->nullable()->after('store_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_zone_id']);
            $table->dropColumn('delivery_zone_id');
        });
        Schema::dropIfExists('driver_zones');
    }
};
