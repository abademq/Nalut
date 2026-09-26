<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'name', 'unit_price', 'quantity',
        'options', 'options_price', 'line_total', 'note', 'is_unavailable',
    ];

    protected function casts(): array
    {
        return [
            'options'       => 'array',
            'unit_price'    => 'float',
            'options_price' => 'float',
            'line_total'    => 'float',
            'is_unavailable' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
