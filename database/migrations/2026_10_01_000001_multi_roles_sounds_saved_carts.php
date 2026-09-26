<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // أكثر من دور لنفس الحساب (زبون + سائق + متجر + إدارة) — role يضل «الدور الأساسي»
        // وتوكن إشعارات لكل تطبيق: نفس الرقم ممكن يكون عنده تطبيق الزبون والسائق
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'roles')) {
                $table->json('roles')->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'fcm_tokens')) {
                $table->json('fcm_tokens')->nullable()->after('fcm_token');
            }
        });

        DB::table('users')->whereNull('roles')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $u) {
                $tokens = $u->fcm_token && in_array($u->role, ['customer', 'driver', 'store'], true)
                    ? json_encode([$u->role => $u->fcm_token])
                    : null;

                DB::table('users')->where('id', $u->id)->update([
                    'roles'      => json_encode([$u->role]),
                    'fcm_tokens' => $tokens,
                ]);
            }
        });

        // سلات الزبون المحفوظة: يجهّز سلة ويحفظها ويطلبها بعدين بضغطة
        if (! Schema::hasTable('saved_carts')) {
            Schema::create('saved_carts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('name', 60);
                $table->json('items');              // [{product_id, quantity, note}]
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_carts');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['roles', 'fcm_tokens']));
    }
};
