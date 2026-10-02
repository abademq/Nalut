<?php

namespace App\Support;

use App\Models\Setting;

/**
 * ألوان التطبيقات الأربعة (الزبون، الكابتن، التاجر، الإدارة) — تتعدّل من «مظهر التطبيقات».
 * التطبيق ياخذها مع /app/content ويعيد رسم الواجهة بيها (بدون تحديث من المتجر).
 * الفاضي أو الغلط = لون الهوية الأصلي.
 */
class AppTheme
{
    /** المفتاح => [الافتراضي, الاسم, الوصف] */
    public const COLORS = [
        'primary' => ['#075C52', 'اللون الأساسي', 'شريط العنوان، التبويبات، الروابط، المفاتيح.'],
        'primary_dark' => ['#043933', 'الأساسي الغامق', 'نصوص فوق الخلفيات الفاتحة من نفس اللون.'],
        'primary_soft' => ['#E3EFEC', 'الأساسي الفاتح', 'خلفية التبويب المختار والشرائح.'],
        'accent' => ['#FF7900', 'لون الأزرار الرئيسية', 'زر «تأكيد الطلب»، الأزرار البارزة، والأسعار.'],
        'accent_soft' => ['#FFF0E3', 'الأزرار الفاتح', 'خلفيات العناصر الثانوية.'],
        'button_text' => ['#FFFFFF', 'لون نص الأزرار', 'النص فوق الأزرار الملوّنة.'],
        'background' => ['#FAF9F7', 'خلفية التطبيق', 'خلفية كل الشاشات.'],
        'card' => ['#FFFFFF', 'خلفية البطاقات', 'بطاقات الطلبات والأصناف وشريط التنقل.'],
        'ink' => ['#1C2B29', 'لون النص الأساسي', ''],
        'muted' => ['#8A948F', 'لون النص الثانوي', 'التبويبات غير المختارة والتفاصيل.'],
        'input' => ['#F1F0EC', 'خلفية حقول الكتابة', ''],
    ];

    public static function get(string $key): string
    {
        $default = self::COLORS[$key][0];
        $v = strtoupper(trim((string) Setting::get("theme.$key", $default)));

        return preg_match('/^#[0-9A-F]{6}$/', $v) ? $v : $default;
    }

    /** للتطبيقات: {primary: "#075C52", ...} + رقم نسخة يتغيّر مع كل حفظ */
    public static function values(): array
    {
        $out = [];
        foreach (array_keys(self::COLORS) as $k) {
            $out[$k] = self::get($k);
        }
        $out['version'] = (int) Setting::get('theme.version', 0);

        return $out;
    }

    public static function defaults(): array
    {
        return array_map(fn ($c) => $c[0], self::COLORS);
    }
}
