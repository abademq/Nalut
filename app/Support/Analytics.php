<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\AppSection;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\Rating;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * الإحصاءات: شاشة المراقبة الحية (monitor) والتحليلات المفصّلة (report).
 * نفس الأرقام تطلع في لوحة التحكم، وشاشة العرض الكبيرة، وتطلب الإدارة.
 *
 * الحسابات في PHP (مش SQL) باش تخدم نفس الشي على MySQL وSQLite —
 * حجم الطلبات في مدينة وحدة صغير، وأطول فترة 180 يوم.
 */
class Analytics
{
    private const ORDER_COLUMNS = ['id', 'code', 'customer_id', 'store_id', 'driver_id', 'status', 'fulfillment',
        'payment_method', 'subtotal', 'delivery_fee', 'delivery_subsidy', 'discount', 'points_discount', 'total',
        'commission_amount', 'driver_earning', 'distance_km', 'cancelled_by', 'created_at', 'accepted_at', 'ready_at',
        'picked_up_at', 'arrived_at', 'delivered_at', 'cancelled_at', 'left_at_door_at', 'handover_deadline_at'];

    // ===================== الشاشة الحية =====================

    public static function monitor(): array
    {
        [$from, $to] = LocalDay::range();
        $tz = LocalDay::timezone();
        $now = now();

        $sectionOf = self::sectionMap();
        $sectionNames = AppSection::orderBy('sort')->pluck('name', 'id')->all();

        $today = Order::whereBetween('created_at', [$from, $to])->get(self::ORDER_COLUMNS);
        $active = Order::active()->with(['store:id,name,store_type_id', 'driver:id,name', 'openIssue'])
            ->orderBy('created_at')->get();

        $delivered = $today->where('status', OrderStatus::Delivered);
        $finished = $today->filter(fn ($o) => $o->status->isFinal());

        // أقسام التطبيق: الطلبات الشغّالة ومتوسط التحضير اليوم
        $sections = [];
        foreach ($sectionNames + [0 => 'بدون قسم'] as $id => $name) {
            $todayIn = $today->filter(fn ($o) => ($sectionOf[$o->store_id] ?? 0) === $id);
            $activeIn = $active->filter(fn ($o) => ($sectionOf[$o->store_id] ?? 0) === $id);
            if ($id === 0 && $todayIn->isEmpty() && $activeIn->isEmpty()) {
                continue;
            }
            $sections[] = [
                'id' => $id,
                'name' => $name,
                'active' => $activeIn->count(),
                'today' => $todayIn->count(),
                'delivered' => $todayIn->where('status', OrderStatus::Delivered)->count(),
                'avg_prep_minutes' => self::avgMinutes($todayIn, 'accepted_at', 'ready_at'),
                'avg_total_minutes' => self::avgMinutes($todayIn->where('status', OrderStatus::Delivered)
                    ->where('fulfillment', '!=', 'pickup'), 'created_at', 'delivered_at'),
            ];
        }

        // السائقين
        $profiles = DriverProfile::where('is_approved', true)->with('user:id,name,is_active')->get();
        $busyIds = $active->whereNotNull('driver_id')->groupBy('driver_id')->map->count();
        $online = $profiles->filter(fn ($p) => $p->is_online && $p->user?->is_active);
        $drivers = $online->map(fn ($p) => [
            'id' => $p->user_id,
            'name' => $p->user?->name,
            'active_orders' => (int) ($busyIds[$p->user_id] ?? 0),
            'location_minutes_ago' => $p->location_updated_at ? (int) $p->location_updated_at->diffInMinutes($now) : null,
        ])->sortByDesc('active_orders')->values();
        $offlineBusy = $busyIds->keys()->diff($online->pluck('user_id'))->count();

        // الطلبات الشغّالة
        $pendingMin = (int) Options::get('alerts.pending_minutes');
        $noDriverMin = (int) Options::get('alerts.no_driver_minutes');
        $activeList = $active->map(function (Order $o) use ($now, $sectionOf, $sectionNames, $pendingMin, $noDriverMin) {
            $age = (int) $o->created_at->diffInMinutes($now);
            $late = match (true) {
                (bool) $o->openIssue => 'issue',
                $o->status === OrderStatus::Pending && $pendingMin > 0 && $age >= $pendingMin => 'pending',
                $o->status === OrderStatus::Ready && ! $o->driver_id && ! $o->isPickup()
                    && $noDriverMin > 0 && $o->ready_at && $o->ready_at->diffInMinutes($now) >= $noDriverMin => 'no_driver',
                $o->status === OrderStatus::AwaitingHandover && $o->handover_deadline_at && $o->handover_deadline_at->lte($now) => 'handover',
                default => null,
            };

            return [
                'id' => $o->id,
                'code' => $o->code,
                'store' => $o->store?->name,
                'section' => $sectionNames[$sectionOf[$o->store_id] ?? 0] ?? 'بدون قسم',
                'status' => $o->status->value,
                'status_label' => $o->openIssue ? 'قيد مراجعة الإدارة' : $o->status->label(),
                'pickup' => $o->isPickup(),
                'driver' => $o->driver?->name,
                'age_minutes' => $age,
                'late' => $late,
            ];
        })->values();

        // المشاكل اللي تحتاج تدخّل
        $problems = [];
        foreach ($activeList->whereNotNull('late') as $o) {
            $problems[] = [
                'level' => $o['late'] === 'issue' ? 'danger' : 'warning',
                'title' => match ($o['late']) {
                    'issue' => 'بلاغ من سائق',
                    'pending' => 'المتجر ما قبلش الطلب',
                    'no_driver' => 'جاهز بدون سائق',
                    'handover' => 'انتهت مهلة الزبون عند الباب',
                },
                'detail' => "#{$o['code']} · {$o['store']} · منذ {$o['age_minutes']} دقيقة",
                'order_id' => $o['id'],
            ];
        }
        $waitingTickets = Ticket::where('status', 'open')->count();
        if ($waitingTickets > 0) {
            $problems[] = ['level' => 'info', 'title' => "تذاكر دعم تستنى رد: {$waitingTickets}", 'detail' => 'الدعم الفني', 'order_id' => null];
        }
        $needDrivers = $active->filter(fn ($o) => ! $o->isPickup() && in_array($o->status, [OrderStatus::Preparing, OrderStatus::Ready], true) && ! $o->driver_id)->count();
        if ($needDrivers > 0 && $online->isEmpty()) {
            $problems[] = ['level' => 'danger', 'title' => 'ما فيش ولا سائق متاح', 'detail' => "{$needDrivers} طلب يحتاج سائق", 'order_id' => null];
        }
        foreach (Emergency::active() as $switch) {
            $problems[] = ['level' => 'danger', 'title' => 'طوارئ: '.Emergency::SWITCHES[$switch][0], 'detail' => 'مركز الطوارئ', 'order_id' => null];
        }
        usort($problems, fn ($a, $b) => ['danger' => 0, 'warning' => 1, 'info' => 2][$a['level']] <=> ['danger' => 0, 'warning' => 1, 'info' => 2][$b['level']]);

        $byStatus = [];
        foreach (OrderStatus::active() as $s) {
            $n = $active->filter(fn ($o) => $o->status->value === $s)->count();
            if ($n > 0 || in_array($s, ['pending', 'preparing', 'ready', 'on_the_way'], true)) {
                $byStatus[] = ['status' => $s, 'label' => OrderStatus::from($s)->label(), 'count' => $n];
            }
        }

        return [
            'updated_at' => $now->copy()->setTimezone($tz)->format('H:i:s'),
            'date' => $now->copy()->setTimezone($tz)->format('Y-m-d'),
            'kpis' => [
                'orders_today' => $today->count(),
                'active' => $active->count(),
                'delivered_today' => $delivered->count(),
                'cancelled_today' => $today->where('status', OrderStatus::Cancelled)->count(),
                'failed_today' => $today->where('status', OrderStatus::Failed)->count(),
                'completion_rate' => self::pct($delivered->count(), $finished->count()),
                'sales_today' => round((float) $delivered->sum('total'), 2),
                'avg_order_value' => $delivered->count() ? round((float) $delivered->avg('total'), 2) : 0,
                'avg_delivery_minutes' => self::avgMinutes($delivered->where('fulfillment', '!=', 'pickup'), 'created_at', 'delivered_at'),
                'avg_prep_minutes' => self::avgMinutes($today, 'accepted_at', 'ready_at'),
            ],
            'by_status' => $byStatus,
            'sections' => $sections,
            'drivers' => [
                'online' => $online->count(),
                'busy' => $online->filter(fn ($p) => ($busyIds[$p->user_id] ?? 0) > 0)->count(),
                'free' => $online->filter(fn ($p) => ($busyIds[$p->user_id] ?? 0) === 0)->count(),
                'offline_with_orders' => $offlineBusy,
                'list' => $drivers->take(30)->all(),
            ],
            'problems' => array_slice($problems, 0, 30),
            'active_orders' => $activeList->sortByDesc(fn ($o) => [$o['late'] !== null, $o['age_minutes']])->take(40)->values()->all(),
        ];
    }

