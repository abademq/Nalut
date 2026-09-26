<?php

namespace App\Support;

use App\Models\Setting;

/**
 * إعدادات التشغيل اللي تتعدّل من لوحة التحكم (صفحة «إعدادات التشغيل»).
 *
 * كل خيار له قيمة افتراضية — من config/.env لو موجودة — وأي قيمة تحطها الإدارة
 * تتخزّن في جدول settings بمفتاح opt.<key> وتغلب على الافتراضي.
 */
class Options
{
    /**
     * type: int | float | bool | string | list (أرقام مفصولة بفاصلة) | lines (نص سطر لكل عنصر)
     * public: يوصل للتطبيقات عبر /app/content
     */
    public static function definitions(): array
    {
        return [
            // ===== الطلبات =====
            'orders.default_prep_minutes' => ['type' => 'int', 'default' => 20, 'min' => 1, 'max' => 600, 'public' => true,
                'label' => 'مدة التحضير الافتراضية (دقيقة)',
                'help'  => 'تنحط للمتاجر الجديدة، وتكون مختارة مسبقاً في تطبيق المتجر لو المتجر ما حددش مدته.'],
            'orders.prep_choices' => ['type' => 'list', 'default' => '10,15,20,30,45,60', 'public' => true,
                'label' => 'خيارات مدة التحضير في تطبيق المتجر',
                'help'  => 'أرقام بالدقائق مفصولة بفاصلة — مثلاً: 10,15,20,30,45,60'],
            'orders.unpaid_timeout_minutes' => ['type' => 'int', 'default' => config('delivery.unpaid_order_timeout_minutes', 30), 'min' => 5, 'max' => 1440,
                'label' => 'مهلة الدفع الإلكتروني (دقيقة)',
                'help'  => 'الطلب المدفوع بالبطاقة ينلغى تلقائياً لو ما تمّش الدفع خلال المدة هذي.'],
            'orders.reject_reasons' => ['type' => 'lines', 'public' => true,
                'default' => "الصنف خلص\nالمتجر مزدحم توّا\nالمتجر على وشك الغلق\nالعنوان بعيد عن نطاقنا\nمشكلة في الطلب — الزبون يتصل بينا",
                'label' => 'أسباب رفض الطلب في تطبيق المتجر',
                'help'  => 'كل سبب في سطر. المتجر يقدر يكتب سبب آخر كمان.'],
            'orders.customer_cancel_until' => ['type' => 'string', 'default' => 'pending', 'public' => true,
                'choices' => ['pending' => 'قبل ما المتجر يقبل الطلب', 'never' => 'الزبون ما يقدرش يلغي'],
                'label' => 'الزبون يقدر يلغي طلبه'],

            // ===== رموز التحقق =====
            'otp.channel' => ['type' => 'string', 'default' => 'sms',
                'choices' => [
                    'sms'          => 'رسالة نصية (SMS) بس',
                    'whatsapp_sms' => 'واتساب أولاً — ولو فشل رسالة نصية',
                    'whatsapp'     => 'واتساب بس',
                ],
                'label' => 'طريقة إرسال رمز التحقق',
                'help'  => 'واتساب يحتاج إعدادات WHATSAPP_* في .env وقالب تحقق معتمد. الزبون يقدر دائماً يطلب الرمز برسالة نصية.'],

            // ===== التنبيهات الفورية للإدارة =====
            'alerts.pending_minutes' => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 240,
                'label' => 'نبّهني لو طلب ما تقبلش من المتجر خلال (دقيقة)',
                'help'  => '0 = بدون تنبيه'],
            'alerts.no_driver_minutes' => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 240,
                'label' => 'نبّهني لو طلب جاهز وما خذاهش سائق خلال (دقيقة)',
                'help'  => '0 = بدون تنبيه'],
            'alerts.phone' => ['type' => 'string', 'default' => '',
                'label' => 'رقم يوصله التنبيه المهم على واتساب/SMS',
                'help'  => 'اختياري — يحتاج قالب «تنبيهات الإدارة» في «قوالب الرسائل». فاضي = تنبيه اللوحة بس.'],

