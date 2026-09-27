<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // تسويات المتاجر والسائقين — كل تسوية لها واصل مطبوع
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('party', 10);            // store | driver
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            // pay = المنصة دفعت للطرف · receive = المنصة استلمت من الطرف
            $table->string('direction', 10);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('method', 20)->default('cash');
            $table->string('reference', 100)->nullable();
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->json('summary')->nullable();    // صورة من الحساب وقت التسوية
            $table->string('note', 500)->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['party', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('store_settlement_id')->nullable()->after('earnings_settled')->constrained('settlements')->nullOnDelete();
            $table->foreignId('driver_settlement_id')->nullable()->after('store_settlement_id')->constrained('settlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_settlement_id');
            $table->dropConstrainedForeignId('driver_settlement_id');
        });
        Schema::dropIfExists('settlements');
    }
};
