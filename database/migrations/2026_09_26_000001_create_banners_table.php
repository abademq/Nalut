<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // إعلانات الصفحة الرئيسية في تطبيق الزبون — تتدار من لوحة التحكم
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('image')->nullable();
            // لون الخلفية لو ما فيش صورة (#D84315)
            $table->string('color', 9)->nullable();
            // الضغط على الإعلان: يفتح متجر، أو رابط، أو ولا شي
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
