<?php

use App\Models\LegalDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الشروط وسياسة الخصوصية (تتعدّل من اللوحة) + سجل موافقة كل مستخدم عليها.
 * القانون رقم 6 لسنة 2022: موافقة صريحة قبل معالجة البيانات الشخصية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('title', 120);
            $table->longText('body');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 30);
            $table->unsignedInteger('version');
            $table->string('app', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'document']);
        });

        Schema::table('users', function (Blueprint $table) {
            // متى اختار بنفسه (موافقة/رفض) على الرسائل التسويقية — null = ما اختارش لسه
            $table->timestamp('marketing_choice_at')->nullable();
        });

        $now = now();
        foreach (LegalDocument::DOCUMENTS as $key => $title) {
            $path = resource_path("legal/$key.md");
            DB::table('legal_documents')->insert([
                'key' => $key, 'title' => $title,
                'body' => is_file($path) ? file_get_contents($path) : '',
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
        Schema::dropIfExists('legal_documents');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('marketing_choice_at'));
    }
};
