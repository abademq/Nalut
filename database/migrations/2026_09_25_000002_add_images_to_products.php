<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // قائمة مسارات الصور — الأولى هي الرئيسية وتنعكس في عمود image
            $table->json('images')->nullable()->after('image');
        });

        // الصورة الموجودة تولّي أول صورة في القائمة
        DB::table('products')->whereNotNull('image')->orderBy('id')->each(function ($p) {
            DB::table('products')->where('id', $p->id)->update(['images' => json_encode([$p->image])]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('images');
        });
    }
};
