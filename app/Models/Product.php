<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id', 'menu_section_id', 'name', 'description', 'image',
        'price', 'discount_price', 'is_available', 'sort',
        'track_stock', 'stock_quantity', 'max_per_order', 'low_stock_alert',
    ];

    protected function casts(): array
    {
        return [
            'price'          => 'float',
            'discount_price' => 'float',
            'is_available'   => 'boolean',
            'track_stock'    => 'boolean',
            'stock_quantity' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class, 'menu_section_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort');
    }

    public function effectivePrice(): float
    {
        return (float) ($this->discount_price ?: $this->price);
    }

    /** هل يقدر الزبون يطلب الكمية هذي؟ */
    public function canOrder(int $quantity): bool
    {
        if (! $this->is_available) {
            return false;
        }

        if ($this->max_per_order && $quantity > $this->max_per_order) {
            return false;
        }

        if ($this->track_stock && $this->stock_quantity < $quantity) {
            return false;
        }

        return true;
    }

    /** إنقاص المخزون بعد الطلب — ويخفي المنتج لو خلص */
    public function reduceStock(int $quantity): void
    {
        if (! $this->track_stock) {
            return;
        }

        $this->decrement('stock_quantity', $quantity);
        $this->refresh();

        if ($this->stock_quantity <= 0) {
            $this->update(['is_available' => false, 'stock_quantity' => 0]);
        }
    }

    /** إرجاع المخزون لو انلغى الطلب */
    public function restoreStock(int $quantity): void
    {
        if (! $this->track_stock) {
            return;
        }

        $this->increment('stock_quantity', $quantity);
    }

    public function isLowStock(): bool
    {
        return $this->track_stock
            && $this->low_stock_alert
            && $this->stock_quantity <= $this->low_stock_alert;
    }
}
