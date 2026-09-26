<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // أقسام التطبيق الرئيسية: مطاعم، متاجر إلكترونية، متاجر... — كل قسم فيه أنواع متاجر
        Schema::create('app_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->string('emoji', 16)->nullable();
            $table->string('image')->nullable();
            $table->string('color', 9)->default('#D84315');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('store_types', function (Blueprint $table) {
            $table->foreignId('app_section_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // المفضلة: متاجر وأصناف
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('favoritable');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'favoritable_type', 'favoritable_id']);
        });

        // سلات جاهزة: مجموعة أصناف من متجر تنضاف للسلة بضغطة
        Schema::create('ready_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('image')->nullable();
            $table->json('items');                  // [{product_id, quantity}]
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // شريط العروض: فوق التطبيق (store_id فاضي) أو فوق متجر معيّن
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->string('bg_color', 9)->default('#D84315');
            $table->string('text_color', 9)->default('#FFFFFF');
            $table->string('link')->nullable();     // رابط مشاركة (s/5 ، go/wallet) أو رابط خارجي
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // نقاط الولاء
        Schema::create('points_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('points');              // + كسب · − صرف
            $table->integer('balance_after');
            $table->string('type', 15);             // earned | converted | redeemed | refund | adjust
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('points_balance')->default(0)->after('marketing_opt_out');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_used')->default(0);
            $table->decimal('points_discount', 10, 2)->default(0);
            $table->unsignedInteger('points_awarded')->default(0);
            // المتجر علّم أصناف مش متوفرة — الطلب يستنى رد الزبون
            $table->timestamp('awaiting_customer_at')->nullable();
            $table->timestamp('substitution_deadline_at')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_unavailable')->default(false);
        });

        // حملات إشعارات التطبيق (بدون قالب)
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('channel', 10)->default('template')->after('title'); // template | push
            $table->string('target_role', 10)->default('customer')->after('channel'); // customer | driver | store
            $table->string('push_title')->nullable();
            $table->string('push_body', 500)->nullable();
            $table->string('push_link')->nullable();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('message_template_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['channel', 'target_role', 'push_title', 'push_body', 'push_link']));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('is_unavailable'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['points_used', 'points_discount', 'points_awarded', 'awaiting_customer_at', 'substitution_deadline_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('points_balance'));
        Schema::dropIfExists('points_transactions');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('ready_carts');
        Schema::dropIfExists('favorites');
        Schema::table('store_types', function (Blueprint $t) {
            $t->dropConstrainedForeignId('app_section_id');
        });
        Schema::dropIfExists('app_sections');
    }
};
