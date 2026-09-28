<?php

namespace App\Models;

use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'store_type_id', 'delivery_zone_id', 'name', 'slug', 'description', 'ingredients_mode',
        'logo', 'cover', 'phone', 'address', 'lat', 'lng', 'commission_percent',
        'min_order', 'prep_time_minutes', 'opens_at', 'closes_at', 'is_open', 'is_active',
        'rating_avg', 'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'commission_percent' => 'float',
            'min_order' => 'float',
            'is_open' => 'boolean',
            'is_active' => 'boolean',
            'rating_avg' => 'float',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** فاضي = حسب نوع المتجر */
    public const INGREDIENTS_MODES = [
        'on' => 'مفعّل لهذا المتجر',
        'off' => 'موقوف لهذا المتجر',
    ];

    /** خيار المكوّنات («بدون بصل») — للمطاعم والمقاهي، يتدار من اللوحة */
    public function ingredientsEnabled(): bool
    {
        return match ($this->ingredients_mode) {
            'on' => true,
            'off' => false,
            default => (bool) $this->type?->has_ingredients,
        };
    }

    /** نفس الشي برقم المتجر — مرة وحدة في الطلب (قائمة أصناف كاملة ما تديرش استعلام لكل صنف) */
    public static function ingredientsEnabledFor(?int $storeId): bool
    {
        if (! $storeId) {
            return false;
        }

        return once(fn () => (bool) static::with('type')->find($storeId)?->ingredientsEnabled());
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
            $now = now(LocalDay::timezone())->format('H:i:s');

            return $this->opens_at <= $this->closes_at
                ? ($now >= $this->opens_at && $now <= $this->closes_at)
                : ($now >= $this->opens_at || $now <= $this->closes_at);
        }

        return true;
    }
}
