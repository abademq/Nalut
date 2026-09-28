<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** الصنف يظهر في أكثر من قسم (مثلاً «شاورما» و«العروض») — نفس الصنف، نفس السعر والمخزون */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_section_product', function (Blueprint $table) {
            $table->foreignId('menu_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['menu_section_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_section_product');
    }
};