            'orders.substitution_timeout_minutes' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120,
                'label' => 'مهلة رد الزبون على «صنف مش متوفر» (دقيقة)',
                'help'  => 'لو الزبون ما ردّش خلال المدة: الطلب يكمّل بدون الأصناف الناقصة (ولو ما بقى شي ينلغى).'],

            // ===== سلات الزبون المحفوظة =====
            'carts.saved_enabled' => ['type' => 'bool', 'default' => true, 'public' => true,
                'label' => 'الزبون يقدر يحفظ سلته ويطلبها بعدين'],
            'carts.saved_max' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 50, 'public' => true,
                'label' => 'أقصى عدد سلات محفوظة لكل زبون'],

            // ===== نقاط الولاء =====
            'points.enabled' => ['type' => 'bool', 'default' => false, 'public' => true,
                'label' => 'تفعيل نظام النقاط'],
            'points.earn_mode' => ['type' => 'string', 'default' => 'per_amount', 'public' => true,
                'choices' => ['per_amount' => 'حسب قيمة الطلب', 'per_order' => 'عدد ثابت لكل طلب'],
                'label' => 'طريقة كسب النقاط'],
            'points.earn_rate' => ['type' => 'float', 'default' => 1, 'min' => 0, 'max' => 10000, 'public' => true,
                'label' => 'النقاط المكتسبة',
                'help'  => '«حسب القيمة»: نقاط لكل 1 د.ل من قيمة الأصناف · «لكل طلب»: عدد النقاط لكل طلب مكتمل'],
            'points.min_order' => ['type' => 'float', 'default' => 0, 'min' => 0, 'max' => 10000,
                'label' => 'أقل قيمة طلب يكسب نقاط (د.ل)'],
            'points.redeem_mode' => ['type' => 'string', 'default' => 'wallet', 'public' => true,
                'choices' => ['wallet' => 'تتحوّل لفلوس في المحفظة', 'checkout' => 'يدفع بيها مباشرة في الطلب'],
                'label' => 'استعمال النقاط (خيار واحد بس)'],
            'points.point_value' => ['type' => 'float', 'default' => 0.01, 'min' => 0.0001, 'max' => 100, 'public' => true,
                'label' => 'قيمة النقطة الوحدة (د.ل)',
                'help'  => 'مثلاً 0.01 = كل 100 نقطة بدينار'],
            'points.min_redeem' => ['type' => 'int', 'default' => 100, 'min' => 1, 'max' => 1000000, 'public' => true,
                'label' => 'أقل عدد نقاط للاستعمال'],

            // ===== المخزون =====
            'stock.restore_after_pickup' => ['type' => 'bool', 'default' => false,
                'label' => 'إرجاع المخزون للطلبات اللي فشلت بعد ما استلمها السائق',
                'help'  => 'الطلب اللي ينلغى قبل الاستلام يرجع مخزونه دائماً. بعد الاستلام البضاعة غالباً طلعت من المتجر — فعّل هذا لو السائق يرجّعها.'],
            'stock.low_label_at' => ['type' => 'int', 'default' => 5, 'min' => 0, 'max' => 1000, 'public' => true,
                'label' => 'إظهار «متبقي X فقط» للزبون لما الكمية تنزل لـ',
                'help'  => '0 = ما يطلعش أبداً'],

            // ===== التوصيل =====
            'delivery.base_fee' => ['type' => 'float', 'default' => config('delivery.base_fee', 5), 'min' => 0, 'max' => 1000,
                'label' => 'رسوم التوصيل الأساسية (د.ل)',
                'help'  => 'تُستعمل لو المنطقة ما عندهاش رسوم خاصة.'],
            'delivery.fee_per_km' => ['type' => 'float', 'default' => config('delivery.fee_per_km', 1.5), 'min' => 0, 'max' => 1000,
                'label' => 'رسوم كل كيلومتر (د.ل)',
                'help'  => 'تُستعمل لو المنطقة ما عندهاش رسوم خاصة.'],
            'delivery.free_radius_km' => ['type' => 'float', 'default' => config('delivery.free_radius_km', 1), 'min' => 0, 'max' => 100,
                'label' => 'المسافة المشمولة في الرسوم الأساسية (كم)'],
            'delivery.default_commission_percent' => ['type' => 'float', 'default' => config('delivery.commission_percent', 15), 'min' => 0, 'max' => 100,
                'label' => 'عمولة المنصة الافتراضية للمتاجر الجديدة (%)'],

            // ===== التتبّع =====
            'tracking.refresh_seconds' => ['type' => 'int', 'default' => 5, 'min' => 3, 'max' => 60, 'public' => true,
                'label' => 'تحديث خريطة التتبّع عند الزبون كل (ثانية)',
                'help'  => 'أقل = أسرع لكن ضغط أكثر على السيرفر.'],

            // ===== الكروت =====
            'wallet.card_digits' => ['type' => 'int', 'default' => config('wallet.card_digits', 12), 'min' => 8, 'max' => 16,
                'label' => 'عدد أرقام كروت الشحن الجديدة'],
        ];
    }

    public static function get(string $key): mixed
    {
        $def = static::definitions()[$key] ?? throw new \InvalidArgumentException("خيار غير معروف: $key");

        return static::cast($def, Setting::get("opt.$key", $def['default']));
    }

    /** @return array<int, int> */
    public static function intList(string $key): array
    {
        return static::get($key);
    }

    public static function cast(array $def, mixed $value): mixed
    {
        return match ($def['type']) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'list'  => static::parseList((string) $value),
            'lines' => implode("\n", array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $value))))),
            default => (string) $value,
        };
    }

    /** "10, 15,x,20" → [10,15,20] مرتّبة وبدون تكرار */
    public static function parseList(string $value): array
    {
        $nums = array_filter(array_map('intval', preg_split('/[\s,،]+/u', $value)), fn ($n) => $n > 0);
        $nums = array_values(array_unique($nums));
        sort($nums);

        return $nums;
    }

    /** الخيارات اللي تحتاجها التطبيقات */
    public static function publicValues(): array
    {
        $out = [];
        foreach (static::definitions() as $key => $def) {
            if ($def['public'] ?? false) {
                $out[$key] = static::get($key);
            }
        }

        return $out;
    }
}
