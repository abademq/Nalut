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
                'help' => 'تنحط للمتاجر الجديدة، وتكون مختارة مسبقاً في تطبيق المتجر لو المتجر ما حددش مدته.'],
            'orders.prep_choices' => ['type' => 'list', 'default' => '10,15,20,30,45,60', 'public' => true,
                'label' => 'خيارات مدة التحضير في تطبيق المتجر',
                'help' => 'أرقام بالدقائق مفصولة بفاصلة — مثلاً: 10,15,20,30,45,60'],
            'orders.unpaid_timeout_minutes' => ['type' => 'int', 'default' => config('delivery.unpaid_order_timeout_minutes', 30), 'min' => 5, 'max' => 1440,
                'label' => 'مهلة الدفع الإلكتروني (دقيقة)',
                'help' => 'الطلب المدفوع بالبطاقة ينلغى تلقائياً لو ما تمّش الدفع خلال المدة هذي.'],
            'orders.reject_reasons' => ['type' => 'lines', 'public' => true,
                'default' => "الصنف خلص\nالمتجر مزدحم توّا\nالمتجر على وشك الغلق\nالعنوان بعيد عن نطاقنا\nمشكلة في الطلب — الزبون يتصل بينا",
                'label' => 'أسباب رفض الطلب في تطبيق المتجر',
                'help' => 'كل سبب في سطر. المتجر يقدر يكتب سبب آخر كمان.'],
            'orders.customer_cancel_until' => ['type' => 'string', 'default' => 'pending', 'public' => true,
                'choices' => ['pending' => 'قبل ما المتجر يقبل الطلب', 'never' => 'الزبون ما يقدرش يلغي'],
                'label' => 'الزبون يقدر يلغي طلبه'],

            // ===== رموز التحقق =====
            'otp.channel' => ['type' => 'string', 'default' => 'sms',
                'choices' => [
                    'sms' => 'رسالة نصية (SMS) بس',
                    'whatsapp_sms' => 'واتساب أولاً — ولو فشل رسالة نصية',
                    'whatsapp' => 'واتساب بس',
                ],
                'label' => 'طريقة إرسال رمز التحقق',
                'help' => 'واتساب يحتاج إعدادات WHATSAPP_* في .env وقالب تحقق معتمد. الزبون يقدر دائماً يطلب الرمز برسالة نصية.'],

            // ===== التنبيهات الفورية للإدارة =====
            'alerts.pending_minutes' => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 240,
                'label' => 'نبّهني لو طلب ما تقبلش من المتجر خلال (دقيقة)',
                'help' => '0 = بدون تنبيه'],
            'alerts.no_driver_minutes' => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 240,
                'label' => 'نبّهني لو طلب جاهز وما خذاهش سائق خلال (دقيقة)',
                'help' => '0 = بدون تنبيه'],
            'alerts.phone' => ['type' => 'string', 'default' => '',
                'label' => 'رقم يوصله التنبيه المهم على واتساب/SMS',
                'help' => 'اختياري — يحتاج قالب «تنبيهات الإدارة» في «قوالب الرسائل». فاضي = تنبيه اللوحة بس.'],

            'orders.substitution_timeout_minutes' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120,
                'label' => 'مهلة رد الزبون على «صنف مش متوفر» (دقيقة)',
                'help' => 'لو الزبون ما ردّش خلال المدة: الطلب يكمّل بدون الأصناف الناقصة (ولو ما بقى شي ينلغى).'],

            // ===== سجل النشاط =====
            'logs.enabled' => ['type' => 'bool', 'default' => true,
                'label' => 'تسجيل كل النشاط (التطبيقات ولوحة التحكم)'],
            'logs.client_events' => ['type' => 'bool', 'default' => true, 'public' => true,
                'label' => 'تسجيل الأحداث داخل التطبيقات (فتح متجر، إضافة للسلة، بحث...)'],
            'logs.retention_days' => ['type' => 'int', 'default' => 90, 'min' => 7, 'max' => 3650,
                'label' => 'مدة الاحتفاظ بالسجل (يوم)',
                'help' => 'الأقدم ينمسح تلقائياً كل ليلة باش قاعدة البيانات ما تكبرش.'],

            // ===== حالة السيرفر =====
            'server.traffic_enabled' => ['type' => 'bool', 'default' => true,
                'label' => 'تسجيل عدد الطلبات ومصدرها (لصفحة «حالة السيرفر»)',
                'help' => 'ملف نصي خفيف — ما يثقلش قاعدة البيانات.'],
            'server.traffic_hours' => ['type' => 'int', 'default' => 24, 'min' => 1, 'max' => 168,
                'label' => 'مدة الاحتفاظ بعدّاد الطلبات (ساعة)'],
            'server.slow_ms' => ['type' => 'int', 'default' => 1500, 'min' => 200, 'max' => 30000,
                'label' => 'الطلب يعتبر «بطيء» لو خذا أكثر من (ملي ثانية)'],
            'server.alert_enabled' => ['type' => 'bool', 'default' => true,
                'label' => 'نبّهني في اللوحة لو السيرفر تحت ضغط عالي',
                'help' => 'يتفحص كل 5 دقايق: المعالج، الذاكرة، المساحة، والأخطاء.'],

            // ===== الحماية =====
            'security.recaptcha_admin' => ['type' => 'bool', 'default' => true,
                'label' => 'reCAPTCHA في صفحة دخول لوحة التحكم',
                'help' => 'يشتغل بس لو مفاتيح RECAPTCHA_SITE_KEY و RECAPTCHA_SECRET_KEY موجودة في .env'],
            'security.app_check' => ['type' => 'string', 'default' => 'monitor',
                'choices' => [
                    'off' => 'معطّل',
                    'monitor' => 'مراقبة بس (يعدّ الطلبات المشبوهة بدون منع)',
                    'enforce' => 'منع (يرفض أي طلب مش من التطبيق الأصلي)',
                ],
                'label' => 'حماية التطبيقات (Firebase App Check)',
                'help' => 'يحمي إرسال رموز التحقق والدخول والطلبات من السكربتات. يحتاج FIREBASE_PROJECT_NUMBER في .env. '
                    .'فعّل «منع» بس بعد ما التطبيق ينزل على Google Play ونسبة «موثّق» في «حالة السيرفر» تقرب من 100%.'],

            // ===== الرسائل التسويقية =====
            'marketing.require_opt_in' => ['type' => 'bool', 'default' => true,
                'label' => 'حملات العروض توصل بس للزبائن اللي وافقو عليها بأنفسهم',
                'help' => 'Apple وGoogle يطلبو موافقة صريحة للرسائل التسويقية. الزبون يختار في التطبيق (عند الموافقة على الشروط أو من «حسابي»). إشعارات الطلبات ما تتأثرش.'],

            // ===== تذاكر الدعم =====
            'support.enabled' => ['type' => 'bool', 'default' => true, 'public' => true,
                'label' => 'الزبائن والسائقين يقدرو يفتحو تذكرة دعم من التطبيق',
                'help' => 'لو مطفي، زر «الدعم والمساعدة» يختفي من التطبيقات.'],
            'support.max_open' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 20,
                'label' => 'أقصى عدد تذاكر مفتوحة لنفس الشخص',
                'help' => 'يمنع الإزعاج — لما يقفلو تذكرة يقدرو يفتحو غيرها.'],
            'support.driver_issues' => ['type' => 'bool', 'default' => true,
                'label' => 'بلاغ السائق (السبب اللي «يفتح الدعم») يفتح تذكرة بدل واتساب',
                'help' => 'التذكرة تنفتح مربوطة بالطلب وفيها تفاصيل البلاغ، والسائق يتابع من داخل التطبيق.'],
            'support.auto_close_days' => ['type' => 'int', 'default' => 7, 'min' => 0, 'max' => 90,
                'label' => 'تتقفل التذكرة لحالها لو الإدارة ردّت وما جاش رد بعد (أيام)',
                'help' => '0 = ما تتقفلش لحالها'],

            // ===== لوحة المتجر على الموقع =====
            'merchant.web_enabled' => ['type' => 'bool', 'default' => true,
                'label' => 'أصحاب المتاجر يقدرو يدخلو لوحة متجرهم من الموقع (/merchant)',
                'help' => 'للي عنده آيفون أو يبي يخدم من الكمبيوتر: يعدّل أصنافه وأقسامه ويتابع طلباته. كل واحد يشوف متجره بس.'],
            'merchant.orders' => ['type' => 'bool', 'default' => true,
                'label' => 'يقدرو يستقبلو الطلبات ويغيّرو حالتها من لوحة الموقع',
                'help' => 'لو مطفي، اللوحة للأصناف والأقسام وبيانات المتجر بس، والطلبات من التطبيق.'],

            // ===== موقع الطلب =====
            'web.enabled' => ['type' => 'bool', 'default' => true,
                'label' => 'موقع الطلب شغّال (للآيفون والكمبيوتر)',
                'help' => 'لو مطفي، الموقع يطلع رسالة «الخدمة موقوفة مؤقتاً» مع روابط التطبيق.'],
            'web.notice' => ['type' => 'string', 'default' => '',
                'label' => 'رسالة فوق في موقع الطلب (اختياري)'],

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
                'help' => '«حسب القيمة»: نقاط لكل 1 د.ل من قيمة الأصناف · «لكل طلب»: عدد النقاط لكل طلب مكتمل'],
            'points.min_order' => ['type' => 'float', 'default' => 0, 'min' => 0, 'max' => 10000,
                'label' => 'أقل قيمة طلب يكسب نقاط (د.ل)'],
            'points.redeem_mode' => ['type' => 'string', 'default' => 'wallet', 'public' => true,
                'choices' => ['wallet' => 'تتحوّل لفلوس في المحفظة', 'checkout' => 'يدفع بيها مباشرة في الطلب'],
                'label' => 'استعمال النقاط (خيار واحد بس)'],
            'points.point_value' => ['type' => 'float', 'default' => 0.01, 'min' => 0.0001, 'max' => 100, 'public' => true,
                'label' => 'قيمة النقطة الوحدة (د.ل)',
                'help' => 'مثلاً 0.01 = كل 100 نقطة بدينار'],
            'points.min_redeem' => ['type' => 'int', 'default' => 100, 'min' => 1, 'max' => 1000000, 'public' => true,
                'label' => 'أقل عدد نقاط للاستعمال'],

            // ===== المتاجر =====
            'stores.manual_open_max_hours' => ['type' => 'int', 'default' => 6, 'min' => 1, 'max' => 24,
                'label' => '«افتح توّا» خارج ساعات العمل: يقعد مفتوح لحد (ساعات)',
                'help' => 'لو المتجر فتح بدري يقعد مفتوح لين وقت فتحه العادي. لو فتح بعد ما سكّر، يتسكّر لحاله بعد الساعات هذي (لو نسي) — يقدر يعاود يفتح.'],

            // ===== المخزون =====
            'stock.restore_after_pickup' => ['type' => 'bool', 'default' => false,
                'label' => 'إرجاع المخزون للطلبات اللي فشلت بعد ما استلمها السائق',
                'help' => 'الطلب اللي ينلغى قبل الاستلام يرجع مخزونه دائماً. بعد الاستلام البضاعة غالباً طلعت من المتجر — فعّل هذا لو السائق يرجّعها.'],
            'stock.low_label_at' => ['type' => 'int', 'default' => 5, 'min' => 0, 'max' => 1000, 'public' => true,
                'label' => 'إظهار «متبقي X فقط» للزبون لما الكمية تنزل لـ',
                'help' => '0 = ما يطلعش أبداً'],

            // ===== الإعلانات =====
            'banners.section_fallback' => ['type' => 'bool', 'default' => true, 'public' => true,
                'label' => 'القسم اللي ما عندوش إعلانات خاصة: نوريو إعلانات الرئيسية',
                'help' => 'لو مطفي، القسم اللي ما عندوش إعلانات يطلع بدون شريط إعلانات'],

            // ===== التوصيل =====
            'delivery.base_fee' => ['type' => 'float', 'default' => config('delivery.base_fee', 5), 'min' => 0, 'max' => 1000,
                'label' => 'رسوم التوصيل الأساسية (د.ل)',
                'help' => 'تُستعمل لو المنطقة ما عندهاش رسوم خاصة.'],
            'delivery.fee_per_km' => ['type' => 'float', 'default' => config('delivery.fee_per_km', 1.5), 'min' => 0, 'max' => 1000,
                'label' => 'رسوم كل كيلومتر (د.ل)',
                'help' => 'تُستعمل لو المنطقة ما عندهاش رسوم خاصة.'],
            'delivery.free_radius_km' => ['type' => 'float', 'default' => config('delivery.free_radius_km', 1), 'min' => 0, 'max' => 100,
                'label' => 'المسافة المشمولة في الرسوم الأساسية (كم)'],
            'delivery.default_commission_percent' => ['type' => 'float', 'default' => config('delivery.commission_percent', 15), 'min' => 0, 'max' => 100,
                'label' => 'عمولة المنصة الافتراضية للمتاجر الجديدة (%)'],
            'delivery.driver_share_percent' => ['type' => 'float', 'default' => 100, 'min' => 0, 'max' => 100,
                'label' => 'نصيب السائق من رسوم التوصيل (%)',
                'help' => '100 = الرسوم كاملة للسائق. مثلاً 80 = 80% للسائق و20% للمنصة. ينطبق على الطلبات الجديدة بس.'],
            'delivery.handover_wait_minutes' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 60, 'public' => true,
                'label' => 'مدة انتظار الزبون عند الباب (دقائق)',
                'help' => 'لما السائق يضغط «وصلت»، الزبون يشوف مؤقت بالمدة هذي. لو ما استلمش، السائق يقدر يحط الطلب أمام الباب ويصوّره كإثبات.'],
            'delivery.leave_at_door_cash' => ['type' => 'bool', 'default' => false, 'public' => true,
                'label' => 'السماح بترك الطلبات النقدية أمام الباب',
                'help' => 'مقفول (الافتراضي): الطلب اللي فيه فلوس يحصّلها السائق ما يتركش أمام الباب — السائق يستعمل «تعذّر التسليم». مفتوح: يتركه حتى لو ما خذاش الفلوس.'],

            // ===== الاستلام من المطعم =====
            'pickup.enabled' => ['type' => 'bool', 'default' => true, 'public' => true,
                'label' => 'الزبون يقدر يختار «استلام من المطعم»',
                'help' => 'بدون سائق ولا رسوم توصيل. بالدفع الإلكتروني بس. كل متجر يقدر يقفلها من إعداداته.'],
            'pickup.allow_wallet' => ['type' => 'bool', 'default' => true, 'public' => true,
                'label' => 'الدفع من المحفظة مسموح في الاستلام',
                'help' => 'لو مطفي: بطاقة بس.'],
            'pickup.require_code' => ['type' => 'bool', 'default' => true, 'public' => true,
                'label' => 'المتجر يدخل رمز الزبون قبل التسليم',
                'help' => 'الرمز (4 أرقام) يطلع للزبون في صفحة الطلب. يمنع إن حد ثاني ياخذ الطلب.'],

            // ===== التسويات =====
            'settlement.company_name' => ['type' => 'string', 'default' => 'شركة القمرة المظلمة لخدمات التكنولوجيا وتقنية المعلومات',
                'label' => 'اسم الشركة في واصل التسوية'],
            'settlement.prefix' => ['type' => 'string', 'default' => 'ST-',
                'label' => 'بادئة رقم التسوية', 'help' => 'مثلاً ST- ← ST-000125'],
            'settlement.footer' => ['type' => 'string', 'default' => 'هذا الواصل إثبات للمبلغ المذكور أعلاه. يُرجى الاحتفاظ به.',
                'label' => 'نص أسفل واصل التسوية'],
            'settlement.show_orders' => ['type' => 'bool', 'default' => true,
                'label' => 'قائمة الطلبات في واصل التسوية (ورق A4)'],

            // ===== ما يظهر للمتجر والسائق (صفحة «ما يظهر للمتجر والسائق») =====
            'show.store.customer_name' => ['type' => 'bool', 'default' => true, 'label' => 'اسم الزبون'],
            'show.store.customer_phone' => ['type' => 'bool', 'default' => true, 'label' => 'رقم الزبون'],
            'show.store.customer_address' => ['type' => 'bool', 'default' => true, 'label' => 'عنوان الزبون'],
            'show.store.item_prices' => ['type' => 'bool', 'default' => true, 'label' => 'أسعار الأصناف'],
            'show.store.order_total' => ['type' => 'bool', 'default' => true, 'label' => 'اللي يدفعه الزبون (التوصيل، الخصم، الإجمالي، طريقة الدفع)'],
            'show.store.commission' => ['type' => 'bool', 'default' => true, 'label' => 'عمولة المنصة'],
            'show.store.store_net' => ['type' => 'bool', 'default' => true, 'label' => 'صافي المتجر من الطلب'],
            'show.store.driver_earning' => ['type' => 'bool', 'default' => false, 'label' => 'أجرة السائق'],
            'show.store.driver' => ['type' => 'bool', 'default' => true, 'label' => 'اسم ورقم السائق'],
            'show.store.balance' => ['type' => 'bool', 'default' => true, 'label' => 'رصيده والتسويات (حسابي)'],

            'show.driver.customer_name' => ['type' => 'bool', 'default' => true, 'label' => 'اسم الزبون'],
            'show.driver.customer_phone' => ['type' => 'bool', 'default' => true, 'label' => 'رقم الزبون'],
            'show.driver.items' => ['type' => 'bool', 'default' => true, 'label' => 'الأصناف'],
            'show.driver.item_prices' => ['type' => 'bool', 'default' => true, 'label' => 'أسعار الأصناف'],
            'show.driver.order_total' => ['type' => 'bool', 'default' => true, 'label' => 'تفصيل اللي يدفعه الزبون (الأصناف، التوصيل، الخصم، الإجمالي)'],
            'show.driver.store_net' => ['type' => 'bool', 'default' => false, 'label' => 'صافي المتجر من الطلب'],
            'show.driver.commission' => ['type' => 'bool', 'default' => false, 'label' => 'عمولة المنصة'],
            'show.driver.earning' => ['type' => 'bool', 'default' => true, 'label' => 'أجرته من الطلب'],
            'show.driver.store_phone' => ['type' => 'bool', 'default' => true, 'label' => 'رقم المتجر'],
            'show.driver.notes' => ['type' => 'bool', 'default' => true, 'label' => 'ملاحظات الزبون'],
            'show.driver.balance' => ['type' => 'bool', 'default' => true, 'label' => 'رصيده والتسويات (حسابي)'],

            // ===== التتبّع =====
            'tracking.map_last_seen_hours' => ['type' => 'int', 'default' => 24, 'min' => 0, 'max' => 168,
                'label' => 'خريطة اللوحة: نوري آخر موقع للسائق غير المتاح لمدة (ساعات)',
                'help' => 'السائق اللي طفّى يطلع رمادي في آخر مكان كان فيه. 0 = ما نوروش إلا المتاحين.'],
            'tracking.route_url' => ['type' => 'string', 'default' => 'https://router.project-osrm.org', 'public' => true,
                'label' => 'خدمة رسم المسار في خريطة السائق (OSRM)',
                'help' => 'الافتراضي الخادم التجريبي المجاني (للاستعمال الخفيف). لو كثرت الطلبات حط رابط خادم OSRM خاص بيكم.'],
            'tracking.refresh_seconds' => ['type' => 'int', 'default' => 5, 'min' => 3, 'max' => 60, 'public' => true,
                'label' => 'تحديث خريطة التتبّع عند الزبون كل (ثانية)',
                'help' => 'أقل = أسرع لكن ضغط أكثر على السيرفر.'],

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
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'list' => static::parseList((string) $value),
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
