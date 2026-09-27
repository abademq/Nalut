<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // الإضافة تتحط مرة وحدة (1) أو أكثر (مثلاً سيخ كباب إضافي × 3)
        Schema::table('product_option_values', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_qty')->default(1)->after('extra_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            $table->dropColumn('max_qty');
        });
    }
};
