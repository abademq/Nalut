<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** شريط عروض بسيط — فوق التطبيق أو فوق متجر */
class Announcement extends Model
{
    protected $fillable = ['text', 'bg_color', 'text_color', 'link', 'store_id', 'starts_at', 'ends_at', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort')->orderByDesc('id');
    }

    public function toApp(): array
    {
        return [
            'id'         => $this->id,
            'text'       => $this->text,
            'bg_color'   => $this->bg_color,
            'text_color' => $this->text_color,
            'link'       => $this->link,
        ];
    }
}
