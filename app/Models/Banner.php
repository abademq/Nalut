<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Banner extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'image', 'color', 'store_id', 'url',
        'placement', 'app_section_id', 'show_store_id',
        'sort', 'is_active', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** وين يطلع الإعلان */
    public const PLACEMENTS = [
        'home' => 'الرئيسية (قبل ما يختار قسم)',
        'everywhere' => 'الرئيسية وكل الأقسام',
        'section' => 'قسم معيّن',
        'store' => 'صفحة متجر معيّن',
        'cart' => 'صفحة السلة',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(AppSection::class, 'app_section_id');
    }

    public function showStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'show_store_id');
    }

    public function placementLabel(): string
    {
        return match ($this->placement) {
            'section' => 'قسم: '.($this->section?->name ?? '—'),
            'store' => 'متجر: '.($this->showStore?->name ?? '—'),
            default => self::PLACEMENTS[$this->placement] ?? $this->placement,
        };
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** المفعّلة وداخل فترة العرض */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort')
            ->orderByDesc('id');
    }

    public function toApp(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image' => $this->image ? asset('storage/'.$this->image) : null,
            'color' => $this->color,
            'store_id' => $this->store_id,
            'url' => $this->url,
            // وين يطلع: home | everywhere | section | store | cart
            'placement' => $this->placement ?? 'home',
            'section_id' => $this->app_section_id,
            'show_store_id' => $this->show_store_id,
        ];
    }
}
