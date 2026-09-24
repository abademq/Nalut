<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('type', 10)->default('percent'); // percent | fixed
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_order', 10, 2)->default(0);
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedSmallInteger('per_user_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('pending')->index();
            $table->string('payment_method', 20)->default('cash');
            $table->boolean('is_paid')->default(false);

            // نسخة ثابتة من العنوان وقت الطلب
            $table->string('address_details');
            $table->string('address_landmark')->nullable();
            $table->decimal('address_lat', 10, 7);
            $table->decimal('address_lng', 10, 7);
            $table->string('customer_phone', 20);

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('store_earning', 10, 2)->default(0);
            $table->decimal('driver_earning', 10, 2)->default(0);
            $table->decimal('distance_km', 6, 2)->default(0);

            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('prep_time_minutes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->index(['store_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->json('options')->nullable();
            $table->decimal('options_price', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('coupon_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('store_rating')->nullable();
            $table->unsignedTinyInteger('driver_rating')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('coupon_user');
        Schema::dropIfExists('order_status_logs');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
    }
};
