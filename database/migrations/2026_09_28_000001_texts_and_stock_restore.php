<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // نصوص التطبيقات والإشعارات — الإدارة تعدّلها من اللوحة.
        // الأصل (default) يجي من الكود عبر texts:sync، والتعديل في value.
        Schema::create('app_texts', function (Blueprint $table) {
            $table->id();
            $table->string('app', 20);          // customer | store | driver | server
            $table->string('group', 100);       // الشاشة أو نوع النص
            $table->char('key_hash', 32);       // md5(key) — المفتاح نفسه ممكن يكون طويل
            $table->text('key');                // للتطبيقات: النص الأصلي نفسه · للسيرفر: مفتاح ثابت
            $table->text('default');
            $table->text('value')->nullable();  // null = يستعمل الأصل
            $table->string('vars')->nullable(); // المتغيّرات المتاحة: {code} {prep} ...
            $table->boolean('is_used')->default(true); // false = اختفى من الكود
            $table->timestamps();

            $table->unique(['app', 'key_hash']);
            $table->index(['app', 'group']);
        });

        Schema::table('products', function (Blueprint $table) {
            // خلص من المخزن وتخفّى تلقائياً — يرجع لحاله لما المخزون يرجع
            $table->timestamp('sold_out_at')->nullable()->after('low_stock_alert');
        });

        Schema::table('orders', function (Blueprint $table) {
            // يمنع إرجاع المخزون مرتين لنفس الطلب
            $table->timestamp('stock_restored_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_texts');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('sold_out_at'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('stock_restored_at'));
    }
};
