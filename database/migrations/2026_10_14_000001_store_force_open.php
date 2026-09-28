<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «افتح توّا» خارج ساعات العمل: المتجر يقعد مفتوح لين الوقت هذا (أو لين يسكّره) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('force_open_until')->nullable()->after('is_open');
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('force_open_until'));
    }
};
