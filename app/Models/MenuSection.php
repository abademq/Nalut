<?php

namespace App\Models;

use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class MenuSection extends Model
{
    protected $fillable = ['store_id', 'name', 'sort', 'is_active', 'is_available', 'paused_until'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_available' => 'boolean', 'paused_until' => 'datetime'];
    }

    /**
     * القسم يقبل طلبات توّا؟
     * موقوف «لين نفتحه» = is_available false بدون وقت.
     * موقوف «لين الساعة X» = يرجع متاح وحده لما يجي الوقت (بدون cron).
     */
    public function isOrderable(): bool
    {
        if ($this->is_available ?? true) {
            return true;
        }

        return $this->paused_until !== null && $this->paused_until->isPast();
    }

    /** النص اللي يطلع للزبون تحت اسم القسم (null = متاح) */
    public function pausedText(): ?string
    {
        if ($this->isOrderable()) {
            return null;
        }

        return $this->paused_until
            ? 'يتوفر الساعة '.LocalDay::toLocal($this->paused_until)->format('H:i')
            : 'غير متاح حالياً';
    }

    /**
     * إيقاف/تشغيل بضغطة: $until = «HH:MM» بتوقيت ليبيا (لو فات اليوم = بكرة)، أو null = لين نفتحه.
     */
    public function pause(bool $available, ?string $until = null): self
    {
        $at = null;
        if (! $available && $until) {
            $tz = LocalDay::timezone();
            $at = Carbon::createFromFormat('H:i', $until, $tz)->seconds(0);
            if ($at->lte(Carbon::now($tz))) {
                $at->addDay();
            }
            $at = $at->utc();
        }

        $this->update(['is_available' => $available, 'paused_until' => $at]);

        return $this;
    }

    /** للتطبيقات: حالة القسم */
    public function toApp(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_available' => $this->isOrderable(),
            'paused_until' => $this->isOrderable() ? null : $this->paused_until?->toIso8601String(),
            'paused_text' => $this->pausedText(),
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort');
    }

    /** أصناف من أقسام ثانية تظهر كمان هني (مثلاً قسم «العروض») */
    public function extraProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'menu_section_product');
    }
}
