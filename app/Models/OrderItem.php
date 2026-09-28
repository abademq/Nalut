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
            'options' => 'array',
            'unit_price' => 'float',
            'options_price' => 'float',
            'line_total' => 'float',
            'is_unavailable' => 'boolean',
        ];
    }

    /** «الإضافات: زيادة صوص، سيخ كباب ×2 · الحجم: كبير» — للعرض في اللوحة والواصل */
    public function optionsText(string $sep = ' · '): string
    {
        return collect($this->options ?? [])
            ->groupBy(fn ($o) => is_array($o) ? (string) ($o['option'] ?? '') : '')
            ->map(function ($group, $option) {
                $values = $group->map(function ($o) {
                    if (! is_array($o)) {
                        return (string) $o;
                    }
                    $qty = (int) ($o['qty'] ?? 1);

                    return ($o['value'] ?? '').($qty > 1 ? " ×$qty" : '');
                })->filter()->implode('، ');

                return $option !== '' ? "$option: $values" : $values;
            })
            ->filter()
            ->implode($sep);
    }

    /** «بصل، بطاطا» — المكوّنات اللي الزبون شالها */
    public function removedText(): string
    {
        return collect($this->options ?? [])->filter(fn ($o) => is_array($o) && ! empty($o['removed']))
            ->pluck('value')->implode('، ');
    }

    /** الإضافات والخيارات بدون «بدون» */
    public function addedText(string $sep = ' · '): string
    {
        $copy = clone $this;
        $copy->options = collect($this->options ?? [])->reject(fn ($o) => is_array($o) && ! empty($o['removed']))->values()->all();

        return $copy->optionsText($sep);
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
