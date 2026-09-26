<?php

namespace App\Support;

use App\Models\AppText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * نصوص قابلة للتعديل من لوحة التحكم («النصوص»).
 *
 * نصوص السيرفر (الإشعارات، حالات الطلب، رسائل الأخطاء) معرّفة هنا بمفاتيح ثابتة.
 * نصوص التطبيقات تجي من كتالوج resources/app-texts/<app>.json (مستخرج من كود التطبيقات)
 * والتطبيق يطلب التعديلات عبر /app/content.
 *
 * المتغيرات تنكتب بين أقواس: {code} {prep} ... — لو المتغير فاضي تنشال الفواصل اللي قبله.
 */
class Texts
{
    /** @return array<string, array{0: string, 1: string, 2?: string}> key => [group, default, vars] */
    public static function serverDefinitions(): array
    {
        $cancel = '{reason} = سبب الإلغاء (لو موجود)';

        return [
            // ===== حالات الطلب — تظهر في كل التطبيقات واللوحة =====
            'status.pending'      => ['حالات الطلب', 'بانتظار قبول المتجر'],
            'status.accepted'     => ['حالات الطلب', 'تم القبول'],
            'status.preparing'    => ['حالات الطلب', 'قيد التحضير'],
            'status.ready'        => ['حالات الطلب', 'جاهز للاستلام'],
            'status.assigned'     => ['حالات الطلب', 'أُسند لسائق'],
            'status.picked_up'    => ['حالات الطلب', 'استلمه السائق'],
            'status.on_the_way'   => ['حالات الطلب', 'في الطريق إليك'],
            'status.delivered'    => ['حالات الطلب', 'تم التسليم'],
            'status.cancelled'    => ['حالات الطلب', 'ملغي'],
            'status.failed'       => ['حالات الطلب', 'فشل التسليم'],
            'status.under_review' => ['حالات الطلب', 'قيد مراجعة الإدارة'],
            'status.awaiting_customer' => ['حالات الطلب', 'بانتظار رد الزبون على الأصناف الناقصة'],

            // ===== أصناف مش متوفرة =====
            'notify.customer.substitution' => ['إشعارات الزبون', 'المتجر: {items} مش متوفر توّا — كمّل بدونه أو عدّل طلبك خلال {minutes} دقيقة', '{items} الأصناف · {minutes} المهلة'],
            'notify.store.substitution_continue' => ['إشعارات المتجر', 'الزبون وافق يكمّل الطلب بدون الأصناف الناقصة'],
            'notify.store.substitution_edit' => ['إشعارات المتجر', 'الزبون ألغى الطلب باش يعدّله — بيوصلك طلب جديد'],
            'msg.substitution_pending' => ['رسائل الطلب', 'الطلب يستنى رد الزبون على الأصناف الناقصة.'],
            'msg.substitution_cancel_reason' => ['رسائل الطلب', 'الزبون ألغى الطلب باش يعدّله'],
            'msg.substitution_empty_reason' => ['رسائل الطلب', 'كل الأصناف مش متوفرة'],

            // ===== عنوان كل إشعارات الطلبات =====
            'notify.title' => ['إشعارات عامة', 'طلب {code}', '{code} = رقم الطلب'],

            // ===== إشعارات الزبون =====
            'notify.customer.pending'    => ['إشعارات الزبون', 'استلمنا طلبك، بانتظار موافقة المتجر'],
            'notify.customer.accepted'   => ['إشعارات الزبون', 'المتجر قبل طلبك — جاهز خلال {prep} دقيقة', '{prep} = مدة التحضير بالدقائق'],
            'notify.customer.preparing'  => ['إشعارات الزبون', 'المتجر قبل طلبك وبدا التحضير — سيكون جاهز خلال {prep} دقيقة تقديرياً', '{prep} = مدة التحضير بالدقائق'],
            'notify.customer.ready'      => ['إشعارات الزبون', 'طلبك جاهز، السائق في الطريق للمتجر'],
            'notify.customer.assigned'   => ['إشعارات الزبون', 'أُسند طلبك للسائق {driver}', '{driver} = اسم السائق'],
            'notify.customer.picked_up'  => ['إشعارات الزبون', 'السائق استلم طلبك من المتجر'],
            'notify.customer.on_the_way' => ['إشعارات الزبون', 'السائق في الطريق إليك'],
            'notify.customer.delivered'  => ['إشعارات الزبون', 'تم تسليم طلبك — شكراً لك'],
            'notify.customer.cancelled'  => ['إشعارات الزبون', 'تم إلغاء طلبك: {reason}', $cancel],
            'notify.customer.failed'     => ['إشعارات الزبون', 'ما نجحش تسليم طلبك: {reason}', $cancel],
            'notify.customer.under_review' => ['إشعارات الزبون', 'صار ظرف مع السائق وطلبك قيد مراجعة الإدارة — نتواصلو معاك قريب'],

            // ===== إشعارات المتجر =====
            'notify.store.new_order'  => ['إشعارات المتجر', 'طلب جديد', 'عنوان إشعار الطلب الجديد'],
            'notify.store.new_order_body' => ['إشعارات المتجر', 'طلب رقم {code} — افتح التطبيق', '{code} = رقم الطلب'],
            'notify.store.pending'    => ['إشعارات المتجر', 'طلب جديد وصلك — افتح التطبيق'],
            'notify.store.accepted'   => ['إشعارات المتجر', 'تم قبول الطلب'],
            'notify.store.preparing'  => ['إشعارات المتجر', 'الطلب قيد التحضير'],
            'notify.store.ready'      => ['إشعارات المتجر', 'الطلب جاهز، بانتظار السائق'],
            'notify.store.assigned'   => ['إشعارات المتجر', 'السائق {driver} في الطريق ليك', '{driver} = اسم السائق'],
            'notify.store.picked_up'  => ['إشعارات المتجر', 'استلمه السائق وخرج'],
            'notify.store.on_the_way' => ['إشعارات المتجر', 'السائق في الطريق للزبون'],
            'notify.store.delivered'  => ['إشعارات المتجر', 'تم تسليم الطلب للزبون'],
            'notify.store.cancelled'  => ['إشعارات المتجر', 'تم إلغاء الطلب: {reason}', $cancel],
            'notify.store.failed'     => ['إشعارات المتجر', 'فشل تسليم الطلب: {reason}', $cancel],

            // ===== إشعارات السائق =====
            'notify.driver.available_title' => ['إشعارات السائق', 'طلب جديد متاح'],
            'notify.driver.available' => ['إشعارات السائق', '{store} — أجرتك {earning} د.ل · {distance} كم', '{store} المتجر · {earning} الأجرة · {distance} المسافة'],
            'notify.driver.assigned'  => ['إشعارات السائق', '{store} ← {address}', '{store} المتجر · {address} عنوان الزبون'],
            'notify.driver.ready'     => ['إشعارات السائق', 'الطلب جاهز في المتجر — تقدر تستلمه توّا'],
            'notify.driver.picked_up' => ['إشعارات السائق', 'سجّلت استلام الطلب من المتجر'],
            'notify.driver.on_the_way'=> ['إشعارات السائق', 'أنت في الطريق للزبون'],
            'notify.driver.delivered' => ['إشعارات السائق', 'تم تسليم الطلب — أجرتك {earning} د.ل', '{earning} = أجرة السائق'],
            'notify.driver.cancelled' => ['إشعارات السائق', 'الطلب انلغى: {reason}', $cancel],
            'notify.driver.failed'    => ['إشعارات السائق', 'تم تسجيل فشل التسليم'],
            'notify.driver.review_continue' => ['إشعارات السائق', 'الإدارة راجعت البلاغ — كمّل التوصيل'],
            'notify.driver.reassigned_away' => ['إشعارات السائق', 'الإدارة حوّلت الطلب لسائق آخر'],

            // ===== رسائل تظهر للمستخدمين =====
            'msg.store_closed'       => ['رسائل الطلب', 'المتجر مغلق توّا.'],
            'msg.min_order'          => ['رسائل الطلب', 'الحد الأدنى للطلب من هذا المتجر {min} د.ل', '{min} = الحد الأدنى'],
            'msg.out_of_coverage'    => ['رسائل الطلب', 'عذراً، خدمتنا غير متوفرة في منطقتك حالياً.'],
            'msg.coupon_invalid'     => ['رسائل الطلب', 'الكوبون غير صالح.'],
            'msg.wallet_insufficient'=> ['رسائل الطلب', 'رصيد المحفظة ما يكفيش. المتوفر: {balance} د.ل', '{balance} = الرصيد'],
            'msg.cart_empty'         => ['رسائل الطلب', 'السلة فارغة.'],
            'msg.product_missing'    => ['رسائل الطلب', 'منتج في سلتك ما عادش موجود. شيله من السلة.'],
            'msg.product_unavailable'=> ['رسائل الطلب', '«{name}» مش متوفر توّا. شيله من السلة.', '{name} = اسم المنتج'],
            'msg.max_per_order'      => ['رسائل الطلب', 'أقصى كمية من «{name}» في الطلب الواحد {max}.', '{name} المنتج · {max} الحد'],
            'msg.stock_left'         => ['رسائل الطلب', 'المتوفر من «{name}» توّا {left} فقط.', '{name} المنتج · {left} المتبقي'],
            'msg.stock_out'          => ['رسائل الطلب', '«{name}» خلص من المخزن.', '{name} = اسم المنتج'],
            'msg.stock_race_left'    => ['رسائل الطلب', 'المتوفر من «{name}» توّا {left} فقط — حد طلبه قبلك. عدّل الكمية في السلة.', '{name} المنتج · {left} المتبقي'],
            'msg.stock_race_out'     => ['رسائل الطلب', '«{name}» خلص من المخزن — حد طلب آخر قطعة قبلك.', '{name} = اسم المنتج'],
            'msg.option_required'    => ['رسائل الطلب', 'اختيار «{option}» مطلوب في «{name}».', '{option} الخيار · {name} المنتج'],
            'msg.option_too_many'    => ['رسائل الطلب', 'تجاوزت الحد المسموح في «{option}».', '{option} = الخيار'],
            'msg.cancel_too_late'    => ['رسائل الطلب', 'المتجر بدا يحضّر طلبك — ما عادش ينلغى. تواصل مع المتجر.'],
            'msg.cancel_disabled'    => ['رسائل الطلب', 'إلغاء الطلب من التطبيق مش متاح. تواصل مع الدعم.'],
            'msg.cancelled_by_customer' => ['رسائل الطلب', 'ألغاه الزبون'],
            'msg.unpaid_cancel_reason'  => ['رسائل الطلب', 'ما تمّش الدفع الإلكتروني خلال {minutes} دقيقة', '{minutes} = المهلة'],
            'msg.awaiting_payment'   => ['رسائل الطلب', 'الطلب بانتظار تأكيد الدفع الإلكتروني.'],
            'msg.under_review_block' => ['رسائل الطلب', 'الطلب قيد مراجعة الإدارة — استنى قرارهم.'],
        ];
    }

