<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOptionValue extends Model
{
    protected $fillable = ['product_option_id', 'name', 'image', 'extra_price', 'max_qty', 'is_available', 'sort'];

    protected function casts(): array
    {
        return ['extra_price' => 'float', 'max_qty' => 'integer', 'is_available' => 'boolean'];
    }

    /** رابط صورة الاختيار (اللون الأحمر مثلاً) — null لو ما عندهاش */
    public function imageUrl(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
