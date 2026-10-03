<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1) دعم التوصيل: كم دفعت الشركة من رسوم التوصيل (الزبون دفع delivery_fee بس).
 * 2) دعوة صديق: كود لكل زبون، ومنو دعاه، ومتى انصرفت الهدية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('delivery_subsidy', 8, 2)->default(0)->after('delivery_fee');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referred_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('referred_at')->nullable();
            $table->timestamp('referral_rewarded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('delivery_subsidy'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('referred_by_id');
            $t->dropUnique(['referral_code']);
            $t->dropColumn(['referral_code', 'referred_at', 'referral_rewarded_at']);
        });
    }
};
