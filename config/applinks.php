<?php

/*
| روابط تفتح التطبيق مباشرة (Android App Links):
|   https://<APP_URL>/s/{متجر}            → صفحة المتجر
|   https://<APP_URL>/s/{متجر}/p/{صنف}    → الصنف داخل المتجر
|   https://<APP_URL>/go/{شاشة}           → home | cart | wallet | orders
|
| أندرويد يفتح التطبيق بدون سؤال بس لو assetlinks.json فيه اسم الحزمة
| وبصمة SHA-256 لمفتاح التوقيع — من: keytool -list -v -keystore nalut-upload.jks
*/

return [
    'android_package' => env('APP_ANDROID_PACKAGE', 'ly.azanx.app'),

    // بصمات SHA-256 مفصولة بفاصلة (مفتاح الإصدار، ومفتاح Google Play لو فعّلت Play App Signing)
    'android_sha256' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_ANDROID_SHA256', ''))))),

    // iOS: TEAMID.ly.azanx.app مفصولة بفاصلة — لروابط الأيفون (Universal Links)
    'ios_app_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_IOS_APP_ID', ''))))),

    // رابط التطبيق في App Store — فاضي = ما نوروش زر الأيفون
    'app_store_url' => env('APP_APP_STORE_URL'),

    // رابط التطبيق في Google Play — فاضي = نبنيه من اسم الحزمة
    'play_store_url' => env('APP_PLAY_STORE_URL'),

    // مخطط احتياطي يفتح التطبيق من صفحة الرابط لو App Links مش متحققة
    'scheme' => 'nalut',

    'screens' => [
        'home' => 'الرئيسية',
        'cart' => 'السلة',
        'wallet' => 'المحفظة وشحن الرصيد',
        'orders' => 'طلباتي',
        'points' => 'نقاطي',
        'favorites' => 'المفضلة',
    ],
];
