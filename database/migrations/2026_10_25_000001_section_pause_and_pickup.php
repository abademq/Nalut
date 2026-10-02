<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1) إيقاف قسم كامل مؤقتاً: القسم وأصنافه يظهرو للزبون، بس ما ينطلبوش
 *    (مثلاً المعجنات ما تبداش من أول النهار). اختياري: يرجع لحاله في ساعة محددة.
 * 2) الاستلام من المطعم: الطلب بدون سائق ولا رسوم توصيل، والزبون يجي ياخذه برمز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_sections', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('is_active');
            $table->timestamp('paused_until')->nullable()->after('is_available');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment', 10)->default('delivery')->after('status')->index();
            $table->string('pickup_code', 6)->nullable()->after('fulfillment');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('pickup_enabled')->default(true)->after('is_open');
        });
    }

    public function down(): void
    {
        Schema::table('menu_sections', fn (Blueprint $t) => $t->dropColumn(['is_available', 'paused_until']));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['fulfillment']);
            $t->dropColumn(['fulfillment', 'pickup_code']);
        });
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('pickup_enabled'));
    }
};
