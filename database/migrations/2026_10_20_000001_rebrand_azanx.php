<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * الاسم الجديد: ازانكس (AzanX).
 * نبدّلو القيم المحفوظة بس لو هي نفس القيم الافتراضية القديمة —
 * أي نص عدّلته الإدارة بيدها يقعد زي ما هو.
 */
return new class extends Migration
{
    private const MAP = [
        'about.name' => ['توصيل نالوت', 'ازانكس'],
        'about.tagline' => ['اطلب من مطاعم ومتاجر نالوت', 'ازانكس… طلبك لعند باب الحوش'],
        'about.description' => [
            'منصة توصيل محلية تربطك بمطاعم ومتاجر نالوت، وتوصّل طلبك لباب بيتك.',
            'ازانكس علامة متخصصة في توصيل طلبات المطاعم والمحلات إلى الزبائن، تنطلق من مدينة نالوت. تجربة توصيل واضحة وبسيطة تربط بين المتاجر والمطاعم والعملاء، مع التركيز على سهولة الاستخدام وسرعة الخدمة والتنظيم.',
        ],
        'receipt.header' => ['توصيل نالوت', 'ازانكس'],
    ];

    public function up(): void
    {
        foreach (self::MAP as $key => [$old, $new]) {
            if (trim((string) Setting::get($key, '')) === $old) {
                Setting::put($key, $new);
            }
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $key => [$old, $new]) {
            if (trim((string) Setting::get($key, '')) === $new) {
                Setting::put($key, $old);
            }
        }
    }
};