    /** نص من نصوص السيرفر بعد التعديل وتعويض المتغيرات */
    public static function get(string $key, array $vars = []): string
    {
        $template = static::overrides('server')[$key]
            ?? static::serverDefinitions()[$key][1]
            ?? $key;

        return static::fill($template, $vars);
    }

    /** تعويض {var} — والمتغير الفاضي ينشال مع الفاصل اللي قبله («تم الإلغاء: {reason}» → «تم الإلغاء») */
    public static function fill(string $template, array $vars): string
    {
        $out = preg_replace_callback('/\s*([:：\-—–،,·]?)\s*\{(\w+)\}/u', function ($m) use ($vars) {
            $value = $vars[$m[2]] ?? null;

            if ($value === null || $value === '') {
                return array_key_exists($m[2], $vars) ? '' : $m[0];
            }

            return str_replace('{'.$m[2].'}', (string) $value, $m[0]);
        }, $template);

        return trim($out);
    }

    /** @return array<string, string> key => النص المعدّل (المعدّل بس) */
    public static function overrides(string $app): array
    {
        // ذاكرة قصيرة داخل العملية — الـ worker يعيش طويل، فما نثبتوش أكثر من ثواني
        $hit = static::$memo[$app] ?? null;
        if ($hit && $hit[0] > microtime(true)) {
            return $hit[1];
        }

        $data = Cache::rememberForever("texts.$app", function () use ($app) {
            if (! Schema::hasTable('app_texts')) {
                return [];
            }

            return AppText::for($app)->whereNotNull('value')->pluck('value', 'key')->all();
        });

        static::$memo[$app] = [microtime(true) + 10, $data];

        return $data;
    }

