<?php

namespace App\Support;

use App\Models\OrderIssue;
use App\Models\Setting;

/**
 * قرارات الرد على بلاغ السائق (يكمّل · سائق آخر · فشل · إلغاء) — تتعدّل من
 * «الإعدادات ← قرارات البلاغات والتسليم»: الاسم، مفعّل ولا لا، والرسالة اللي توصل للسائق وللزبون.
 */
class IssueDecisions
{
    /** الافتراضي: [مفعّل, رسالة السائق, رسالة الزبون] — الاسم من OrderIssue::RESOLUTIONS */
    public const DEFAULTS = [
        // فاضي = النص الافتراضي من «النصوص» (notify.driver.review_continue / reassigned_away)
        'continue' => [true, '', ''],
        'reassign' => [true, '', ''],
        'failed' => [true, '', ''],
        'cancelled' => [true, '', ''],
    ];

    /** @return array{key: string, label: string, enabled: bool, driver_message: string, customer_message: string} */
    public static function get(string $key): array
    {
        $d = self::DEFAULTS[$key] ?? [false, '', ''];

        return [
            'key' => $key,
            'label' => trim((string) Setting::get("issue_decision.$key.label", '')) ?: (OrderIssue::RESOLUTIONS[$key] ?? $key),
            'enabled' => filter_var(Setting::get("issue_decision.$key.enabled", $d[0] ? '1' : '0'), FILTER_VALIDATE_BOOLEAN),
            'driver_message' => (string) Setting::get("issue_decision.$key.driver_message", $d[1]),
            'customer_message' => (string) Setting::get("issue_decision.$key.customer_message", $d[2]),
        ];
    }

    /** @return array<string, array> كل القرارات (للصفحة) */
    public static function all(): array
    {
        return collect(array_keys(OrderIssue::RESOLUTIONS))->mapWithKeys(fn ($k) => [$k => self::get($k)])->all();
    }

    /** @return array<string, string> المفعّلة بس: key => الاسم (للقوائم في اللوحة وتطبيق الإدارة) */
    public static function options(): array
    {
        return collect(self::all())->filter(fn ($d) => $d['enabled'])->map(fn ($d) => $d['label'])->all();
    }

    public static function label(?string $key): string
    {
        return $key ? self::get($key)['label'] : '—';
    }

    public static function save(string $key, array $data): void
    {
        foreach (['label', 'driver_message', 'customer_message'] as $f) {
            Setting::put("issue_decision.$key.$f", trim((string) ($data[$f] ?? '')));
        }
        Setting::put("issue_decision.$key.enabled", ! empty($data['enabled']) ? '1' : '0');
    }
}
