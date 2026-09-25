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
        'store_id', 'menu_section_id', 'name', 'description', 'image', 'images',
        'price', 'discount_price', 'is_available', 'sort',
        'track_stock', 'stock_quantity', 'max_per_order', 'low_stock_alert', 'sold_out_at',
    ];

    protected function casts(): array
    {
        return [
            'price'          => 'float',
            'discount_price' => 'float',
            'is_available'   => 'boolean',
            'track_stock'    => 'boolean',
            'stock_quantity' => 'integer',
            'images'         => 'array',
            'sold_out_at'    => 'datetime',
        ];
    }

    /** أقصى عدد صور للمنتج */
    public const MAX_IMAGES = 8;

    protected static function booted(): void
    {
        // image = أول صورة في images دائماً — التطبيقات القديمة والجداول تقرا image
        static::saving(function (Product $p) {
            if ($p->isDirty('images')) {
                $images = array_values(array_filter((array) $p->images));
                $p->images = $images ?: null;
                $p->image = $images[0] ?? null;
            } elseif ($p->isDirty('image')) {
                $rest = array_values(array_diff((array) $p->images, [$p->getOriginal('image'), $p->image]));
                $images = array_values(array_filter([$p->image, ...$rest]));
                $p->images = $images ?: null;
            }

            // المنتج اللي تخفّى لأنه خلص يرجع يظهر أول ما المخزون يرجع
            if ($p->sold_out_at && ($p->isDirty('stock_quantity') || $p->isDirty('track_stock'))
                && (! $p->track_stock || $p->stock_quantity > 0)) {
                $p->is_available = true;
                $p->sold_out_at = null;
            } elseif ($p->sold_out_at && $p->isDirty('is_available') && $p->is_available) {
                $p->sold_out_at = null;
            }
        });
    }

    /** روابط كل الصور بالترتيب */
    public function imageUrls(): array
    {
        return array_map(fn ($path) => asset('storage/'.$path), (array) $this->images);
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
            $this->update(['is_available' => false, 'stock_quantity' => 0, 'sold_out_at' => now()]);
        }
    }

    /**
     * إرجاع المخزون لو انلغى الطلب.
     * لو المنتج كان تخفّى تلقائياً لأنه خلص، يرجع يظهر.
     * (لو المتجر خبّاه بيده ما نلمسوش — sold_out_at يكون فاضي)
     */
    public function restoreStock(int $quantity): void
    {
        if (! $this->track_stock || $quantity <= 0) {
            return;
        }

        static::whereKey($this->id)->increment('stock_quantity', $quantity);

        static::whereKey($this->id)
            ->whereNotNull('sold_out_at')
            ->where('stock_quantity', '>', 0)
            ->update(['is_available' => true, 'sold_out_at' => null]);

        $this->refresh();
    }

    public function isLowStock(): bool
    {
        return $this->track_stock
            && $this->low_stock_alert
            && $this->stock_quantity <= $this->low_stock_alert;
    }
}
