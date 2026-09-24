<?php

/*
|--------------------------------------------------------------------------
| رموز التحقق (OTP)
|--------------------------------------------------------------------------
|
| driver:
|   log     — ما يبعتش شي، يولّد الرمز ويكتبه في السجل (للتطوير والتجربة)
|   resala  — يبعت SMS عبر منصة رسالة (resala.ly)، والمنصة هي اللي تولّد الرمز
|
| ⚠️ لا تستعمل env() خارج ملفات config — مع config:cache ترجع null.
|
*/

return [

    'driver' => env('OTP_DRIVER', 'log'),

    // عدد خانات الرمز — رسالة تقبل 4 أو 5 أو 6
    'length' => (int) env('OTP_LENGTH', 4),

    // صلاحية الرمز بالثواني
    'ttl' => (int) env('OTP_TTL_SECONDS', 300),

    // أقل مدة بين طلبين لنفس الرقم
    'resend_cooldown' => (int) env('OTP_RESEND_COOLDOWN', 60),

    // أقصى عدد رموز لنفس الرقم في الساعة
    'max_per_hour' => (int) env('OTP_MAX_PER_HOUR', 5),

    // أقصى عدد رموز من نفس الـ IP في الساعة (حماية من استنزاف الرصيد)
    'max_per_ip_hour' => (int) env('OTP_MAX_PER_IP_HOUR', 20),

    // أقصى عدد محاولات خاطئة قبل ما يُلغى الرمز
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    // يرجّع الرمز في الاستجابة (debug_code) — للتجربة فقط، لازم false مع مستخدمين حقيقيين
    'debug' => (bool) env('OTP_DEV_MODE', false),

    // اسم الخدمة اللي يظهر في نص الرسالة
    'service_name' => env('OTP_SERVICE_NAME', 'Nalut'),

    /*
    | أرقام تجريبية برمز ثابت وبدون إرسال — لمراجعي Google Play وحسابات العرض.
    | الصيغة: "0913333333:1234,0911111111:5678"
    */
    'test_numbers' => collect(explode(',', (string) env('OTP_TEST_NUMBERS', '')))
        ->map(fn ($pair) => array_map('trim', explode(':', $pair, 2)))
        ->filter(fn ($p) => count($p) === 2 && $p[0] !== '' && $p[1] !== '')
        ->mapWithKeys(fn ($p) => [$p[0] => $p[1]])
        ->all(),

    'resala' => [
        'base_url' => env('RESALA_BASE_URL', 'https://dev.resala.ly/api/v1'),
        'token'    => env('RESALA_API_TOKEN'),
        // وضع الاختبار: ما تنبعتش رسالة حقيقية وما ينخصمش رصيد، لكن الرمز يرجع
        'test'     => (bool) env('RESALA_TEST', false),
        'timeout'  => (int) env('RESALA_TIMEOUT', 15),
    ],

];
