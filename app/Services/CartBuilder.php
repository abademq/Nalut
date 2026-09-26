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
    /** @param  array<int, array{product_id: int, quantity: int, note?: ?string}>  $lines */
    public function build(Store $store, array $lines): array
    {
        $products = Product::where('store_id', $store->id)
            ->whereIn('id', array_column($lines, 'product_id'))
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

            $out[] = ['product' => new ProductResource($p), 'quantity' => $qty, 'note' => $l['note'] ?? null];
        }

        return [
            'store'   => ['id' => $store->id, 'name' => $store->name, 'is_accepting' => $store->isAcceptingOrders()],
            'lines'   => $out,
            'missing' => array_values(array_unique($missing)),
        ];
    }
}
