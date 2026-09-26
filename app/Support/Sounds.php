<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * أصوات الإشعارات — تتختار من لوحة التحكم لكل جهة.
 *
 * النغمات الجاهزة موجودة داخل التطبيقات (res/raw و assets/sounds) وفي public/sounds،
 * فتشتغل حتى والتطبيق مقفول. الملف المرفوع يشتغل في لوحة التحكم وداخل التطبيق وهو مفتوح
 * (أندرويد ما يسمحش بصوت إشعار من ملف خارجي والتطبيق في الخلفية).
 */
class Sounds
{
    public const TONES = [
        'default' => 'الافتراضي',
        'classic' => 'كلاسيكي (نغمتين)',
        'chime'   => 'رنين',
        'bell'    => 'جرس',
        'ding'    => 'دينغ قصير',
        'alert'   => 'تنبيه قوي (متكرر)',
        'soft'    => 'هادئ',
    ];

    public const TARGETS = [
        'admin'    => 'لوحة التحكم',
        'customer' => 'تطبيق الزبون',
        'driver'   => 'تطبيق السائق',
        'store'    => 'تطبيق المتجر',
    ];

    public static function tone(string $target): string
    {
        $tone = (string) Setting::get("sound.$target", 'default');

        return array_key_exists($tone, self::TONES) ? $tone : 'default';
    }

    public static function customPath(string $target): ?string
    {
        $path = (string) Setting::get("sound.$target.file", '');

        return $path !== '' ? $path : null;
    }

    /** رابط الصوت اللي يشتغل داخل التطبيق/اللوحة — المرفوع أولاً، بعدين النغمة (null = الافتراضي) */
    public static function url(string $target): ?string
    {
        if ($path = self::customPath($target)) {
            return Storage::disk('public')->url($path);
        }

        $tone = self::tone($target);

        return $tone === 'default' ? null : asset("sounds/tone_$tone.wav");
    }

    /** قناة أندرويد والصوت اللي يرسلهم السيرفر مع الإشعار */
    public static function android(string $app): array
    {
        $tone = self::tone($app);

        return $tone === 'default'
            ? ['channel_id' => 'orders']
            : ['channel_id' => "orders_$tone", 'sound' => "tone_$tone"];
    }

    /** للتطبيقات: {tone, url} */
    public static function forApp(string $app): array
    {
        return ['tone' => self::tone($app), 'url' => self::url($app), 'custom' => self::customPath($app) !== null];
    }
}
