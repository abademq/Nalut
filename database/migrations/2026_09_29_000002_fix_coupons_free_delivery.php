<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «توصيل مجاني» كان يطيّح إنشاء الكوبون:
     *  - type كان 10 حروف و free_delivery = 13 (MySQL يرفض)
     *  - value إجباري بدون قيمة افتراضية وحقله مخفي في التوصيل المجاني
     */
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('type', 20)->default('percent')->change();
            $table->decimal('value', 10, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('type', 10)->default('percent')->change();
            $table->decimal('value', 10, 2)->change();
        });
    }
};
