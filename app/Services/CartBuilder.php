<?php

namespace App\Services;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Store;

/**
 * يبني سلة جاهزة للتطبيق من قائمة أصناف (إعادة طلب، سلة جاهزة، تعديل طلب).
 * الأسعار والتوفّر من قاعدة البيانات توّا — والناقص يرجع في «missing».
 */
class CartBuilder
{
    /** @param  array<int, array{product_id: int, quantity: int, note?: ?string, options?: array}>  $lines */
    public function build(Store $store, array $lines): array
    {
        $products = Product::where('store_id', $store->id)
            ->whereIn('id', array_column($lines, 'product_id'))
            ->with('options.values')
            ->get()->keyBy('id');

        $out = [];
        $missing = [];

        foreach ($lines as $l) {
            $p = $products->get($l['product_id']);

            if (! $p || ! $p->is_available) {
                $missing[] = $p?->name ?? 'صنف محذوف';

                continue;
            }

            $qty = max(1, (int) $l['quantity']);
            if ($p->max_per_order) {
                $qty = min($qty, $p->max_per_order);
            }
            if ($p->track_stock) {
                if ($p->stock_quantity <= 0) {
                    $missing[] = $p->name;

                    continue;
                }
                $qty = min($qty, $p->stock_quantity);
            }

            // الإضافات اللي لسه موجودة ومتوفرة بس، وفي حدود العدد المسموح
            $values = $p->options->flatMap->values->keyBy('id');
            $options = [];
            foreach (OrderService::selectedOptions($l) as $id => $n) {
                $v = $values->get($id);
                if ($v && $v->is_available) {
                    $options[] = ['id' => $id, 'qty' => min($n, max(1, (int) $v->max_qty))];
                }
            }

            $out[] = ['product' => new ProductResource($p), 'quantity' => $qty, 'note' => $l['note'] ?? null, 'options' => $options];
        }

        return [
            'store' => ['id' => $store->id, 'name' => $store->name, 'is_accepting' => $store->isAcceptingOrders()],
            'lines' => $out,
            'missing' => array_values(array_unique($missing)),
        ];
    }
}
