<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('role', 20);    // customer | store | driver
            $table->string('status', 25);  // حالة الطلب أو available
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['role', 'status']);
        });

        // الإعدادات الافتراضية = السلوك الحالي
        $defaults = [
            'customer' => [
                'pending' => false, 'accepted' => true, 'preparing' => false,
                'ready' => true, 'assigned' => false, 'picked_up' => false,
                'on_the_way' => true, 'delivered' => true,
                'cancelled' => true, 'failed' => true, 'available' => false,
            ],
            'store' => [
                'pending' => true, 'accepted' => false, 'preparing' => false,
                'ready' => false, 'assigned' => false, 'picked_up' => true,
                'on_the_way' => false, 'delivered' => false,
                'cancelled' => true, 'failed' => true, 'available' => false,
            ],
            'driver' => [
                'pending' => false, 'accepted' => false, 'preparing' => false,
                'ready' => false, 'assigned' => true, 'picked_up' => false,
                'on_the_way' => false, 'delivered' => false,
                'cancelled' => true, 'failed' => false, 'available' => true,
            ],
        ];

        $rows = [];
        foreach ($defaults as $role => $statuses) {
            foreach ($statuses as $status => $enabled) {
                $rows[] = [
                    'role'       => $role,
                    'status'     => $status,
                    'enabled'    => $enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('notification_settings')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
