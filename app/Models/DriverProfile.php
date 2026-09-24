<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\DriverLocationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class DriverProfile extends Model
{
    public const MODES = [
        'single'     => 'طلب واحد في نفس الوقت',
        'same_store' => 'عدة طلبات من نفس المتجر فقط',
        'any'        => 'عدة طلبات من أي متجر',
    ];

    protected $fillable = [
        'user_id', 'vehicle_type', 'plate_number', 'national_id', 'id_photo',
        'is_approved', 'is_online', 'max_active_orders', 'multi_order_mode',
        'current_lat', 'current_lng', 'location_updated_at',
        'cash_in_hand', 'rating_avg', 'rating_count', 'delivered_count',
    ];

    protected function casts(): array
    {
        return [
            'is_approved'         => 'boolean',
            'is_online'           => 'boolean',
            'max_active_orders'   => 'integer',
            'current_lat'         => 'float',
            'current_lng'         => 'float',
            'cash_in_hand'        => 'decimal:2',
            'location_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** مناطق العمل — فاضية معناها يشتغل في كل المناطق */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryZone::class, 'driver_zones');
    }

    /**
     * الموقع الحالي — من الكاش أولاً (المصدر الحقيقي)،
     * ومن أعمدة الجدول كاحتياطي للبيانات القديمة.
     *
     * DriverLocationService يكتب في الكاش فقط بـ TTL 120 ثانية،
     * فأعمدة current_lat/lng تضل فاضية للسائقين الجدد.
     */
    public function liveLocation(): ?array
    {
        if ($cached = DriverLocationService::get($this->user_id)) {
            return [
                'lat' => (float) $cached['lat'],
                'lng' => (float) $cached['lng'],
                'at'  => (int) $cached['at'],
            ];
        }

        if ($this->current_lat && $this->location_updated_at) {
            return [
                'lat' => (float) $this->current_lat,
                'lng' => (float) $this->current_lng,
                'at'  => $this->location_updated_at->timestamp,
            ];
        }

        return null;
    }

    /// السائق يُعتبر متاح فعلياً لو موقعه تحدّث حديثاً.
    /// يحمينا من سائق أقفل التطبيق بدون ما يوقف «متاح».
    public function isReallyOnline(int $staleMinutes = 5): bool
    {
        if (! $this->is_online) {
            return false;
        }

        $age = $this->locationAgeMinutes();

        return $age !== null && $age <= $staleMinutes;
    }

    public function locationAgeMinutes(): ?int
    {
        $location = $this->liveLocation();

        if (! $location) {
            return null;
        }

        return (int) floor((now()->timestamp - $location['at']) / 60);
    }

    public function servesZone(?int $zoneId): bool
    {
        $ids = $this->zones()->pluck('delivery_zones.id');

        return $ids->isEmpty() || ($zoneId && $ids->contains($zoneId));
    }

    /** الطلبات اللي لسه ماشية عند السائق */
    public function activeOrders(): Collection
    {
        return Order::where('driver_id', $this->user_id)
            ->whereIn('status', OrderStatus::active())
            ->get();
    }

    public function modeLabel(): string
    {
        return self::MODES[$this->multi_order_mode] ?? $this->multi_order_mode;
    }

    /**
     * هل يقدر ياخذ هذا الطلب؟
     * ترجع null لو مسموح، أو رسالة سبب الرفض.
     */
    public function refusalReason(Order $order): ?string
    {
        if (! $this->is_approved) {
            return 'حسابك لسه ما تمش اعتماده من الإدارة.';
        }

        if (! $this->servesZone($order->delivery_zone_id)) {
            return 'الطلب خارج مناطق عملك.';
        }

        $active = $this->activeOrders();

        if ($active->count() >= $this->max_active_orders) {
            return $this->max_active_orders === 1
                ? 'عندك طلب ماشي — خلّصه أول.'
                : "وصلت الحد الأقصى ({$this->max_active_orders} طلبات في نفس الوقت).";
        }

        if ($active->isNotEmpty()) {
            if ($this->multi_order_mode === 'single') {
                return 'حسابك مضبوط على طلب واحد في نفس الوقت.';
            }

            if ($this->multi_order_mode === 'same_store'
                && $active->pluck('store_id')->unique()->first() !== $order->store_id) {
                return 'تقدر تاخذ عدة طلبات بس من نفس المتجر.';
            }
        }

        return null;
    }

    public function canAccept(Order $order): bool
    {
        return $this->refusalReason($order) === null;
    }

    /** كم طلب ثاني يقدر ياخذ توّا */
    public function remainingCapacity(): int
    {
        return max(0, $this->max_active_orders - $this->activeOrders()->count());
    }
}
