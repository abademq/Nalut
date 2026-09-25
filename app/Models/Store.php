<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'store_type_id', 'delivery_zone_id', 'name', 'slug', 'description',
        'logo', 'cover', 'phone', 'address', 'lat', 'lng', 'commission_percent',
        'min_order', 'prep_time_minutes', 'opens_at', 'closes_at', 'is_open', 'is_active',
        'rating_avg', 'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'lat'                => 'float',
            'lng'                => 'float',
            'commission_percent' => 'float',
            'min_order'          => 'float',
            'is_open'            => 'boolean',
            'is_active'          => 'boolean',
            'rating_avg'         => 'float',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(StoreType::class, 'store_type_id');
    }

        public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }
    
    public function sections(): HasMany
    {
        return $this->hasMany(MenuSection::class)->orderBy('sort');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** هل المتجر يستقبل طلبات توّا */
    public function isAcceptingOrders(): bool
    {
        if (! $this->is_active || ! $this->is_open) {
            return false;
        }

        if ($this->opens_at && $this->closes_at) {
            // ساعات العمل بتوقيت ليبيا — now() لوحدها UTC (فرق ساعتين)
            $now = now(\App\Support\LocalDay::timezone())->format('H:i:s');

            return $this->opens_at <= $this->closes_at
                ? ($now >= $this->opens_at && $now <= $this->closes_at)
                : ($now >= $this->opens_at || $now <= $this->closes_at);
        }

        return true;
    }
}
