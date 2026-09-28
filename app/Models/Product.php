<?php

namespace App\Models;

use App\Support\Options;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id', 'menu_section_id', 'name', 'description', 'ingredients', 'image', 'images',
        'price', 'discount_price', 'is_available', 'is_visible', 'sort',
        'track_stock', 'stock_quantity', 'max_per_order', 'low_stock_alert', 'sold_out_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'discount_price' => 'float',
            'is_available' => 'boolean',
            'is_visible' => 'boolean',
            'track_stock' => 'boolean',
            'stock_quantity' => 'integer',
            'images' => 'array',
            'ingredients' => 'array',
            'sold_out_at' => 'datetime',
        ];
    }

    /** أقصى عدد مكوّنات للصنف */
    public const MAX_INGREDIENTS = 30;

    /** كلمة «بدون» اللي تطلع في الطلب والواصل: «بدون: بصل، مايونيز» */
    public const REMOVED_LABEL = 'بدون';

    /**
     * ينظّف قائمة المكوّنات اللي جاية من اللوحة أو تطبيق المتجر.
     * يقبل: ["بصل", ...] أو [{name, removable}] أو JSON نص.
     *
     * @return array<int, array{name: string, removable: bool}>
     */
    public static function normalizeIngredients(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $out = [];
        foreach ((array) $raw as $i) {
            $name = trim((string) (is_array($i) ? ($i['name'] ?? '') : $i));
            $name = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 60);
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            $out[$name] = ['name' => $name, 'removable' => is_array($i) ? filter_var($i['removable'] ?? true, FILTER_VALIDATE_BOOLEAN) : true];
            if (count($out) >= self::MAX_INGREDIENTS) {
                break;
            }
        }

        return array_values($out);
    }

    /** المتجر مفعّل عنده خيار المكوّنات (من اللوحة) */
    public function ingredientsEnabled(): bool
    {
        return Store::ingredientsEnabledFor($this->store_id);
    }

    /** @return array<int, array{name: string, removable: bool}> */
    public function ingredientsList(): array
    {
        return self::normalizeIngredients($this->ingredients ?? []);
    }

    /** أسماء المكوّنات اللي الزبون يقدر يشيلها */
    public function removableIngredients(): array
    {
        if (! $this->ingredientsEnabled()) {
            return [];
        }

        return array_values(array_map(fn ($i) => $i['name'],
            array_filter($this->ingredientsList(), fn ($i) => $i['removable'])));
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

            // منتج بكمية وخلص: ما يتفتحش إلا بكمية جديدة (من أي مكان: التطبيق، اللوحة، أو الكود)
            if ($p->isOutOfStock() && $p->is_available) {
                $p->is_available = false;
                $p->sold_out_at ??= now();
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

    /** أقسام إضافية يظهر فيها الصنف (غير قسمه الأساسي) — مثلاً «العروض» */
    public function extraSections(): BelongsToMany
    {
        return $this->belongsToMany(MenuSection::class, 'menu_section_product');
    }

    /** كل الأقسام اللي يظهر فيها: الأساسي أول، وبعده الإضافية */
    public function sectionIds(): array
    {
        $extra = $this->relationLoaded('extraSections')
            ? $this->extraSections->pluck('id')->all()
            : $this->extraSections()->pluck('menu_sections.id')->all();

        return array_values(array_unique(array_filter([$this->menu_section_id, ...$extra])));
    }

    /**
     * الأقسام الإضافية — بس أقسام نفس المتجر، وبدون القسم الأساسي.
     *
     * @param  array<int|string>|string|null  $ids  (من multipart ممكن تجي JSON نص)
     */
    public function syncExtraSections(array|string|null $ids): void
    {
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        $valid = MenuSection::where('store_id', $this->store_id)
            ->whereIn('id', array_map('intval', (array) $ids))
            ->where('id', '!=', (int) $this->menu_section_id)
            ->pluck('id')->all();

        $this->extraSections()->sync($valid);
        $this->unsetRelation('extraSections');
    }

    /**
     * نسخة جديدة من الصنف بكل تفاصيله: الصور (نسخ ملفات جديدة)، الإضافات، المكوّنات، الأقسام.
     * النسخة تبدا مخفية عن الزبائن — المتجر يعدّلها ويظهرها لما تجهز.
     */
    public function duplicate(): self
    {
        return DB::transaction(function () {
            $disk = Storage::disk('public');

            // كل نسخة عندها ملفات صورها — حذف صورة من وحدة ما يمسحهاش من الثانية
            $images = [];
            foreach ((array) $this->images as $path) {
                if (! $path || ! $disk->exists($path)) {
                    continue;
                }
                $new = dirname($path).'/'.Str::random(24).'.'.pathinfo($path, PATHINFO_EXTENSION);
                $disk->copy($path, $new);
                $images[] = $new;
            }

            $copy = $this->replicate(['image', 'images', 'sold_out_at', 'deleted_at']);
            $copy->name = mb_substr($this->name.' (نسخة)', 0, 120);
            $copy->images = $images ?: null;
            $copy->is_visible = false;
            $copy->sort = (int) static::where('store_id', $this->store_id)->max('sort') + 1;
            $copy->save();

            foreach ($this->options()->with('values')->get() as $o) {
                $newOption = $copy->options()->create($o->only(['name', 'type', 'is_required', 'max_choices', 'sort']));
                foreach ($o->values as $v) {
                    $newOption->values()->create($v->only(['name', 'extra_price', 'max_qty', 'is_available', 'sort']));
                }
            }

            $copy->extraSections()->sync($this->extraSections()->pluck('menu_sections.id'));

            return $copy->fresh(['options.values']);
        });
    }

    public function effectivePrice(): float
    {
        return (float) ($this->discount_price ?: $this->price);
    }

    /** يتتبّع الكمية وخلص — ما يتفتحش للطلب لين تنضاف كمية */
    public function isOutOfStock(): bool
    {
        return (bool) $this->track_stock && (int) $this->stock_quantity <= 0;
    }

    /**
     * حالة المنتج — مرجع واحد للّوحة والتطبيقات والموقع:
     *
     *   available  متوفر
     *   low        متوفر لكن الكمية قرّبت تخلص (تحت «تنبيه عند الكمية»)
     *   sold_out   نفد: يتتبّع كمية ووصلت صفر — يتفتح لحاله أول ما تنضاف كمية
     *   stopped    موقوف: المتجر (أو الإدارة) قفله بإيده — يقعد مقفول لين يفتحوه، حتى لو فيه كمية
     */
    public function state(): string
    {
        if ($this->isOutOfStock()) {
            return 'sold_out';
        }
        if (! $this->is_available) {
            return 'stopped';
        }
        if ($this->track_stock && (int) $this->stock_quantity <= $this->lowStockLevel()) {
            return 'low';
        }

        return 'available';
    }

    /** «قرّب يخلص» تحت الرقم هذا: تنبيه المنتج، ولو فاضي الإعداد العام */
    public function lowStockLevel(): int
    {
        return (int) ($this->low_stock_alert ?? rescue(fn () => Options::get('stock.low_label_at'), 5, false));
    }

    /** اللي يبان للزبائن بس (المخفي ما يبانش حتى لو متوفر) */
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    /**
     * الزبون يشوف حاجتين بس: متوفر (ومعاه «X قطع فقط» لو المتجر حدد تنبيه) أو غير متوفر.
     * «قطع فقط» تطلع بس لو المتجر كتب «تنبيه عند الكمية» للصنف — هو اللي يقرر.
     */
    public function customerLeft(): ?int
    {
        if (! $this->is_available || ! $this->track_stock || $this->low_stock_alert === null) {
            return null;
        }
        $left = (int) $this->stock_quantity;

        return $left > 0 && $left <= (int) $this->low_stock_alert ? $left : null;
    }

    public const STATE_LABELS = [
        'available' => 'متوفر',
        'low' => 'قرّب يخلص',
        'sold_out' => 'نفد',
        'stopped' => 'موقوف',
    ];

    public const OUT_OF_STOCK_MESSAGE = 'المنتج هذا خلص. زيد كمية جديدة أول باش تقدر تفتحه للطلب.';

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