    // ===================== التحليلات =====================

    public static function report(Carbon $from, Carbon $to): array
    {
        $tz = LocalDay::timezone();
        $sectionOf = self::sectionMap();
        $sectionNames = AppSection::orderBy('sort')->pluck('name', 'id')->all() + [0 => 'بدون قسم'];

        $orders = Order::whereBetween('created_at', [$from, $to])->get(self::ORDER_COLUMNS);
        $delivered = $orders->where('status', OrderStatus::Delivered);
        $cancelled = $orders->where('status', OrderStatus::Cancelled);
        $failed = $orders->where('status', OrderStatus::Failed);
        $finished = $orders->filter(fn ($o) => $o->status->isFinal());
        $deliveryOnly = $delivered->filter(fn ($o) => ($o->fulfillment ?? 'delivery') !== 'pickup');

        // منو ألغى
        $storeOwners = Store::pluck('user_id', 'id');
        $admins = User::withRole('admin')->pluck('id')->flip();
        $cancelBy = ['customer' => 0, 'store' => 0, 'admin' => 0, 'system' => 0];
        foreach ($cancelled as $o) {
            $key = match (true) {
                ! $o->cancelled_by => 'system',
                $o->cancelled_by === $o->customer_id => 'customer',
                $o->cancelled_by === ($storeOwners[$o->store_id] ?? null) => 'store',
                isset($admins[$o->cancelled_by]) => 'admin',
                default => 'system',
            };
            $cancelBy[$key]++;
        }

        // الأوقات (طلبات توصيل مكتملة)
        $totals = $deliveryOnly->map(fn ($o) => self::minutes($o->created_at, $o->delivered_at))->filter(fn ($m) => $m !== null)->sort()->values();
        $speeds = $deliveryOnly->map(function ($o) {
            $min = self::minutes($o->picked_up_at, $o->delivered_at);
            $km = (float) $o->distance_km;

            return ($min && $min >= 2 && $km > 0) ? $km / ($min / 60) : null;
        })->filter(fn ($v) => $v !== null && $v < 120);

        // الزبائن
        $customerIds = $delivered->pluck('customer_id')->unique()->filter();
        $perCustomer = $delivered->groupBy('customer_id')->map->count();
        $before = $customerIds->isEmpty() ? collect() : Order::where('status', OrderStatus::Delivered->value)
            ->where('created_at', '<', $from)->whereIn('customer_id', $customerIds)->distinct()->pluck('customer_id');

        // الاحتفاظ: الزبائن اللي أول طلب مكتمل ليهم في الفترة — كم واحد رجع طلب خلال 30 يوم
        $firsts = $customerIds->isEmpty() ? collect() : Order::where('status', OrderStatus::Delivered->value)
            ->whereIn('customer_id', $customerIds)->orderBy('created_at')->get(['customer_id', 'created_at'])
            ->groupBy('customer_id');
        $newBuyers = 0;
        $retained = 0;
        foreach ($firsts as $list) {
            $first = $list->first()->created_at;
            if ($first->lt($from)) {
                continue;
            }
            $newBuyers++;
            if ($list->skip(1)->contains(fn ($o) => $o->created_at->lte($first->copy()->addDays(30)))) {
                $retained++;
            }
        }

        // يومي وساعات اليوم
        $daily = [];
        for ($d = $from->copy()->setTimezone($tz)->startOfDay(); $d->lte($to->copy()->setTimezone($tz)); $d->addDay()) {
            $daily[$d->format('Y-m-d')] = ['date' => $d->format('m-d'), 'orders' => 0, 'delivered' => 0, 'sales' => 0.0];
        }
        $hourly = array_fill(0, 24, 0);
        foreach ($orders as $o) {
            $local = $o->created_at->copy()->setTimezone($tz);
            $k = $local->format('Y-m-d');
            if (isset($daily[$k])) {
                $daily[$k]['orders']++;
                if ($o->status === OrderStatus::Delivered) {
                    $daily[$k]['delivered']++;
                    $daily[$k]['sales'] += (float) $o->total;
                }
            }
            $hourly[(int) $local->format('G')]++;
        }

        // الأقسام
        $sections = [];
        foreach ($sectionNames as $id => $name) {
            $in = $orders->filter(fn ($o) => ($sectionOf[$o->store_id] ?? 0) === $id);
            if ($in->isEmpty()) {
                continue;
            }
            $sections[] = self::groupStats($name, $in);
        }

        // المتاجر والسائقين
        $storeNames = Store::whereIn('id', $orders->pluck('store_id')->unique())->pluck('name', 'id');
        $ratings = Rating::whereHas('order', fn ($q) => $q->whereBetween('created_at', [$from, $to]))->with('order:id,store_id,driver_id')->get();
        $stores = $orders->groupBy('store_id')->map(function ($in, $sid) use ($storeNames, $ratings) {
            $r = $ratings->filter(fn ($x) => $x->order?->store_id === (int) $sid)->whereNotNull('store_rating');

            return self::groupStats($storeNames[$sid] ?? "متجر #$sid", $in) + [
                'rating' => $r->count() ? round((float) $r->avg('store_rating'), 1) : null,
            ];
        })->sortByDesc('delivered')->take(15)->values()->all();

        $driverNames = User::whereIn('id', $orders->pluck('driver_id')->unique()->filter())->pluck('name', 'id');
        $drivers = $orders->whereNotNull('driver_id')->groupBy('driver_id')->map(function ($in, $did) use ($driverNames, $ratings) {
            $done = $in->where('status', OrderStatus::Delivered);
            $r = $ratings->filter(fn ($x) => $x->order?->driver_id === (int) $did)->whereNotNull('driver_rating');

            return [
                'name' => $driverNames[$did] ?? "سائق #$did",
                'delivered' => $done->count(),
                'failed' => $in->where('status', OrderStatus::Failed)->count(),
                'avg_road_minutes' => self::avgMinutes($done, 'picked_up_at', 'delivered_at'),
                'avg_pickup_wait_minutes' => self::avgMinutes($done, 'ready_at', 'picked_up_at'),
                'earnings' => round((float) $done->sum('driver_earning'), 2),
                'rating' => $r->count() ? round((float) $r->avg('driver_rating'), 1) : null,
            ];
        })->sortByDesc('delivered')->take(15)->values()->all();

        // الفلوس
        $platform = $delivered->sum(fn ($o) => (float) $o->commission_amount + (float) $o->delivery_fee - (float) $o->driver_earning
            - (float) $o->discount - (float) $o->points_discount);

        // دعوة صديق
        $refApplied = User::whereBetween('referred_at', [$from, $to])->count();
        $refRewarded = User::whereBetween('referral_rewarded_at', [$from, $to])->count();
        $topReferrers = User::whereBetween('referred_at', [$from, $to])->whereNotNull('referred_by_id')
            ->get(['referred_by_id', 'referral_rewarded_at'])->groupBy('referred_by_id')
            ->map(fn ($g, $id) => ['id' => $id, 'invited' => $g->count(), 'rewarded' => $g->whereNotNull('referral_rewarded_at')->count()])
            ->sortByDesc('invited')->take(10);
        $refNames = User::whereIn('id', $topReferrers->keys())->pluck('name', 'id');

        $days = max(1, (int) ceil($from->diffInDays($to, true)));

        return [
            'from' => $from->copy()->setTimezone($tz)->format('Y-m-d'),
            'to' => $to->copy()->setTimezone($tz)->format('Y-m-d'),
            'days' => $days,
            'orders' => [
                'total' => $orders->count(),
                'per_day' => round($orders->count() / $days, 1),
                'delivered' => $delivered->count(),
                'cancelled' => $cancelled->count(),
                'failed' => $failed->count(),
                'active' => $orders->count() - $finished->count(),
                'completion_rate' => self::pct($delivered->count(), $finished->count()),
                'cancel_rate' => self::pct($cancelled->count(), $finished->count()),
                'fail_rate' => self::pct($failed->count(), $finished->count()),
                'cancel_by' => $cancelBy,
                'pickup_share' => self::pct($orders->where('fulfillment', 'pickup')->count(), $orders->count()),
                'left_at_door' => $delivered->whereNotNull('left_at_door_at')->count(),
                'payment' => [
                    'cash' => $delivered->filter(fn ($o) => $o->payment_method?->value === 'cash')->count(),
                    'wallet' => $delivered->filter(fn ($o) => $o->payment_method?->value === 'wallet')->count(),
                    'card' => $delivered->filter(fn ($o) => $o->payment_method?->value === 'card')->count(),
                ],
            ],
            'money' => [
                'sales' => round((float) $delivered->sum('total'), 2),
                'items_value' => round((float) $delivered->sum('subtotal'), 2),
                'avg_order_value' => $delivered->count() ? round((float) $delivered->avg('total'), 2) : 0,
                'commission' => round((float) $delivered->sum('commission_amount'), 2),
                'delivery_paid_by_customers' => round((float) $delivered->sum('delivery_fee'), 2),
                'delivery_subsidy' => round((float) $delivered->sum('delivery_subsidy'), 2),
                'driver_earnings' => round((float) $delivered->sum('driver_earning'), 2),
                'discounts' => round((float) $delivered->sum(fn ($o) => (float) $o->discount + (float) $o->points_discount), 2),
                'platform_net' => round((float) $platform, 2),
            ],
            'times' => [
                'accept' => self::avgMinutes($orders, 'created_at', 'accepted_at'),
                'prep' => self::avgMinutes($orders, 'accepted_at', 'ready_at'),
                'wait_driver' => self::avgMinutes($deliveryOnly, 'ready_at', 'picked_up_at'),
                'road' => self::avgMinutes($deliveryOnly, 'picked_up_at', 'delivered_at'),
                'total' => $totals->count() ? round($totals->avg(), 1) : null,
                'median_total' => $totals->count() ? round((float) $totals[intdiv($totals->count(), 2)], 1) : null,
                'within_45' => self::pct($totals->filter(fn ($m) => $m <= 45)->count(), $totals->count()),
                'within_60' => self::pct($totals->filter(fn ($m) => $m <= 60)->count(), $totals->count()),
                'avg_speed_kmh' => $speeds->count() ? round($speeds->avg(), 1) : null,
                'avg_distance_km' => $deliveryOnly->count() ? round((float) $deliveryOnly->avg('distance_km'), 1) : null,
            ],
            'customers' => [
                'new_signups' => User::withRole('customer')->whereBetween('created_at', [$from, $to])->count(),
                'ordering' => $customerIds->count(),
                'repeat_in_period' => self::pct($perCustomer->filter(fn ($n) => $n >= 2)->count(), $customerIds->count()),
                'returning' => $before->count(),
                'returning_rate' => self::pct($before->count(), $customerIds->count()),
                'new_buyers' => $newBuyers,
                'retention_30' => self::pct($retained, $newBuyers),
                'orders_per_customer' => $customerIds->count() ? round($delivered->count() / $customerIds->count(), 2) : 0,
            ],
            'ratings' => [
                'store' => $ratings->whereNotNull('store_rating')->count() ? round((float) $ratings->whereNotNull('store_rating')->avg('store_rating'), 2) : null,
                'driver' => $ratings->whereNotNull('driver_rating')->count() ? round((float) $ratings->whereNotNull('driver_rating')->avg('driver_rating'), 2) : null,
                'count' => $ratings->count(),
                'rated_share' => self::pct($ratings->count(), $delivered->count()),
            ],
            'referrals' => [
                'applied' => $refApplied,
                'rewarded' => $refRewarded,
                'top' => $topReferrers->map(fn ($r) => $r + ['name' => $refNames[$r['id']] ?? '—'])->values()->all(),
            ],
            'daily' => array_values(array_map(fn ($d) => $d + ['sales' => round($d['sales'], 2)], $daily)),
            'hourly' => $hourly,
            'sections' => $sections,
            'stores' => $stores,
            'drivers' => $drivers,
        ];
    }

