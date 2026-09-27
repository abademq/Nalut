<?php

namespace App\Models;

use App\Services\OrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سلة حفظها الزبون بنفسه — يطلبها بعدين بضغطة */
class SavedCart extends Model
{
    protected $fillable = ['user_id', 'store_id', 'name', 'items'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return array<int, array{product_id: int, quantity: int, note: ?string}> */
    public function lines(): array
    {
        return collect($this->items ?? [])
            ->map(fn ($i) => [
                'product_id' => (int) ($i['product_id'] ?? 0),
                'quantity' => max(1, (int) ($i['quantity'] ?? 1)),
                'note' => $i['note'] ?? null,
                'options' => array_values((array) ($i['options'] ?? [])),
            ])
            ->filter(fn ($i) => $i['product_id'] > 0)
            ->values()->all();
    }

    public function toApp(): array
    {
        $lines = $this->lines();
        $products = Product::with('options.values')->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');

        $total = 0.0;
        $missing = 0;
        foreach ($lines as $l) {
            $p = $products->get($l['product_id']);
            if ($p && $p->is_available && ($p->is_visible ?? true)) {
                $values = $p->options->flatMap->values->keyBy('id');
                $extra = 0.0;
                foreach (OrderService::selectedOptions($l) as $id => $n) {
                    $extra += (float) ($values->get($id)?->extra_price ?? 0) * $n;
                }
                $total += ($p->effectivePrice() + $extra) * $l['quantity'];
            } else {
                $missing++;
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'store' => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'logo' => $this->store->logo ? asset('storage/'.$this->store->logo) : null,
            ] : null,
            'items_count' => array_sum(array_column($lines, 'quantity')),
            'missing' => $missing,
            'total' => round($total, 2),
            'items' => collect($lines)->map(fn ($l) => [
                'name' => $products->get($l['product_id'])?->name ?? 'صنف محذوف',
                'quantity' => $l['quantity'],
            ])->values(),
            'updated_at' => $this->updated_at,
        ];
    }
}
