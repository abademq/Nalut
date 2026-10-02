<?php

namespace App\Models;

use App\Models\Concerns\HasBlurHashes;
use App\Support\LocalDay;
use App\Support\Options;
use App\Support\Texts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Store extends Model
{
    use HasBlurHashes;

    protected const BLURHASH = ['logo' => 'logo_hash', 'cover' => 'cover_hash'];

    use SoftDeletes;

    protected $fillable = [
        'user_id', 'store_type_id', 'delivery_zone_id', 'name', 'slug', 'description', 'ingredients_mode',
        'logo', 'cover', 'phone', 'address', 'lat', 'lng', 'commission_percent',
        'min_order', 'prep_time_minutes', 'opens_at', 'closes_at', 'is_open', 'is_active', 'pickup_enabled',
        'rating_avg', 'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'pickup_enabled' => 'boolean',
            'commission_percent' => 'float',
            'min_order' => 'float',
            'is_open' => 'boolean',
            'is_active' => 'boolean',
            'rating_avg' => 'float',
            'force_open_until' => 'datetime',
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

    /**
     * هل المتجر يستقبل طلبات توّا:
     * مفتوح (المفتاح اليدوي) + (داخل ساعات العمل، أو مفتوح يدوياً خارجها لين force_open_until).
     */
    public function isAcceptingOrders(): bool
    {
        if (! $this->is_active || ! $this->is_open) {
            return false;
        }

        return $this->withinHours() || $this->isForcedOpen();
    }

    public function hasHours(): bool
    {
        return (bool) ($this->opens_at && $this->closes_at);
    }

    /** داخل ساعات العمل المدرجة (أو ما فيش ساعات = دائماً) */
    public function withinHours(): bool
    {
        if (! $this->hasHours()) {
            return true;
        }
        // ساعات العمل بتوقيت ليبيا — now() لوحدها UTC (فرق ساعتين)
        $now = now(LocalDay::timezone())->format('H:i:s');
        $open = $this->hhmmss($this->opens_at);
        $close = $this->hhmmss($this->closes_at);

        return $open <= $close
            ? ($now >= $open && $now <= $close)
            : ($now >= $open || $now <= $close);
    }

    /** مفتوح يدوياً خارج الساعات (المتجر فتح بدري أو سكّر متأخر) */
    public function isForcedOpen(): bool
    {
        return $this->force_open_until !== null && $this->force_open_until->isFuture();
    }

    private function hhmmss(string $t): string
    {
        return strlen($t) === 5 ? "$t:00" : substr($t, 0, 8);
    }

    /** وقت الفتح الجاي حسب الساعات (بتوقيت ليبيا) */
    public function nextOpening(): ?Carbon
    {
        if (! $this->hasHours()) {
            return null;
        }
        $tz = LocalDay::timezone();
        $now = now($tz);
        [$h, $m] = array_map('intval', explode(':', $this->opens_at));
        $at = $now->copy()->setTime($h, $m);

        return $at->lessThanOrEqualTo($now) ? $at->addDay() : $at;
    }

    /**
     * زر «افتح توّا» (المتجر أو الإدارة): يفتح حتى لو خارج الساعات.
     * خارج الساعات يقعد مفتوح لين يجي وقت الفتح العادي (والساعات تكمّل)،
     * بحد أقصى ساعات معيّنة — باش لو نسي يسكّر ما يقعدش يستقبل طلبات طول الليل.
     */
    public function openNow(): void
    {
        $this->is_open = true;
        $this->force_open_until = null;

        if ($this->hasHours() && ! $this->withinHours()) {
            $max = now()->addHours(max(1, (int) Options::get('stores.manual_open_max_hours')));
            $next = $this->nextOpening()?->utc();
            $this->force_open_until = $next && $next->lessThan($max) ? $next : $max;
        }
        $this->save();
    }

    public function closeNow(): void
    {
        $this->update(['is_open' => false, 'force_open_until' => null]);
    }

    /** زر واحد: لو يستقبل طلبات يسكّر، ولو مسكّر (يدوياً أو بالساعات) يفتح */
    public function toggleManual(): bool
    {
        $this->isAcceptingOrders() ? $this->closeNow() : $this->openNow();

        return $this->isAcceptingOrders();
    }

    /** وصف الحالة للمتجر والإدارة والزبون */
    public function statusText(): string
    {
        $tz = LocalDay::timezone();
        if (! $this->is_open) {
            return 'مغلق (يدوياً)';
        }
        if ($this->isForcedOpen() && ! $this->withinHours()) {
            return 'مفتوح خارج الأوقات لين '.$this->force_open_until->timezone($tz)->format('H:i');
        }
        if ($this->withinHours()) {
            return $this->hasHours() ? 'مفتوح — يسكّر الساعة '.substr($this->closes_at, 0, 5) : 'مفتوح';
        }

        return 'مغلق حسب الأوقات — يفتح الساعة '.substr($this->opens_at, 0, 5);
    }

    /** رسالة للزبون لما يحاول يطلب والمتجر مسكّر */
    public function closedMessage(): string
    {
        $base = Texts::get('msg.store_closed');
        if ($this->is_open && $this->hasHours() && ! $this->withinHours()) {
            return $base.' يفتح الساعة '.substr($this->opens_at, 0, 5).' — تقدر تجهّز سلتك وتطلب لما يفتح.';
        }

        return $base.' تقدر تجهّز سلتك وتطلب لما يفتح.';
    }
}