    /** [from, to] من «7 · 30 · 90 · today · month» أو تاريخين */
    public static function range(?string $preset, ?string $fromDate = null, ?string $toDate = null): array
    {
        $tz = LocalDay::timezone();
        if ($fromDate && $toDate) {
            $f = Carbon::parse($fromDate, $tz)->startOfDay();
            $t = Carbon::parse($toDate, $tz)->endOfDay();
            if ($f->gt($t)) {
                [$f, $t] = [$t->copy()->startOfDay(), $f->copy()->endOfDay()];
            }
            if ($f->diffInDays($t) > 366) {
                $f = $t->copy()->subDays(366)->startOfDay();
            }

            return [$f->utc(), $t->utc()];
        }
        $now = Carbon::now($tz);

        return match ($preset) {
            'today' => [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()],
            'month' => [$now->copy()->startOfMonth()->utc(), $now->copy()->endOfDay()->utc()],
            '90' => [$now->copy()->subDays(89)->startOfDay()->utc(), $now->copy()->endOfDay()->utc()],
            '30' => [$now->copy()->subDays(29)->startOfDay()->utc(), $now->copy()->endOfDay()->utc()],
            default => [$now->copy()->subDays(6)->startOfDay()->utc(), $now->copy()->endOfDay()->utc()],
        };
    }

