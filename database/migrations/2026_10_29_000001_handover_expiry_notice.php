<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** وقت إشعار «انتهت مهلة الزبون» — باش ما يتبعتش أكثر من مرة */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('handover_expired_at')->nullable()->after('handover_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('handover_expired_at'));
    }
};
