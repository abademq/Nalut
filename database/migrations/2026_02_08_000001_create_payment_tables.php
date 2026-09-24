<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // بوابات الدفع — المفاتيح تتخزن مشفّرة وتتعدّل من اللوحة
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();   // sadad | localbankcards | adfali | mpgs | tlync
            $table->string('name');
            $table->string('provider', 20)->default('plutu');
            $table->string('mode', 10)->default('test'); // test | live
            $table->text('credentials')->nullable();     // مشفّرة
            $table->boolean('enabled_for_topup')->default(false);
            $table->boolean('enabled_for_order')->default(false);
            $table->decimal('min_amount', 10, 2)->default(1);
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // كل عملية دفع إلكترونية
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 30);
            $table->string('purpose', 15)->default('topup'); // topup | order
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_no', 40)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status', 15)->default('pending'); // pending|paid|failed|canceled
            $table->string('process_id')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        $now = now();

        DB::table('payment_gateways')->insert([
            [
                'key' => 'sadad', 'name' => 'سداد (المدار)', 'provider' => 'plutu',
                'mode' => 'test', 'sort' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'localbankcards', 'name' => 'بطاقة مصرفية محلية', 'provider' => 'plutu',
                'mode' => 'test', 'sort' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'adfali', 'name' => 'إدفعلي (مصرف التجارة والتنمية)', 'provider' => 'plutu',
                'mode' => 'test', 'sort' => 3,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'tlync', 'name' => 'تي-لينك (تداول)', 'provider' => 'plutu',
                'mode' => 'test', 'sort' => 4,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'mpgs', 'name' => 'بطاقة دولية (ماستركارد)', 'provider' => 'plutu',
                'mode' => 'test', 'sort' => 5,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_gateways');
    }
};
