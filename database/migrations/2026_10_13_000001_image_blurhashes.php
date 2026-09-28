<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** BlurHash لكل صورة: ضبابية بألوان الصورة تبان لين الصورة تتحمّل */
return new class extends Migration
{
    private const COLUMNS = [
        'products' => ['image_hashes' => 'json'],
        'stores' => ['logo_hash' => 'string', 'cover_hash' => 'string'],
        'banners' => ['image_hash' => 'string'],
        'product_option_values' => ['image_hash' => 'string'],
        'app_sections' => ['image_hash' => 'string'],
        'ready_carts' => ['image_hash' => 'string'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            Schema::table($table, function (Blueprint $t) use ($cols) {
                foreach ($cols as $col => $type) {
                    $type === 'json' ? $t->json($col)->nullable() : $t->string($col, 60)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(array_keys($cols)));
        }
    }
};
