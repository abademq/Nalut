<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // قوالب الرسائل — لازم تكون معتمدة عند المزوّد (واتساب/رسالة) بنفس الاسم أو المعرّف
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');                  // اسم للإدارة
            $table->string('channel', 10);           // whatsapp | sms
            $table->string('provider_ref');          // اسم قالب واتساب أو sms_template_id في رسالة
            $table->string('language', 10)->default('ar');
            $table->json('params')->nullable();      // ["{name}", "{orders}"] = {{1}} {{2}} أو $1 $2
            $table->text('preview')->nullable();     // نص القالب كما هو معتمد — للمعاينة فقط
            $table->string('purpose', 20)->default('general'); // marketing | report | alert | general
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);
            $table->string('phone', 20);
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context', 20);           // otp | campaign | report | alert
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('status', 10);            // sent | failed | skipped
            $table->string('provider_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['context', 'context_id']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('message_template_id')->constrained();
            $table->string('audience', 20);          // all | active | inactive | store | numbers
            $table->json('audience_params')->nullable();
            $table->string('status', 10)->default('draft'); // draft | queued | sending | done | failed
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // فاضي = ملخص المنصة
            $table->string('recipient', 10)->default('store'); // store = رقم المتجر · custom = رقم محدد
            $table->string('phone', 20)->nullable();
            $table->foreignId('message_template_id')->constrained();
            $table->string('period', 15)->default('today'); // today | yesterday | last_7_days | this_month | last_month
            $table->string('frequency', 10)->default('daily'); // once | daily | weekly | monthly
            $table->string('send_time', 5)->default('23:00'); // بتوقيت ليبيا
            $table->unsignedTinyInteger('weekday')->nullable(); // 1=الاثنين … 7=الأحد (ISO)
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->timestamp('send_at')->nullable();  // للإرسال مرة وحدة
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status', 10)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // ما يوصلوش رسائل تسويقية
            $table->boolean('marketing_opt_out')->default(false)->after('is_active');
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        // «نسخة الزبون» صارت «نسخة السائق» — والسائق يحتاج هاتف الزبون
        DB::table('settings')->updateOrInsert(['key' => 'receipt.customer.show_phone'], ['value' => '1']);
        DB::table('settings')->where('key', 'receipt.customer.title')->where('value', 'نسخة الزبون')
            ->update(['value' => 'نسخة السائق']);
        DB::table('settings')->where('key', 'receipt.customer.footer')->where('value', 'شكراً لطلبك')
            ->update(['value' => 'راجع الأصناف مع الزبون عند التسليم']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('marketing_opt_out'));
        Schema::dropIfExists('report_subscriptions');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('message_templates');
    }
};
