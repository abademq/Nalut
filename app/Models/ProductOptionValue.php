<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOptionValue extends Model
{
    protected $fillable = ['product_option_id', 'name', 'extra_price', 'is_available', 'sort'];

    protected function casts(): array
    {
        return ['extra_price' => 'float', 'is_available' => 'boolean'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
