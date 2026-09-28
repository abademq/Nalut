<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * خيار «المكوّنات» (بدون بصل...) يتفعّل من اللوحة:
 * على نوع المتجر (مطاعم، مقاهي) — وكل متجر يقدر يخالف نوعه (on/off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_types', function (Blueprint $table) {
            $table->boolean('has_ingredients')->default(false)->after('is_active');
        });
        Schema::table('stores', function (Blueprint $table) {
            // null = حسب نوع المتجر
            $table->string('ingredients_mode', 5)->nullable();
        });

        // المطاعم والمقاهي الموجودة تتفعّل من البداية
        foreach (['%مطعم%', '%مطاعم%', '%مقهى%', '%مقاهي%', '%كافي%', '%كوفي%', '%قهوة%'] as $like) {
            DB::table('store_types')->where('name', 'like', $like)->update(['has_ingredients' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('store_types', fn (Blueprint $t) => $t->dropColumn('has_ingredients'));
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('ingredients_mode'));
    }
};
