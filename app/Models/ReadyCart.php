<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سلة جاهزة: أصناف من متجر واحد تنضاف للسلة بضغطة */
class ReadyCart extends Model
{
    protected $fillable = ['store_id', 'name', 'description', 'image', 'items', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['items' => 'array', 'is_active' => 'boolean'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return array<int, array{product_id: int, quantity: int}> */
    public function lines(): array
    {
        return collect($this->items ?? [])
            ->map(fn ($i) => ['product_id' => (int) ($i['product_id'] ?? 0), 'quantity' => max(1, (int) ($i['quantity'] ?? 1))])
            ->filter(fn ($i) => $i['product_id'] > 0)
            ->values()->all();
    }

    /** السعر الحالي للسلة (الأصناف المتوفرة بس) */
    public function price(): float
    {
        $products = Product::whereIn('id', array_column($this->lines(), 'product_id'))->get()->keyBy('id');

        return round(collect($this->lines())->sum(fn ($l) => ($p = $products->get($l['product_id'])) && $p->is_available
            ? $p->effectivePrice() * $l['quantity'] : 0), 2);
    }
}
