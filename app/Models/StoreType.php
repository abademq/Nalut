<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreType extends Model
{
    protected $fillable = ['name', 'icon', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
