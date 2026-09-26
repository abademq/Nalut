<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'max_discount', 'min_order', 'store_id',
        'usage_limit', 'used_count', 'per_user_limit', 'starts_at', 'ends_at', 'is_active',
    ];

    protected $attributes = ['value' => 0, 'min_order' => 0, 'used_count' => 0];

    protected function casts(): array
    {
        return [
            'value'        => 'float',
            'max_discount' => 'float',
            'min_order'    => 'float',
            'is_active'    => 'boolean',
            'starts_at'    => 'datetime',
            'ends_at'      => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isUsableBy(User $user, float $subtotal, ?int $storeId = null): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }
        if ($this->store_id && $this->store_id !== $storeId) {
            return false;
        }
        if ($subtotal < $this->min_order) {
            return false;
        }
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        $usedByUser = DB::table('coupon_user')
            ->where('coupon_id', $this->id)
            ->where('user_id', $user->id)
            ->count();

        return $usedByUser < $this->per_user_limit;
    }

    /**
     * قيمة الخصم.
     * percent        = نسبة من قيمة الأصناف
     * fixed          = مبلغ ثابت
     * free_delivery  = رسوم التوصيل كاملة
     */
    public function discountFor(float $subtotal, float $deliveryFee = 0): float
    {
        $discount = match ($this->type) {
            'free_delivery' => $deliveryFee,
            'percent'       => $subtotal * ($this->value / 100),
            default         => $this->value,
        };

        if ($this->max_discount && $this->type !== 'free_delivery') {
            $discount = min($discount, $this->max_discount);
        }

        return round(min($discount, $subtotal + $deliveryFee), 2);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'free_delivery' => 'توصيل مجاني',
            'percent'       => 'نسبة مئوية',
            default         => 'مبلغ ثابت',
        };
    }
}
