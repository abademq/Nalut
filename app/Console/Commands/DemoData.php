<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\AppSection;
use App\Models\Coupon;
use App\Models\DeliveryZone;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReadyCart;
use App\Models\RechargeCard;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\GeoService;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * بيانات تجريبية لاختبار كل السيناريوهات: متاجر في كل الأقسام، أصناف بإضافات وكميات،
 * حسابات زبائن وسائقين ومتاجر، كوبونات وكروت شحن.
 *
 *   php artisan demo:seed            إضافة (أو إعادة بناء) البيانات التجريبية
 *   php artisan demo:seed --remove   مسح كل شي تجريبي (المتاجر، الحسابات، طلباتهم...)
 *
 * كل شي تجريبي معلّم: المتاجر slug يبدا بـ demo- · الحسابات بريدها @demo.test · الكوبونات تبدا بـ DEMO · الكروت batch=DEMO
 */
class DemoData extends Command
{
    protected $signature = 'demo:seed {--remove : مسح البيانات التجريبية} {--force : بدون سؤال}';

    protected $description = 'Create (or remove) demo stores, products and accounts for testing';

    public const PASSWORD = 'Demo@2026';

    private const CENTER = [31.8686, 10.9817]; // نالوت

    public function handle(WalletService $wallets): int
    {
        if ($this->option('remove')) {
            if (! $this->option('force') && ! $this->confirm('نمسحو كل البيانات التجريبية وطلباتها؟')) {
                return self::FAILURE;
            }
            $this->remove();
            $this->info('تم مسح البيانات التجريبية.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && app()->environment('production')
            && ! $this->confirm('هذا سيرفر الإنتاج — المتاجر التجريبية حتبان للزبائن لين تمسحها. نكمّلو؟')) {
            return self::FAILURE;
        }

        // إعادة بناء نظيفة
        $this->remove(quiet: true);

        DB::transaction(function () use ($wallets) {
            $meta = ['sections' => [], 'types' => [], 'zones' => []];
            $this->ensureZone($meta);
            $types = $this->ensureSections($meta);

            $accounts = $this->accounts($wallets);
            $this->stores($types, $accounts);
            $this->coupons();
            $this->cards();

            Setting::put('demo.meta', json_encode($meta));
        });

        $this->printSummary();

        return self::SUCCESS;
    }

    // ===================== الأساسيات =====================

    private function ensureZone(array &$meta): void
    {
        [$lat, $lng] = self::CENTER;
        $covers = DeliveryZone::where('is_active', true)->get()
            ->contains(fn ($z) => GeoService::airDistanceKm($lat, $lng, (float) $z->center_lat, (float) $z->center_lng) <= (float) $z->radius_km);

        if (! $covers) {
            $meta['zones'][] = DeliveryZone::create([
                'name' => 'نالوت (تجريبي)', 'center_lat' => $lat, 'center_lng' => $lng, 'radius_km' => 12, 'is_active' => true,
            ])->id;
        }
    }

    /** @return array<string, int> [مفتاح => رقم نوع المتجر] */
    private function ensureSections(array &$meta): array
    {
        $wanted = [
            'food' => ['مطاعم', '🍔', '#FF7043', 'مطاعم'],
            'sweets' => ['حلويات ومخابز', '🍰', '#EC407A', 'حلويات ومخابز'],
            'market' => ['سوبرماركت', '🛒', '#66BB6A', 'مواد غذائية'],
            'online' => ['متاجر إلكترونية', '📱', '#42A5F5', 'إلكترونيات'],
        ];
        $types = [];
        $sort = (int) AppSection::max('sort');

        foreach ($wanted as $key => [$name, $emoji, $color, $typeName]) {
            $section = AppSection::where('name', $name)->first();
            if (! $section) {
                $section = AppSection::create(['name' => $name, 'emoji' => $emoji, 'color' => $color, 'is_active' => true, 'sort' => ++$sort]);
                $meta['sections'][] = $section->id;
            }
            $type = StoreType::where('app_section_id', $section->id)->where('is_active', true)->orderBy('sort')->first();
            if (! $type) {
                $type = StoreType::create(['name' => $typeName, 'icon' => $emoji, 'is_active' => true, 'app_section_id' => $section->id, 'sort' => 1]);
                $meta['types'][] = $type->id;
            }
            $types[$key] = $type->id;
        }

        // أي قسم ثاني موجود في المنصة: متجر عام واحد فيه باش كل الأقسام تتجرب
        foreach (AppSection::where('is_active', true)->whereNotIn('name', array_column($wanted, 0))->get() as $other) {
            $type = StoreType::where('app_section_id', $other->id)->where('is_active', true)->first();
            if ($type) {
                $types['extra-'.$other->id] = $type->id;
            }
        }

        return $types;
    }

    private function user(string $name, string $phone, string $role, string $tag): User
    {
        return User::create([
            'name' => $name, 'phone' => $phone, 'role' => $role, 'roles' => [$role],
            'email' => "demo+$tag@demo.test", 'password' => Hash::make(self::PASSWORD),
            'is_active' => true, 'phone_verified_at' => now(),
        ]);
    }

    private function accounts(WalletService $wallets): array
    {
        [$lat, $lng] = self::CENTER;
        $a = [];

        // زبائن: واحد عادي، واحد برصيد ونقاط، واحد بدون عنوان
        $a['c1'] = $this->user('زبون تجريبي ١', '0910000301', 'customer', 'c1');
        $a['c1']->addresses()->create(['label' => 'البيت', 'details' => 'حي تجريبي — قريب من الساحة', 'landmark' => 'جنب الجامع', 'lat' => $lat + 0.004, 'lng' => $lng + 0.003, 'is_default' => true]);
        $a['c1']->addresses()->create(['label' => 'الشغل', 'details' => 'عنوان بعيد شوية داخل المنطقة', 'lat' => $lat + 0.03, 'lng' => $lng - 0.03]);

        $a['c2'] = $this->user('زبون تجريبي ٢ (عنده رصيد)', '0910000302', 'customer', 'c2');
        $a['c2']->addresses()->create(['label' => 'البيت', 'details' => 'حي تجريبي ٢', 'lat' => $lat - 0.005, 'lng' => $lng + 0.006, 'is_default' => true]);
        $wallets->credit($a['c2'], 150, 'adjustment', null, 'رصيد تجريبي');
        $a['c2']->forceFill(['points_balance' => 2500])->save();

        $a['c3'] = $this->user('زبون تجريبي ٣ (بدون عنوان)', '0910000303', 'customer', 'c3');

        // سائقين معتمدين في المنطقة
        foreach ([['d1', 'سائق تجريبي ١', '0910000201', 0.002], ['d2', 'سائق تجريبي ٢', '0910000202', -0.004]] as [$k, $name, $phone, $off]) {
            $a[$k] = $this->user($name, $phone, 'driver', $k);
            $profile = $a[$k]->ensureDriverProfile();
            $profile->update(['is_approved' => true, 'is_online' => false, 'vehicle_type' => 'car',
                'current_lat' => $lat + $off, 'current_lng' => $lng + $off]);
            $zoneIds = DeliveryZone::where('is_active', true)->pluck('id');
            $profile->zones()->sync($zoneIds);
        }
        // سائق مش معتمد (يستنى الإدارة)
        $a['d3'] = $this->user('سائق تجريبي ٣ (مش معتمد)', '0910000203', 'driver', 'd3');
        $a['d3']->ensureDriverProfile()->update(['is_approved' => false]);

        // حساب واحد بأكثر من دور (زبون + سائق)
        $a['multi'] = $this->user('حساب تجريبي متعدد الأدوار', '0910000401', 'customer', 'multi');
        $a['multi']->addRole(UserRole::Driver);
        $a['multi']->ensureDriverProfile()->update(['is_approved' => true]);
        $a['multi']->addresses()->create(['label' => 'البيت', 'details' => 'حي تجريبي ٣', 'lat' => $lat + 0.002, 'lng' => $lng - 0.002, 'is_default' => true]);

        return $a;
    }

    // ===================== المتاجر والأصناف =====================

    private int $ownerSeq = 100;

    private function store(array $types, string $typeKey, array $attrs): Store
    {
        $this->ownerSeq++;
        $owner = $this->user('صاحب '.$attrs['name'], '0910000'.$this->ownerSeq, 'store', 's'.$this->ownerSeq);
        [$lat, $lng] = self::CENTER;

        return Store::create($attrs + [
            'user_id' => $owner->id,
            'store_type_id' => $types[$typeKey] ?? reset($types),
            'slug' => 'demo-'.$this->ownerSeq,
            'phone' => $owner->phone,
            'lat' => $lat + (mt_rand(-40, 40) / 10000),
            'lng' => $lng + (mt_rand(-40, 40) / 10000),
            'commission_percent' => 15,
            'min_order' => 0,
            'prep_time_minutes' => 20,
            'is_open' => true,
            'is_active' => true,
            'rating_avg' => mt_rand(38, 50) / 10,
            'rating_count' => mt_rand(3, 120),
        ]);
    }

    /**
     * @param  array  $p  name, price, discount, section, desc, stock, max, off, images, options
     */
    private function product(Store $store, array $sections, array $p): Product
    {
        $images = [];
        for ($i = 0; $i < ($p['images'] ?? 1); $i++) {
            $images[] = $this->image($p['name'], $i, $p['color'] ?? null);
        }

        $product = $store->products()->create([
            'name' => $p['name'],
            'description' => $p['desc'] ?? null,
            'price' => $p['price'],
            'discount_price' => $p['discount'] ?? null,
            'menu_section_id' => isset($p['section']) ? $sections[$p['section']] : null,
            'track_stock' => array_key_exists('stock', $p),
            'stock_quantity' => $p['stock'] ?? 0,
            'low_stock_alert' => $p['low'] ?? null,
            'max_per_order' => $p['max'] ?? null,
            'is_available' => ! ($p['off'] ?? false) && (! array_key_exists('stock', $p) || $p['stock'] > 0),
            'is_visible' => ! ($p['hidden'] ?? false),
            'sold_out_at' => array_key_exists('stock', $p) && $p['stock'] <= 0 ? now() : null,
            'images' => $images ?: null,
            'ingredients' => Product::normalizeIngredients($p['ingredients'] ?? []) ?: null,
            'sort' => $p['sort'] ?? 0,
        ]);

        foreach ($p['options'] ?? [] as $i => $o) {
            $option = $product->options()->create([
                'name' => $o['name'], 'type' => $o['type'] ?? 'multi', 'is_required' => $o['required'] ?? false,
                'max_choices' => $o['max'] ?? (($o['type'] ?? 'multi') === 'single' ? 1 : count($o['values'])), 'sort' => $i,
            ]);
            foreach ($o['values'] as $j => $v) {
                $option->values()->create([
                    'name' => $v[0], 'extra_price' => $v[1] ?? 0, 'max_qty' => $v[2] ?? 1,
                    'is_available' => $v[3] ?? true, 'sort' => $j,
                ]);
            }
        }

        return $product;
    }

    private function sections(Store $store, array $names): array
    {
        $out = [];
        foreach ($names as $i => $name) {
            $out[$name] = MenuSection::create(['store_id' => $store->id, 'name' => $name, 'sort' => $i, 'is_active' => true])->id;
        }

        return $out;
    }

    private function stores(array $types, array $acc): void
    {
        // خيارات جاهزة تتكرر
        $size = ['name' => 'الحجم', 'type' => 'single', 'required' => true, 'values' => [['عادي', 0], ['وسط', 2], ['كبير', 4]]];
        $extras = ['name' => 'الإضافات', 'max' => 4, 'values' => [
            ['زيادة صوص', 0, 1], ['زيادة ثوم', 0, 1], ['سيخ كباب إضافي', 5, 3], ['جبنة', 1.5, 1], ['بطاطا', 2, 2], ['هريسة', 0, 1, false],
        ]];

        // ---- 1) مطعم كامل: كل أنواع الإضافات ----
        $s = $this->store($types, 'food', ['name' => 'مطعم الجبل (تجريبي)', 'description' => 'شاورما، برجر، بيتزا — مطعم تجريبي فيه كل أنواع الإضافات',
            'min_order' => 10, 'prep_time_minutes' => 20]);
        $sec = $this->sections($s, ['شاورما', 'برجر', 'بيتزا', 'وجبات', 'مشروبات', 'حلويات']);
        $shawarma = $this->product($s, $sec, ['name' => 'شاورما دجاج', 'price' => 12, 'section' => 'شاورما', 'desc' => 'شاورما على الفحم', 'images' => 3, 'options' => [$size, $extras],
            'ingredients' => [['name' => 'خبز صاج', 'removable' => false], ['name' => 'دجاج', 'removable' => false], 'ثوم', 'مخلل', 'بطاطا', 'هريسة', 'بصل']]);
        $this->product($s, $sec, ['name' => 'شاورما لحم', 'price' => 15, 'discount' => 13, 'section' => 'شاورما', 'desc' => 'عرض: سعر مخفّض', 'options' => [$size, $extras]]);
        $this->product($s, $sec, ['name' => 'صحن شاورما عائلي', 'price' => 45, 'section' => 'شاورما', 'desc' => 'أقصى 2 في الطلب', 'max' => 2]);
        $this->product($s, $sec, ['name' => 'برجر كلاسيك', 'price' => 18, 'section' => 'برجر',
            'ingredients' => [['name' => 'شريحة لحم', 'removable' => false], 'جبنة', 'خس', 'طماطم', 'بصل', 'مخلل', 'كاتشب', 'مايونيز'], 'options' => [
                ['name' => 'نوع الخبز', 'type' => 'single', 'values' => [['خبز عادي', 0], ['خبز بريوش', 2], ['بدون خبز (خس)', 0]]],
                ['name' => 'درجة الاستواء', 'type' => 'single', 'required' => true, 'values' => [['متوسط', 0], ['مستوي زيادة', 0]]],
                ['name' => 'إضافات البرجر', 'max' => 3, 'values' => [['شريحة لحم إضافية', 7, 2], ['بيض', 1.5, 2], ['جبنة شيدر', 2, 3], ['هالبينو', 1]]],
            ]]);
        $this->product($s, $sec, ['name' => 'بيتزا مارجريتا', 'price' => 25, 'section' => 'بيتزا', 'images' => 2, 'options' => [
            ['name' => 'الحجم', 'type' => 'single', 'required' => true, 'values' => [['صغير', 0], ['وسط', 8], ['كبير', 15], ['عائلي', 25, 1, false]]],
            ['name' => 'العجينة', 'type' => 'single', 'required' => true, 'values' => [['رقيقة', 0], ['سميكة', 2], ['محشية جبنة', 6]]],
            ['name' => 'إضافات', 'max' => 5, 'values' => [['زيتون', 1, 2], ['فطر', 2, 2], ['تونة', 4, 2], ['جبنة زيادة', 3, 3]]],
        ]]);
        $this->product($s, $sec, ['name' => 'وجبة رز باللحم', 'price' => 30, 'section' => 'وجبات', 'desc' => 'تقدر تزيد أسياخ كباب (لحد 5)', 'options' => [
            ['name' => 'أسياخ إضافية', 'max' => 2, 'values' => [['سيخ كباب', 5, 5], ['سيخ شيش طاووق', 4, 5]]],
            ['name' => 'مع الوجبة', 'type' => 'single', 'values' => [['سلطة', 0], ['شوربة', 3]]],
        ]]);
        $this->product($s, $sec, ['name' => 'كسكسي بالخضرة', 'price' => 22, 'section' => 'وجبات', 'desc' => 'متوفر 3 بس اليوم', 'stock' => 3, 'low' => 3]);
        $this->product($s, $sec, ['name' => 'بازين', 'price' => 20, 'section' => 'وجبات', 'desc' => 'خلص اليوم — يبان «نفد»', 'stock' => 0, 'images' => 2]);
        $this->product($s, $sec, ['name' => 'مبكبكة', 'price' => 18, 'section' => 'وجبات', 'desc' => 'موقوف من المتجر (مش خالص)', 'off' => true]);
        $this->product($s, $sec, ['name' => 'عصير برتقال طبيعي', 'price' => 6, 'section' => 'مشروبات', 'options' => [
            ['name' => 'الحجم', 'type' => 'single', 'required' => true, 'values' => [['صغير', 0], ['كبير', 3]]],
            ['name' => 'بدون سكر؟', 'type' => 'single', 'values' => [['بدون سكر', 0]]],
        ]]);
        $this->product($s, $sec, ['name' => 'مياه', 'price' => 1, 'section' => 'مشروبات', 'images' => 0]);
        $this->product($s, $sec, ['name' => 'مشروب غازي', 'price' => 2.5, 'section' => 'مشروبات', 'max' => 6]);
        $this->product($s, $sec, ['name' => 'كنافة', 'price' => 10, 'section' => 'حلويات', 'stock' => 1, 'desc' => 'آخر قطعة — جرّب طلبها من زبونين مع بعض']);
        $this->product($s, $sec, ['name' => 'عرض العيد (مخفي)', 'price' => 50, 'section' => 'حلويات', 'hidden' => true, 'desc' => 'مخفي عن الزبائن — يبان في تطبيق المتجر واللوحة بس']);
        $this->product($s, $sec, ['name' => 'بسبوسة', 'price' => 7, 'section' => 'حلويات', 'stock' => 3, 'low' => 3, 'desc' => 'الزبون يشوف «متوفر 3 قطع فقط»']);
        ReadyCart::create(['store_id' => $s->id, 'name' => 'وجبة عائلية جاهزة', 'description' => '٤ شاورما + ٤ مشروبات', 'is_active' => true,
            'items' => [['product_id' => $shawarma->id, 'quantity' => 4], ['product_id' => $s->products()->where('name', 'مشروب غازي')->value('id'), 'quantity' => 4]]]);

        // ---- 2) مطعم مسكّر ----
        $s = $this->store($types, 'food', ['name' => 'مطعم مسكّر (تجريبي)', 'description' => 'مسكّر — تقدر تتصفح بس ما تقدرش تطلب', 'is_open' => false]);
        $sec = $this->sections($s, ['الأكلات']);
        $this->product($s, $sec, ['name' => 'مقرونة مبكبكة', 'price' => 15, 'section' => 'الأكلات']);
        $this->product($s, $sec, ['name' => 'شربة ليبية', 'price' => 8, 'section' => 'الأكلات']);

        // ---- 3) مطعم ساعات عمل (يفتح بالليل بس) ----
        $s = $this->store($types, 'food', ['name' => 'مشويات الليل (تجريبي)', 'description' => 'يخدم من 7 المساء لـ 2 الفجر — جرّب قبل وبعد',
            'opens_at' => '19:00:00', 'closes_at' => '02:00:00', 'commission_percent' => 10]);
        $sec = $this->sections($s, ['مشويات']);
        $this->product($s, $sec, ['name' => 'مشاوي مشكلة', 'price' => 40, 'section' => 'مشويات', 'options' => [
            ['name' => 'الكمية', 'type' => 'single', 'required' => true, 'values' => [['نص كيلو', 0], ['كيلو', 35]]],
        ]]);

        // ---- 4) مطعم بحد أدنى عالي + عمولة صفر ----
        $s = $this->store($types, 'food', ['name' => 'مطعم الولائم (تجريبي)', 'description' => 'الحد الأدنى للطلب 100 د.ل', 'min_order' => 100, 'commission_percent' => 0, 'prep_time_minutes' => 60]);
        $sec = $this->sections($s, ['ولائم']);
        $this->product($s, $sec, ['name' => 'صينية رز ولحم', 'price' => 60, 'section' => 'ولائم']);
        $this->product($s, $sec, ['name' => 'خروف محشي', 'price' => 450, 'section' => 'ولائم', 'max' => 1]);

        // ---- 5) مخبز وحلويات: كل شي بكمية ----
        $s = $this->store($types, 'sweets', ['name' => 'مخبز الساحة (تجريبي)', 'description' => 'كل الأصناف بكمية محدودة — جرّب «قرّب يخلص» و«نفد»', 'prep_time_minutes' => 10]);
        $sec = $this->sections($s, ['خبز', 'حلويات', 'تورتات']);
        $this->product($s, $sec, ['name' => 'خبز تنور (ربطة)', 'price' => 2, 'section' => 'خبز', 'stock' => 50, 'low' => 10]);
        $this->product($s, $sec, ['name' => 'كرواسون', 'price' => 3, 'section' => 'خبز', 'stock' => 4, 'low' => 5, 'options' => [
            ['name' => 'الحشوة', 'type' => 'single', 'required' => true, 'values' => [['سادة', 0], ['شوكولاتة', 1], ['جبنة', 1]]],
        ]]);
        $this->product($s, $sec, ['name' => 'بقلاوة (علبة)', 'price' => 25, 'section' => 'حلويات', 'stock' => 2]);
        $this->product($s, $sec, ['name' => 'معمول', 'price' => 15, 'section' => 'حلويات', 'stock' => 0]);
        $this->product($s, $sec, ['name' => 'تورتة عيد ميلاد', 'price' => 80, 'section' => 'تورتات', 'max' => 1, 'images' => 2, 'options' => [
            ['name' => 'الحجم', 'type' => 'single', 'required' => true, 'values' => [['8 أشخاص', 0], ['15 شخص', 50]]],
            ['name' => 'النكهة', 'type' => 'single', 'required' => true, 'values' => [['شوكولاتة', 0], ['فانيلا', 0], ['فراولة', 5]]],
            ['name' => 'إضافات', 'max' => 3, 'values' => [['شموع', 2, 3], ['كتابة اسم', 5, 1], ['صورة مطبوعة', 15, 1]]],
        ]]);

        // ---- 6) سوبرماركت: أصناف كثيرة وحدود لكل طلب ----
        $s = $this->store($types, 'market', ['name' => 'سوبرماركت النور (تجريبي)', 'description' => 'أصناف كثيرة — جرّب البحث والسلة الكبيرة', 'min_order' => 20, 'prep_time_minutes' => 15]);
        $sec = $this->sections($s, ['خضرة وفواكه', 'ألبان', 'معلبات', 'منظفات', 'مشروبات']);
        $items = [
            ['طماطم (كيلو)', 3.5, 'خضرة وفواكه'], ['بطاطا (كيلو)', 2.5, 'خضرة وفواكه'], ['بصل (كيلو)', 2, 'خضرة وفواكه'], ['تفاح (كيلو)', 7, 'خضرة وفواكه'],
            ['موز (كيلو)', 6, 'خضرة وفواكه'], ['حليب طويل الأجل', 4, 'ألبان'], ['لبن', 2.5, 'ألبان'], ['جبنة بيضاء', 9, 'ألبان'], ['زبادي (6 حبات)', 5, 'ألبان'],
            ['تونة', 4.5, 'معلبات'], ['طماطم معلبة', 2, 'معلبات'], ['فول', 2.5, 'معلبات'], ['حمص', 2.5, 'معلبات'],
            ['صابون غسيل', 12, 'منظفات'], ['سائل أواني', 6, 'منظفات'], ['مناديل (علبة)', 3, 'منظفات'],
            ['مياه (6 قنينات)', 5, 'مشروبات'], ['عصير (لتر)', 4, 'مشروبات'],
        ];
        foreach ($items as $i => [$name, $price, $section]) {
            $this->product($s, $sec, ['name' => $name, 'price' => $price, 'section' => $section, 'sort' => $i, 'images' => $i % 3 ? 1 : 0]);
        }
        $this->product($s, $sec, ['name' => 'سكر (كيس 5 كيلو) — عرض', 'price' => 20, 'discount' => 16, 'section' => 'معلبات', 'max' => 2, 'stock' => 10, 'desc' => 'عرض: أقصى 2 لكل زبون']);
        $this->product($s, $sec, ['name' => 'زيت زيتون (لتر)', 'price' => 25, 'section' => 'معلبات', 'stock' => 3, 'low' => 5]);

        // ---- 7) متجر إلكترونيات: أسعار عالية وكمية وحدة ----
        $s = $this->store($types, 'online', ['name' => 'متجر التقنية (تجريبي)', 'description' => 'أسعار عالية وكميات قليلة — جرّب الدفع من المحفظة', 'prep_time_minutes' => 30, 'commission_percent' => 8]);
        $sec = $this->sections($s, ['هواتف', 'إكسسوارات']);
        $this->product($s, $sec, ['name' => 'هاتف ذكي', 'price' => 1200, 'section' => 'هواتف', 'stock' => 1, 'max' => 1, 'images' => 3, 'options' => [
            ['name' => 'اللون', 'type' => 'single', 'required' => true, 'values' => [['أسود', 0], ['أزرق', 0], ['ذهبي', 50, 1, false]]],
            ['name' => 'السعة', 'type' => 'single', 'required' => true, 'values' => [['128 جيجا', 0], ['256 جيجا', 150]]],
        ]]);
        $this->product($s, $sec, ['name' => 'سماعة بلوتوث', 'price' => 85, 'discount' => 70, 'section' => 'إكسسوارات', 'stock' => 5]);
        $this->product($s, $sec, ['name' => 'شاحن سريع', 'price' => 35, 'section' => 'إكسسوارات', 'options' => [
            ['name' => 'مع الشاحن', 'max' => 2, 'values' => [['كابل إضافي', 10, 3], ['غطاء حماية', 15, 1]]],
        ]]);

        // ---- 8) متجر بعيد (رسوم توصيل عالية) ----
        [$lat, $lng] = self::CENTER;
        $s = $this->store($types, 'market', ['name' => 'متجر المزرعة البعيدة (تجريبي)', 'description' => 'بعيد على المدينة — رسوم التوصيل أعلى']);
        $s->update(['lat' => $lat + 0.07, 'lng' => $lng + 0.07]);
        $sec = $this->sections($s, ['منتجات المزرعة']);
        $this->product($s, $sec, ['name' => 'بيض بلدي (طبق)', 'price' => 15, 'section' => 'منتجات المزرعة', 'stock' => 20]);
        $this->product($s, $sec, ['name' => 'زيت زيتون بلدي (لتر)', 'price' => 30, 'section' => 'منتجات المزرعة']);

        // ---- أقسام ثانية موجودة في المنصة: متجر عام ----
        foreach ($types as $key => $typeId) {
            if (str_starts_with($key, 'extra-')) {
                $s = $this->store($types, $key, ['name' => 'متجر '.StoreType::find($typeId)?->name.' (تجريبي)']);
                $sec = $this->sections($s, ['الأصناف']);
                $this->product($s, $sec, ['name' => 'صنف تجريبي ١', 'price' => 10, 'section' => 'الأصناف']);
                $this->product($s, $sec, ['name' => 'صنف تجريبي ٢', 'price' => 20, 'section' => 'الأصناف', 'options' => [$size]]);
            }
        }
    }

    private function coupons(): void
    {
        $shawarma = Store::where('slug', 'like', 'demo-%')->where('name', 'مطعم الجبل (تجريبي)')->value('id');
        foreach ([
            ['code' => 'DEMO10', 'type' => 'percent', 'value' => 10, 'max_discount' => 20],
            ['code' => 'DEMO5', 'type' => 'fixed', 'value' => 5, 'min_order' => 30],
            ['code' => 'DEMOFREE', 'type' => 'free_delivery', 'value' => 0],
            ['code' => 'DEMOJABAL', 'type' => 'percent', 'value' => 20, 'store_id' => $shawarma],
            ['code' => 'DEMOONCE', 'type' => 'fixed', 'value' => 3, 'per_user_limit' => 1],
            ['code' => 'DEMOOLD', 'type' => 'percent', 'value' => 50, 'ends_at' => now()->subDay()],
        ] as $c) {
            Coupon::create($c + ['is_active' => true]);
        }
    }

    private function cards(): void
    {
        foreach ([['100000000011', 20], ['100000000012', 50], ['100000000013', 100]] as [$code, $amount]) {
            RechargeCard::create(['code' => $code, 'amount' => $amount, 'batch' => 'DEMO', 'status' => 'unused']);
        }
        RechargeCard::create(['code' => '100000000014', 'amount' => 10, 'batch' => 'DEMO', 'status' => 'unused', 'expires_at' => now()->subDay()]);
    }

    /** صورة بسيطة ملوّنة باسم الصنف (باش نجربو المعرض والواصل بدون صور حقيقية) */
    private function image(string $name, int $i, ?string $color): string
    {
        $palette = [[216, 67, 21], [46, 125, 50], [21, 101, 192], [123, 31, 162], [239, 108, 0], [0, 131, 143]];
        [$r, $g, $b] = $palette[(crc32($name) + $i) % count($palette)];
        $path = 'demo/'.substr(md5($name.$i), 0, 12).'.png';

        if (function_exists('imagecreatetruecolor') && ! Storage::disk('public')->exists($path)) {
            $im = imagecreatetruecolor(640, 400);
            imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
            $light = imagecolorallocatealpha($im, 255, 255, 255, 90);
            imagefilledellipse($im, 480 - $i * 120, 140 + $i * 60, 360, 360, $light);
            imagefilledellipse($im, 120 + $i * 80, 320, 240, 240, $light);
            $white = imagecolorallocate($im, 255, 255, 255);
            imagestring($im, 5, 24, 360, 'DEMO '.($i + 1), $white);
            ob_start();
            imagepng($im);
            Storage::disk('public')->put($path, ob_get_clean());
            imagedestroy($im);
        }

        return $path;
    }

    // ===================== المسح =====================

    private function remove(bool $quiet = false): void
    {
        DB::transaction(function () {
            $users = User::withTrashed()->where('email', 'like', '%@demo.test')->get();
            $stores = Store::withTrashed()->where('slug', 'like', 'demo-%')->get();

            // الطلبات أول (من المتاجر التجريبية أو للزبائن التجريبيين)
            $orderIds = Order::whereIn('store_id', $stores->pluck('id'))
                ->orWhereIn('customer_id', $users->pluck('id'))->pluck('id');
            Order::whereIn('id', $orderIds)->get()->each->delete();

            Settlement::whereIn('user_id', $users->pluck('id'))->delete();
            ReadyCart::whereIn('store_id', $stores->pluck('id'))->delete();
            Coupon::where('code', 'like', 'DEMO%')->delete();
            RechargeCard::where('batch', 'DEMO')->delete();

            foreach ($stores as $s) {
                Product::withTrashed()->where('store_id', $s->id)->get()->each->forceDelete();
                MenuSection::where('store_id', $s->id)->delete();
                $s->forceDelete();
            }

            foreach ($users as $u) {
                $u->tokens()->delete();
                $u->addresses()->delete();
                $u->driverProfile?->zones()->detach();
                $u->driverProfile?->delete();
                Wallet::where('user_id', $u->id)->get()->each(function ($w) {
                    WalletTransaction::where('wallet_id', $w->id)->delete();
                    $w->delete();
                });
                $u->forceDelete();
            }

            $meta = json_decode((string) Setting::get('demo.meta', '{}'), true) ?: [];
            StoreType::whereIn('id', $meta['types'] ?? [])->doesntHave('stores')->delete();
            AppSection::whereIn('id', $meta['sections'] ?? [])->get()
                ->filter(fn ($s) => ! StoreType::where('app_section_id', $s->id)->exists())->each->delete();
            DeliveryZone::whereIn('id', $meta['zones'] ?? [])->delete();
            Setting::put('demo.meta', '{}');
        });

        Storage::disk('public')->deleteDirectory('demo');
    }

    private function printSummary(): void
    {
        $this->info('✅ البيانات التجريبية جاهزة. كلمة المرور لكل الحسابات: '.self::PASSWORD);
        $this->table(['الحساب', 'الرقم', 'الدور'], User::where('email', 'like', '%@demo.test')->orderBy('phone')->get()
            ->map(fn ($u) => [$u->name, $u->phone, $u->rolesLabel()])->all());
        $this->line('الكوبونات: DEMO10 · DEMO5 · DEMOFREE · DEMOJABAL · DEMOONCE · DEMOOLD (منتهي)');
        $this->line('كروت الشحن: 100000000011 (20) · 100000000012 (50) · 100000000013 (100) · 100000000014 (منتهي)');
        $this->line('للمسح بعد التجربة: php artisan demo:seed --remove');
    }
}
