<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreType extends Model
{
    protected $fillable = ['name', 'icon', 'sort', 'is_active', 'has_ingredients', 'app_section_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'has_ingredients' => 'boolean'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AppSection::class, 'app_section_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
