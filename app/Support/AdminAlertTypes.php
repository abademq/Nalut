<?php

namespace App\Support;

use App\Models\Setting;

/**
 * أنواع تنبيهات الإدارة وإعداداتها («إعدادات الإشعارات ← تنبيهات لوحة التحكم»):
 *   enabled = يظهر في جرس اللوحة · sound = معاه صوت · tone = النغمة · push = يوصل لتطبيق الإدارة
 */
class AdminAlertTypes
{
    /** النوع => [الاسم, الوصف, الافتراضي: enabled, sound, tone, push] */
    public const TYPES = [
        'order_new' => ['طلب جديد', 'كل طلب يوصل للمتجر.', false, false, 'soft', true],
        'order_rejected' => ['المتجر رفض طلب', 'المتجر ضغط «رفض» وكتب السبب.', true, true, 'default', true],
        'stuck_pending' => ['طلب ما تقبلش', 'فاتت مدة «نبّهني لو طلب ما تقبلش» بدون رد المتجر.', true, true, 'alert', true],
        'stuck_ready' => ['طلب جاهز بدون سائق', 'فاتت مدة «جاهز وما خذاهش سائق».', true, true, 'alert', true],
        'driver_issue' => ['بلاغ من سائق', 'السائق بلّغ عن مشكلة والطلب موقوف لين تقرر.', true, true, 'alert', true],
        'order_failed' => ['فشل تسليم طلب', 'الطلب تعلّم «فشل التسليم».', true, true, 'alert', true],
        'ticket_new' => ['تذكرة دعم جديدة', 'زبون أو سائق أو متجر فتح تذكرة.', true, true, 'chime', true],
        'ticket_reply' => ['رد على تذكرة', 'المستخدم ردّ على تذكرة مفتوحة.', true, true, 'ding', true],
        'account_delete' => ['طلب حذف حساب', 'حساب طلب الحذف ويحتاج موافقة الإدارة.', true, false, 'default', false],
        'emergency' => ['مركز الطوارئ', 'حد شغّل أو وقّف مفتاح طوارئ (دفع، طلبات، قفل تطبيق...).', true, true, 'alert', true],
        'server' => ['ضغط على السيرفر', 'الذاكرة أو المعالج أو الطابور تجاوزو الحد.', true, true, 'bell', true],
        'other' => ['تنبيهات أخرى', 'أي تنبيه ثاني من النظام.', true, true, 'default', true],
    ];

    /** مفتاح التنبيه (order-failed:15) → النوع */
    public static function fromKey(?string $key): string
    {
        $prefix = $key ? strtok($key, ':') : '';

        return match ($prefix) {
            'order-new' => 'order_new',
            'order-rejected' => 'order_rejected',
            'stuck-pending' => 'stuck_pending',
            'stuck-ready' => 'stuck_ready',
            'order-issue' => 'driver_issue',
            'order-failed' => 'order_failed',
            'ticket-new' => 'ticket_new',
            'ticket-msg' => 'ticket_reply',
            'delete-request' => 'account_delete',
            'server-high' => 'server',
            'emergency' => 'emergency',
            default => 'other',
        };
    }

    /** @return array{enabled: bool, sound: bool, tone: string, push: bool} */
    public static function config(string $type): array
    {
        $d = self::TYPES[$type] ?? self::TYPES['other'];
        $tone = (string) Setting::get("admin_alert.$type.tone", $d[4]);

        return [
            'enabled' => filter_var(Setting::get("admin_alert.$type.enabled", $d[2] ? '1' : '0'), FILTER_VALIDATE_BOOLEAN),
            'sound' => filter_var(Setting::get("admin_alert.$type.sound", $d[3] ? '1' : '0'), FILTER_VALIDATE_BOOLEAN),
            'tone' => array_key_exists($tone, Sounds::TONES) ? $tone : 'default',
            'push' => filter_var(Setting::get("admin_alert.$type.push", $d[5] ? '1' : '0'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public static function save(string $type, array $cfg): void
    {
        foreach (['enabled', 'sound', 'push'] as $k) {
            Setting::put("admin_alert.$type.$k", ! empty($cfg[$k]) ? '1' : '0');
        }
        Setting::put("admin_alert.$type.tone", array_key_exists($cfg['tone'] ?? '', Sounds::TONES) ? $cfg['tone'] : 'default');
    }

    /** رابط صوت النغمة في اللوحة — default = صوت «أصوات الإشعارات» للإدارة (أو null = نغمة قصيرة) */
    public static function toneUrl(string $tone): ?string
    {
        return $tone === 'default' ? Sounds::url('admin') : asset("sounds/tone_$tone.wav");
    }
}
