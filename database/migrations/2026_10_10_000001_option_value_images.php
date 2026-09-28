<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** صورة لكل اختيار (اللون الأحمر، الأسود...) — تطلع للزبون لما يختاره */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            $table->string('image')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_values', fn (Blueprint $t) => $t->dropColumn('image'));
    }
};
