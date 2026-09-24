<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // هل المنتج يتتبّع المخزون؟ false = متوفر دائماً
            $table->boolean('track_stock')->default(false)->after('is_available');
            $table->integer('stock_quantity')->default(0)->after('track_stock');
            $table->unsignedSmallInteger('max_per_order')->nullable()->after('stock_quantity');
            $table->unsignedSmallInteger('low_stock_alert')->nullable()->after('max_per_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['track_stock', 'stock_quantity', 'max_per_order', 'low_stock_alert']);
        });
    }
};
