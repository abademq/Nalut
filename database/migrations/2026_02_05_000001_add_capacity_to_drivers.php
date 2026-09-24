<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // أقصى عدد طلبات نشطة في نفس الوقت
            $table->unsignedTinyInteger('max_active_orders')->default(1)->after('is_online');

            // single     = طلب واحد فقط
            // same_store = أكثر من طلب بشرط نفس المتجر
            // any        = أي طلبات من أي متجر
            $table->string('multi_order_mode', 15)->default('single')->after('max_active_orders');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['max_active_orders', 'multi_order_mode']);
        });
    }
};
