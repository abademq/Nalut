<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * حفظ الإضافات والخيارات لمنتج دفعة وحدة (من تطبيق المتجر).
 *
 * التطبيق يبعث القائمة كاملة: الموجود (بـ id) يتعدّل، الجديد ينضاف، واللي مش في القائمة ينمسح.
 * الطلبات القديمة ما تتأثرش — الإضافات منسوخة فيها بالاسم والسعر.
 */
class ProductOptionsSync
{
    public const RULES = [
        'options' => ['present', 'array', 'max:15'],
        'options.*.id' => ['nullable', 'integer'],
        'options.*.name' => ['required', 'string', 'max:60'],
        'options.*.type' => ['required', 'in:single,multi'],
        'options.*.is_required' => ['nullable', 'boolean'],
        'options.*.max_choices' => ['nullable', 'integer', 'min:1', 'max:30'],
        'options.*.values' => ['required', 'array', 'min:1', 'max:30'],
        'options.*.values.*.id' => ['nullable', 'integer'],
        'options.*.values.*.name' => ['required', 'string', 'max:60'],
        'options.*.values.*.extra_price' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        'options.*.values.*.max_qty' => ['nullable', 'integer', 'min:1', 'max:20'],
        'options.*.values.*.is_available' => ['nullable', 'boolean'],
        // صورة الاختيار: مسار رفعته «store/option-images»، أو الرابط الحالي، أو null = بدون صورة
        'options.*.values.*.image' => ['nullable', 'string', 'max:500'],
    ];

    /**
     * صورة الاختيار من التطبيق:
     * ما انبعتتش = تقعد زي ما هي · null/فاضية = تنشال · نفس الحالية (مسار أو رابط) = تقعد ·
     * مسار جديد = لازم يكون مرفوع لنفس المتجر (options/{store}/...) وموجود.
     */
    private function resolveImage(Product $product, ?string $current, array $v): ?string
    {
        if (! array_key_exists('image', $v)) {
            return $current;
        }
        $img = trim((string) $v['image']);
        if ($img === '') {
            return null;
        }
        $prefix = asset('storage').'/';
        if (str_starts_with($img, $prefix)) {
            $img = substr($img, strlen($prefix));
        }
        if ($img === $current) {
            return $current;
        }
        if (! str_starts_with($img, "options/{$product->store_id}/") || str_contains($img, '..')
            || ! Storage::disk('public')->exists($img)) {
            throw ValidationException::withMessages(['options' => 'صورة اختيار مش صالحة — عاود ارفعها.']);
        }

        return $img;
    }

    public function sync(Product $product, array $options): void
    {
        DB::transaction(function () use ($product, $options) {
            $existing = $product->options()->with('values')->get()->keyBy('id');
            $keep = [];

            // validate() ممكن يرجّع المصفوفة بترتيب مختلف (العناصر اللي فيها id أول) — نرجعو ترتيب التطبيق
            ksort($options);
            foreach (array_values($options) as $i => $o) {
                ksort($o['values']);
                $option = isset($o['id']) ? $existing->get((int) $o['id']) : null;
                if (isset($o['id']) && ! $option) {
                    throw ValidationException::withMessages(['options' => 'مجموعة إضافات مش تابعة للمنتج هذا.']);
                }

                $single = $o['type'] === 'single';
                $attrs = [
                    'name' => trim($o['name']),
                    'type' => $o['type'],
                    'is_required' => (bool) ($o['is_required'] ?? false),
                    'max_choices' => $single ? 1 : max(1, (int) ($o['max_choices'] ?? count($o['values']))),
                    'sort' => $i,
                ];
                if ($option) {
                    $option->update($attrs);
                } else {
                    $option = $product->options()->create($attrs);
                }
                $keep[] = $option->id;

                $oldValues = $option->values->keyBy('id');
                $keepValues = [];
                foreach (array_values($o['values']) as $j => $v) {
                    $value = isset($v['id']) ? $oldValues->get((int) $v['id']) : null;
                    $vAttrs = [
                        'name' => trim($v['name']),
                        'image' => $this->resolveImage($product, $value?->image, $v),
                        'extra_price' => round((float) ($v['extra_price'] ?? 0), 2),
                        // الخيار الواحد (حجم مثلاً) ما يتكررش
                        'max_qty' => $single ? 1 : max(1, (int) ($v['max_qty'] ?? 1)),
                        'is_available' => (bool) ($v['is_available'] ?? true),
                        'sort' => $j,
                    ];
                    if ($value) {
                        $value->update($vAttrs);
                    } else {
                        $value = $option->values()->create($vAttrs);
                    }
                    $keepValues[] = $value->id;
                }
                $option->values()->whereNotIn('id', $keepValues)->delete();
            }

            $product->options()->whereNotIn('id', $keep)->get()->each->delete();
        });
    }
}
