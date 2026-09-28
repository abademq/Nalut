<?php

use App\Models\Ticket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** أنواع مشاكل التذاكر — تتعدّل من لوحة التحكم (كانت ثابتة في الكود) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('app', 10)->index();          // customer | driver | store
            $table->string('key', 30);                    // التذاكر تتخزّن بيه — ما يتغيّرش
            $table->string('label', 80);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['app', 'key']);
        });

        $now = now();
        foreach (Ticket::DEFAULT_CATEGORIES as $app => $list) {
            $i = 0;
            foreach ($list as $key => $label) {
                DB::table('ticket_categories')->insert([
                    'app' => $app, 'key' => $key, 'label' => $label, 'sort' => $i++,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
