<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «بانتظار التسليم»: السائق وصل عند الزبون ويستناه.
 * بعد انتهاء المدة (من إعدادات التشغيل) يقدر يحط الطلب أمام الباب ويصوّره كإثبات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('picked_up_at');
            $table->timestamp('handover_deadline_at')->nullable()->after('arrived_at');
            $table->timestamp('left_at_door_at')->nullable()->after('handover_deadline_at');
            $table->string('door_photo')->nullable()->after('left_at_door_at');
        });

        // إشعارات الحالة الجديدة: الزبون (لازم يعرف إن السائق عند الباب)
        $defaults = ['customer' => true, 'store' => false, 'driver' => false];
        foreach ($defaults as $role => $enabled) {
            DB::table('notification_settings')->updateOrInsert(
                ['role' => $role, 'status' => 'awaiting_handover'],
                ['enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['arrived_at', 'handover_deadline_at', 'left_at_door_at', 'door_photo']));
        DB::table('notification_settings')->where('status', 'awaiting_handover')->delete();
    }
};
