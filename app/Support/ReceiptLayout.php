<?php

namespace App\Support;

use App\Models\Setting;

/**
 * تصميم الواصل: قائمة «قطع» مرتبة (شعار، اسم المتجر، رقم الطلب، الأصناف، الإجمالي...)
 * وكل قطعة عندها حجم خط ومحاذاة وهوامش وإطار... — تتعدّل من «مصمم الواصل» في اللوحة.
 *
 * الأحجام كلها بالنقاط على أساس ورق 384 نقطة (5.5 سم) — التطبيق يكبّرها لورق 8 سم.
 * نفس القواعد مكتوبة في تطبيق المتجر (receipt_widget.dart) وفي معاينة اللوحة.
 */
class ReceiptLayout
{
    public const COPIES = ['store' => 'نسخة المتجر', 'customer' => 'نسخة السائق'];

    /**
     * أنواع القطع: الاسم في اللوحة، ونوعها:
     *  text   — نص ثابت (فيه متغيرات {code}...)
     *  value  — قيمة من الطلب بعنوان قابل للتعديل (سطر: العنوان … القيمة)
     *  items  — جدول الأصناف
     *  divider / spacer / logo
     */
    public const TYPES = [
        'logo'           => ['label' => 'الشعار', 'kind' => 'logo'],
        'store_name'     => ['label' => 'اسم المتجر', 'kind' => 'text'],
        'copy_title'     => ['label' => 'عنوان النسخة', 'kind' => 'text'],
        'text'           => ['label' => 'نص حر', 'kind' => 'text'],
        'order_code'     => ['label' => 'رقم الطلب', 'kind' => 'text'],
        'datetime'       => ['label' => 'التاريخ والوقت', 'kind' => 'text'],
        'items'          => ['label' => 'الأصناف', 'kind' => 'items'],
        'pieces'         => ['label' => 'إجمالي القطع', 'kind' => 'value'],
        'subtotal'       => ['label' => 'مجموع الأصناف', 'kind' => 'value'],
        'delivery_fee'   => ['label' => 'رسوم التوصيل', 'kind' => 'value'],
        'discount'       => ['label' => 'الخصم', 'kind' => 'value'],
        'wallet_paid'    => ['label' => 'مدفوع من المحفظة', 'kind' => 'value'],
        'total'          => ['label' => 'الإجمالي', 'kind' => 'value'],
        'cash_to_collect' => ['label' => 'المبلغ اللي يحصّله السائق', 'kind' => 'value'],
        'payment_method' => ['label' => 'طريقة الدفع', 'kind' => 'value'],
        'customer_name'  => ['label' => 'اسم الزبون', 'kind' => 'value'],
        'customer_phone' => ['label' => 'هاتف الزبون', 'kind' => 'value'],
        'address'        => ['label' => 'العنوان', 'kind' => 'value'],
        'landmark'       => ['label' => 'علامة مميزة', 'kind' => 'value'],
        'driver'         => ['label' => 'اسم السائق', 'kind' => 'value'],
        'customer_notes' => ['label' => 'ملاحظة الزبون', 'kind' => 'value'],
        'divider'        => ['label' => 'خط فاصل', 'kind' => 'divider'],
        'spacer'         => ['label' => 'مسافة فاضية', 'kind' => 'spacer'],
    ];

    /** متغيرات النص الحر */
    public const VARS = [
        '{store}' => 'اسم المتجر', '{code}' => 'رقم الطلب', '{date}' => 'التاريخ', '{time}' => 'الوقت',
        '{customer}' => 'اسم الزبون', '{phone}' => 'هاتف الزبون', '{address}' => 'العنوان',
        '{driver}' => 'السائق', '{total}' => 'الإجمالي', '{cash}' => 'المبلغ نقداً', '{pieces}' => 'عدد القطع',
    ];

    private const PAGE_DEFAULTS = [
        'padding_x' => 8, 'padding_top' => 10, 'padding_bottom' => 18,
        'base_size' => 16, 'line_height' => 1.3, 'weight' => 'medium',
    ];

