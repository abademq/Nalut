<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // الرموز القديمة مخزّنة كنص عادي — ما عادش تتطابق مع التحقق المشفّر
        DB::table('otp_codes')->delete();

        Schema::table('otp_codes', function (Blueprint $table) {
            // الرمز يتخزّن HMAC-SHA256 (64 خانة) بدل النص العادي
            $table->string('code', 64)->change();
            $table->string('channel', 20)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        DB::table('otp_codes')->delete();

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('channel');
            $table->string('code', 8)->change();
        });
    }
};
