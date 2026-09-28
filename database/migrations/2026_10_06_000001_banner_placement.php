<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // وين يطلع الإعلان: الرئيسية، كل الأقسام، قسم معيّن، صفحة متجر، أو السلة
        Schema::table('banners', function (Blueprint $table) {
            $table->string('placement', 20)->default('home')->after('url');
            $table->foreignId('app_section_id')->nullable()->after('placement')->constrained()->nullOnDelete();
            $table->foreignId('show_store_id')->nullable()->after('app_section_id')->constrained('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('show_store_id');
            $table->dropConstrainedForeignId('app_section_id');
            $table->dropColumn('placement');
        });
    }
};
