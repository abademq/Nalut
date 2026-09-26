<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // سجل نشاط المنصة: كل عملية من التطبيقات الثلاثة ولوحة التحكم والنظام
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('app', 10)->index();          // customer | store | driver | admin | system
            $table->string('action', 60)->index();       // order.create · cart.add · model.updated ...
            $table->string('description', 255);
            $table->nullableMorphs('subject');
            $table->foreignId('order_id')->nullable()->index();
            $table->foreignId('store_id')->nullable()->index();
            $table->json('properties')->nullable();      // الطلب، التغييرات (قبل/بعد)، النتيجة
            $table->unsignedSmallInteger('status')->nullable(); // كود الرد لطلبات الـ API
            $table->string('ip', 45)->nullable();
            $table->string('device', 160)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
