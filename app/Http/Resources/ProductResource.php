<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'description'    => $this->description,
            'image'          => $this->image ? asset('storage/'.$this->image) : null,
            // كل الصور بالترتيب — الأولى هي الرئيسية
            'images'         => $this->imageUrls(),
            'price'          => (float) $this->price,
            'discount_price' => $this->discount_price ? (float) $this->discount_price : null,
            'is_available'   => (bool) $this->is_available,
            'is_favorite'    => \App\Support\FavoriteIds::has($request->user(), \App\Models\Product::class, $this->id),
            // المخزون: التطبيق يحدّ الكمية ويكتب «متبقي X» — والمتجر يحتاجهم في فورم التعديل
            'track_stock'     => (bool) $this->track_stock,
            'stock_quantity'  => $this->track_stock ? (int) $this->stock_quantity : null,
            'max_per_order'   => $this->max_per_order ? (int) $this->max_per_order : null,
            'low_stock_alert' => $this->low_stock_alert ? (int) $this->low_stock_alert : null,
            // خلص وتخفّى تلقائياً — يرجع لحاله لما المخزون يرجع
            'sold_out'        => $this->sold_out_at !== null,
            'section_id'     => $this->menu_section_id,
            'options'        => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'id'          => $o->id,
                'name'        => $o->name,
                'type'        => $o->type,
                'is_required' => (bool) $o->is_required,
                'max_choices' => $o->max_choices,
                'values'      => $o->values->map(fn ($v) => [
                    'id'           => $v->id,
                    'name'         => $v->name,
                    'extra_price'  => (float) $v->extra_price,
                    'is_available' => (bool) $v->is_available,
                ]),
            ])),
        ];
    }
}
