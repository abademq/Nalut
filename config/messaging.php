<?php

/*
|--------------------------------------------------------------------------
| الرسائل: واتساب (WhatsApp Cloud API من Meta) و SMS (رسالة resala.ly)
|--------------------------------------------------------------------------
|
| واتساب الرسمي يحتاج:
|  1) حساب Meta Business موثّق + رقم مخصص للواتساب (ما يكونش عليه واتساب عادي)
|  2) Phone Number ID و Access Token دائم (System User)
|  3) قوالب معتمدة: قالب تحقق (Authentication) للرموز، وقوالب للتسويق والتقارير
|
| لو WHATSAPP_TOKEN فاضي: رسائل الواتساب ما تنبعتش وتتسجّل «متخطّاة» في السجل.
*/

return [
    'whatsapp' => [
        'token'           => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version'     => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url'        => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'timeout'         => (int) env('WHATSAPP_TIMEOUT', 15),

        // قالب رمز التحقق (نوع Authentication، فيه زر «نسخ الرمز»)
        'otp_template'    => env('WHATSAPP_OTP_TEMPLATE', 'otp_code'),
        'otp_language'    => env('WHATSAPP_OTP_LANGUAGE', 'ar'),
    ],
];