    // ===================== أدوات =====================

    private static function groupStats(string $name, Collection $in): array
    {
        $done = $in->where('status', OrderStatus::Delivered);
        $finished = $in->filter(fn ($o) => $o->status->isFinal());

        return [
            'name' => $name,
            'orders' => $in->count(),
            'delivered' => $done->count(),
            'cancel_rate' => self::pct($in->where('status', OrderStatus::Cancelled)->count(), $finished->count()),
            'sales' => round((float) $done->sum('total'), 2),
            'avg_order_value' => $done->count() ? round((float) $done->avg('total'), 2) : 0,
            'avg_prep_minutes' => self::avgMinutes($in, 'accepted_at', 'ready_at'),
            'avg_total_minutes' => self::avgMinutes($done->filter(fn ($o) => ($o->fulfillment ?? 'delivery') !== 'pickup'), 'created_at', 'delivered_at'),
        ];
    }

    /** store_id => app_section_id (0 = بدون قسم) */
    private static function sectionMap(): array
    {
        return Store::with('type:id,app_section_id')->get(['id', 'store_type_id'])
            ->mapWithKeys(fn ($s) => [$s->id => (int) ($s->type?->app_section_id ?? 0)])->all();
    }

    private static function minutes($a, $b): ?float
    {
        if (! $a || ! $b) {
            return null;
        }
        $m = ($b->getTimestamp() - $a->getTimestamp()) / 60;

        // قيم غريبة (تعديل يدوي، أيام) ما تخرّبش المتوسط
        return $m >= 0 && $m <= 600 ? $m : null;
    }

    private static function avgMinutes(Collection $orders, string $from, string $to): ?float
    {
        $vals = $orders->map(fn ($o) => self::minutes($o->{$from}, $o->{$to}))->filter(fn ($v) => $v !== null);

        return $vals->count() ? round($vals->avg(), 1) : null;
    }

    private static function pct(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round(100 * $part / $whole, 1) : null;
    }
}