    /** @var array<string, array{0: float, 1: array}> */
    private static array $memo = [];

    public static function flush(string $app): void
    {
        Cache::forget("texts.$app");
        unset(static::$memo[$app]);
    }

    /** أسماء الشاشات كما تظهر في اللوحة (اسم الملف في التطبيق ← اسم مفهوم) */
    public const SCREENS = [
        'login_screen' => 'الدخول والتسجيل', 'home_screen' => 'الرئيسية', 'store_screen' => 'صفحة المتجر',
        'cart_screen' => 'السلة والدفع', 'order_tracking_screen' => 'تتبّع الطلب', 'orders_screen' => 'طلباتي',
        'addresses_screen' => 'العناوين', 'address_picker_screen' => 'اختيار العنوان على الخريطة',
        'profile_screen' => 'حسابي', 'wallet_screen' => 'المحفظة', 'about_screen' => 'عن التطبيق',
        'orders_tab' => 'الطلبات', 'order_history_screen' => 'سجل الطلبات', 'products_tab' => 'المنتجات',
        'product_form_screen' => 'إضافة وتعديل منتج', 'settings_tab' => 'إعدادات المتجر',
        'printer_settings_screen' => 'إعدادات الطابعة', 'receipt_widget' => 'الواصل المطبوع',
        'receipt_service' => 'الطباعة', 'daily_report' => 'التقرير المطبوع', 'daily_report_screen' => 'تقرير اليوم',
        'earnings_tab' => 'الأرباح', 'my_orders_tab' => 'طلباتي', 'available_tab' => 'الطلبات المتاحة',
        'order_card' => 'بطاقة الطلب', 'push' => 'الإشعارات', 'main' => 'عام', 'wallet_models' => 'المحفظة',
        'api' => 'أخطاء الاتصال', 'models' => 'عام', 'cart' => 'السلة', 'quick_location' => 'الموقع',
    ];

