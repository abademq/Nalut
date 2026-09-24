<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'code', 'customer_id', 'store_id', 'delivery_zone_id', 'driver_id', 'coupon_id', 'status',
        'payment_method', 'is_paid', 'wallet_paid', 'earnings_settled',
        'address_details', 'address_landmark',
        'address_lat', 'address_lng', 'customer_phone', 'subtotal', 'delivery_fee',
        'discount', 'total', 'commission_amount', 'store_earning', 'driver_earning',
        'distance_km', 'notes', 'prep_time_minutes', 'accepted_at', 'ready_at', 'drivers_notified_at',
        'picked_up_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'status'         => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'is_paid'          => 'boolean',
            'wallet_paid'      => 'float',
            'earnings_settled' => 'boolean',
            'address_lat'    => 'float',
            'address_lng'    => 'float',
            'subtotal'       => 'float',
            'delivery_fee'   => 'float',
            'discount'       => 'float',
            'total'          => 'float',
            'distance_km'    => 'float',
            'accepted_at'    => 'datetime',
            'ready_at'       => 'datetime',
            'picked_up_at'   => 'datetime',
            'delivered_at'   => 'datetime',
            'cancelled_at'   => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('id');
    }

    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', OrderStatus::active());
    }

    /**
     * هل الطلب متاح للسائقين؟
     * إما المتجر حدّده جاهز، أو انقضى وقت التحضير اللي حدّده.
     */
    public function isAvailableForDrivers(): bool
    {
        if ($this->driver_id) {
            return false;
        }

        if ($this->status === OrderStatus::Ready) {
            return true;
        }

        if ($this->status !== OrderStatus::Preparing || ! $this->accepted_at) {
            return false;
        }

        return $this->accepted_at
            ->addMinutes((int) ($this->prep_time_minutes ?? 0))
            ->isPast();
    }

    /** كم دقيقة باقية على جاهزية الطلب */
    public function minutesUntilReady(): int
    {
        if ($this->status === OrderStatus::Ready || ! $this->accepted_at) {
            return 0;
        }

        $readyAt = $this->accepted_at->addMinutes((int) ($this->prep_time_minutes ?? 0));

        return max(0, (int) ceil(now()->diffInMinutes($readyAt, false)));
    }

    /**
     * رقم طلب من 6 أرقام فقط — أسهل على الزبون والمتجر
     * في المكالمات والفواتير المطبوعة.
     */
    public static function generateCode(): string
    {
        for ($i = 0; $i < 30; $i++) {
            $code = (string) random_int(100000, 999999);

            if (! self::where('code', $code)->exists()) {
                return $code;
            }
        }

        // احتياطي لو الأرقام العشوائية تزاحمت
        $last = (int) (self::max('code') ?: 100000);

        return (string) min($last + 1, 999999);
    }
}
