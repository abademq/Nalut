<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // أسباب تعذّر التسليم — تتدار من لوحة التحكم
        Schema::create('failure_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            // true: الطلب يتعلّق «قيد مراجعة الإدارة» بدل ما يفشل مباشرة
            $table->boolean('hold_for_review')->default(false);
            // true: تنفتح محادثة واتساب مع الدعم الفني تلقائياً
            $table->boolean('open_support')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // بلاغات السائقين على الطلبات (تذاكر)
        Schema::create('order_issues', function (Blueprint $table) {
            $table->id();
            $table->string('ticket', 20)->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('failure_reason_id')->nullable()->constrained()->nullOnDelete();
            // نسخة ثابتة من السبب — لو اتعدّل أو انمسح من اللوحة بعدين
            $table->string('reason_label');
            $table->text('note')->nullable();
            // review = الطلب متعلّق للمراجعة · failed = الطلب فشل مباشرة
            $table->string('action', 10);
            $table->string('status', 10)->default('open'); // open | resolved
            $table->string('resolution', 20)->nullable();   // continue | failed | cancelled | reassign
            $table->string('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        $now = now();
        DB::table('failure_reasons')->insert([
            ['label' => 'تعطّلت المركبة',                     'hold_for_review' => true,  'open_support' => true,  'sort' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'حادث أو ظرف طارئ للسائق',           'hold_for_review' => true,  'open_support' => true,  'sort' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'الطلب تضرّر أثناء التوصيل',          'hold_for_review' => true,  'open_support' => true,  'sort' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'الزبون ما يردّش والعنوان غير واضح', 'hold_for_review' => false, 'open_support' => false, 'sort' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'الزبون رفض الاستلام',               'hold_for_review' => false, 'open_support' => true,  'sort' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'مشكلة أخرى',                        'hold_for_review' => true,  'open_support' => true,  'sort' => 6, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_issues');
        Schema::dropIfExists('failure_reasons');
    }
};