    public static function get(string $copy): array
    {
        $raw = Setting::get("receipt.layout.$copy");
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) && ! empty($data['blocks'])
            ? self::sanitize($data)
            : self::defaultFor($copy);
    }

    public static function save(string $copy, array $layout): array
    {
        $clean = self::sanitize($layout);
        Setting::put("receipt.layout.$copy", json_encode($clean, JSON_UNESCAPED_UNICODE));

        return $clean;
    }

    public static function reset(string $copy): array
    {
        Setting::forget("receipt.layout.$copy");

        return self::defaultFor($copy);
    }

    /** للتطبيق: التصميمين */
    public static function all(): array
    {
        return collect(array_keys(self::COPIES))->mapWithKeys(fn ($c) => [$c => self::get($c)])->all();
    }

    /**
     * التصميم الافتراضي — مبني من إعدادات الواصل القديمة (العنوان، السطر الأخير، الخيارات)
     * باش اللي ضبطه المدير قبل ما يضيعش.
     */
    public static function defaultFor(string $copy): array
    {
        $store = $copy === 'store';
        $old = fn (string $k, $d) => Setting::get("receipt.$copy.$k", $d);
        $on = fn (string $k, bool $d = true) => filter_var($old($k, $d ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
        $scale = (float) Setting::get('receipt.font_scale', '1') ?: 1;
        $sz = fn (float $v) => (int) round($v * $scale);
        $header = (string) Setting::get('receipt.header', 'توصيل نالوت');

        $b = [];
        if ($on('show_logo', ! $store)) {
            $b[] = ['type' => 'logo', 'height' => 70, 'margin_bottom' => 6];
        }
        $b[] = ['type' => 'store_name', 'size' => $sz(23), 'bold' => true];
        if (trim($header) !== '') {
            $b[] = ['type' => 'text', 'text' => $header, 'size' => $sz(14)];
        }
        $b[] = ['type' => 'copy_title', 'text' => (string) $old('title', $store ? 'نسخة المتجر' : 'نسخة السائق'),
            'size' => $sz(16), 'bold' => true, 'boxed' => true, 'margin_top' => 6];
        $b[] = ['type' => 'divider'];
        $b[] = ['type' => 'order_code', 'size' => $sz(36), 'bold' => true];
        $b[] = ['type' => 'datetime', 'size' => $sz(14)];
        $b[] = ['type' => 'divider'];
        $b[] = ['type' => 'items', 'size' => $sz(17), 'bold' => true, 'show_price' => $on('show_prices'),
            'show_notes' => true, 'show_options' => true];
        $b[] = ['type' => 'divider'];
        if ($on('show_pieces')) {
            $b[] = ['type' => 'pieces', 'bold' => true];
        }
        if ($on('show_prices')) {
            $b[] = ['type' => 'subtotal', 'margin_top' => 6];
            $b[] = ['type' => 'delivery_fee'];
            $b[] = ['type' => 'discount'];
            $b[] = ['type' => 'wallet_paid'];
            $b[] = ['type' => 'total', 'size' => $sz(21), 'bold' => true, 'margin_top' => 5];
        }
        $b[] = ['type' => 'cash_to_collect', 'bold' => true, 'margin_top' => 5, 'layout' => 'inline'];
        $b[] = ['type' => 'divider'];
        if ($on('show_customer')) {
            $b[] = ['type' => 'customer_name', 'layout' => 'inline'];
        }
        if ($on('show_phone')) {
            $b[] = ['type' => 'customer_phone', 'layout' => 'inline'];
        }
        if ($on('show_address')) {
            $b[] = ['type' => 'address', 'layout' => 'inline'];
            $b[] = ['type' => 'landmark', 'layout' => 'inline'];
        }
        if ($on('show_driver')) {
            $b[] = ['type' => 'driver', 'layout' => 'inline'];
        }
        if ($on('show_notes')) {
            $b[] = ['type' => 'customer_notes', 'layout' => 'inline', 'bold' => true, 'margin_top' => 5];
        }
        $b[] = ['type' => 'divider'];
        $b[] = ['type' => 'text', 'text' => (string) $old('footer', $store ? 'راجع الأصناف قبل التسليم' : 'راجع الأصناف مع الزبون عند التسليم'),
            'size' => $sz(15)];

        return self::sanitize(['page' => self::PAGE_DEFAULTS + ['base_size' => $sz(16)], 'blocks' => $b]);
    }

    /** عناوين القطع الافتراضية (تتغيّر من المصمم) */
    public static function defaultLabel(string $type): string
    {
        return match ($type) {
            'customer_name'   => 'الزبون:',
            'customer_phone'  => 'الهاتف:',
            'address'         => 'العنوان:',
            'landmark'        => 'علامة:',
            'driver'          => 'السائق:',
            'customer_notes'  => 'ملاحظة الزبون:',
            'cash_to_collect' => 'يُحصّل نقداً:',
            'payment_method'  => 'الدفع:',
            default           => self::TYPES[$type]['label'] ?? '',
        };
    }

    /** ينظّف التصميم: أنواع معروفة بس، والأرقام في حدودها — اللوحة والتطبيق يعتمدو عليه */
    public static function sanitize(array $layout): array
    {
        $p = (array) ($layout['page'] ?? []);
        $page = [
            'padding_x'      => self::num($p['padding_x'] ?? null, 0, 60, self::PAGE_DEFAULTS['padding_x']),
            'padding_top'    => self::num($p['padding_top'] ?? null, 0, 120, self::PAGE_DEFAULTS['padding_top']),
            'padding_bottom' => self::num($p['padding_bottom'] ?? null, 0, 200, self::PAGE_DEFAULTS['padding_bottom']),
            'base_size'      => self::num($p['base_size'] ?? null, 8, 40, self::PAGE_DEFAULTS['base_size']),
            'line_height'    => round((float) self::num($p['line_height'] ?? null, 0.9, 2.5, self::PAGE_DEFAULTS['line_height'], true), 2),
            'weight'         => in_array($p['weight'] ?? null, ['normal', 'medium', 'bold'], true) ? $p['weight'] : 'medium',
        ];

        $blocks = [];
        foreach (array_slice((array) ($layout['blocks'] ?? []), 0, 80) as $raw) {
            $type = is_array($raw) ? ($raw['type'] ?? null) : null;
            if (! isset(self::TYPES[$type])) {
                continue;
            }
            $kind = self::TYPES[$type]['kind'];

            $b = [
                'type'          => $type,
                'hidden'        => (bool) ($raw['hidden'] ?? false),
                'align'         => in_array($raw['align'] ?? null, ['start', 'center', 'end'], true)
                    ? $raw['align'] : (in_array($kind, ['value', 'items'], true) ? 'start' : 'center'),
                'size'          => self::num($raw['size'] ?? null, 8, 72, $page['base_size']),
                'bold'          => (bool) ($raw['bold'] ?? false),
                'italic'        => (bool) ($raw['italic'] ?? false),
                'underline'     => (bool) ($raw['underline'] ?? false),
                'boxed'         => (bool) ($raw['boxed'] ?? false),
                'inverted'      => (bool) ($raw['inverted'] ?? false),
                'margin_top'    => self::num($raw['margin_top'] ?? null, 0, 80, 0),
                'margin_bottom' => self::num($raw['margin_bottom'] ?? null, 0, 80, 0),
            ];

            if ($kind === 'text') {
                $b['text'] = mb_substr((string) ($raw['text'] ?? ''), 0, 300);
            }
            if ($type === 'datetime') {
                $b['format'] = in_array($raw['format'] ?? null, ['date_time', 'date', 'time'], true) ? $raw['format'] : 'date_time';
            }
            if ($kind === 'value') {
                $b['label'] = mb_substr((string) ($raw['label'] ?? self::defaultLabel($type)), 0, 60);
                $b['layout'] = in_array($raw['layout'] ?? null, ['row', 'inline', 'stacked'], true) ? $raw['layout'] : 'row';
                $b['value_bold'] = (bool) ($raw['value_bold'] ?? $b['bold']);
                $b['currency'] = (bool) ($raw['currency'] ?? in_array($type, ['total', 'cash_to_collect'], true));
            }
            if ($type === 'cash_to_collect') {
                $b['paid_text'] = mb_substr((string) ($raw['paid_text'] ?? 'مدفوع بالكامل — لا تحصّل فلوس'), 0, 100);
            }
            if ($kind === 'items') {
                $b['show_price'] = (bool) ($raw['show_price'] ?? true);
                $b['show_options'] = (bool) ($raw['show_options'] ?? true);
                $b['show_notes'] = (bool) ($raw['show_notes'] ?? true);
                $b['note_size'] = self::num($raw['note_size'] ?? null, 8, 60, max(8, $b['size'] - 2));
                $b['spacing'] = self::num($raw['spacing'] ?? null, 0, 40, 7);
                $b['qty_format'] = in_array($raw['qty_format'] ?? null, ['{qty}×', '{qty} x', 'x{qty}', '({qty})'], true)
                    ? $raw['qty_format'] : '{qty}×';
                $b['row_divider'] = (bool) ($raw['row_divider'] ?? false);
            }
            if ($kind === 'divider') {
                $b['thickness'] = self::num($raw['thickness'] ?? null, 1, 10, 2);
                $b['style'] = in_array($raw['style'] ?? null, ['solid', 'dashed', 'double'], true) ? $raw['style'] : 'solid';
                $b['margin_top'] = self::num($raw['margin_top'] ?? null, 0, 80, 8);
                $b['margin_bottom'] = self::num($raw['margin_bottom'] ?? null, 0, 80, 8);
            }
            if ($kind === 'spacer') {
                $b['height'] = self::num($raw['height'] ?? null, 0, 200, 10);
            }
            if ($kind === 'logo') {
                $b['height'] = self::num($raw['height'] ?? null, 16, 240, 70);
            }

            $blocks[] = $b;
        }

        return ['page' => $page, 'blocks' => $blocks];
    }

    private static function num($v, float $min, float $max, $default, bool $float = false): int|float
    {
        if (! is_numeric($v)) {
            return $default;
        }
        $n = max($min, min($max, (float) $v));

        return $float ? $n : (int) round($n);
    }
}
