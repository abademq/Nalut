<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // محفظة واحدة لكل مستخدم — زبون، متجر، أو سائق
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // دفتر الحركات — يُضاف إليه فقط، ما يتعدّلش أبداً
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->decimal('amount', 12, 2);          // موجب = دخل، سالب = خرج
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recharge_card_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['wallet_id', 'created_at']);
        });

        // كروت شحن المحفظة
        Schema::create('recharge_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('batch', 40)->nullable()->index();
            $table->string('status', 15)->default('unused')->index(); // unused | used | disabled
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // كم دُفع من المحفظة في كل طلب (يسمح بالدفع المختلط)
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('wallet_paid', 10, 2)->default(0)->after('is_paid');
            $table->boolean('earnings_settled')->default(false)->after('wallet_paid');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['wallet_paid', 'earnings_settled']);
        });
        Schema::dropIfExists('recharge_cards');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