    /** كتالوج نصوص تطبيق — مستخرج من كوده */
    public static function catalog(string $app): array
    {
        $path = resource_path("app-texts/$app.json");

        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }

    /**
     * يزامن جدول app_texts مع الكود: يضيف الجديد، يحدّث الأصل، ويعلّم اللي اختفى.
     * التعديلات (value) ما تنمسحش أبداً.
     *
     * @return array<string, int> عدد النصوص لكل تطبيق
     */
    public static function sync(): array
    {
        $result = [];

        $sets = ['server' => collect(static::serverDefinitions())->map(fn ($d, $key) => [
            'key' => $key, 'group' => $d[0], 'default' => $d[1], 'vars' => $d[2] ?? null,
        ])->values()->all()];

        foreach (['customer', 'store', 'driver'] as $app) {
            $sets[$app] = array_map(fn ($t) => [
                'key' => $t['text'], 'group' => static::SCREENS[$t['group']] ?? $t['group'],
                'default' => $t['text'], 'vars' => $t['vars'] ?? null,
            ], static::catalog($app));
        }

        foreach ($sets as $app => $items) {
            $seen = [];

            foreach ($items as $item) {
                $hash = md5($item['key']);
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;

                $row = AppText::firstOrNew(['app' => $app, 'key_hash' => $hash]);
                $row->fill([
                    'key'     => $item['key'],
                    'group'   => mb_substr($item['group'], 0, 100),
                    'default' => $item['default'],
                    'vars'    => $item['vars'] ? mb_substr($item['vars'], 0, 255) : null,
                    'is_used' => true,
                ]);
                $row->save();
            }

            AppText::for($app)->whereNotIn('key_hash', array_keys($seen))->update(['is_used' => false]);
            static::flush($app);
            $result[$app] = count($seen);
        }

        return $result;
    }
}
