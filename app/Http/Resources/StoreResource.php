<?php

namespace App\Http\Resources;

use App\Models\Store;
use App\Support\FavoriteIds;
use App\Support\Pickup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo' => $this->logo ? asset('storage/'.$this->logo) : null,
            'cover' => $this->cover ? asset('storage/'.$this->cover) : null,
            'logo_blurhash' => $this->logo ? $this->logo_hash : null,
            'cover_blurhash' => $this->cover ? $this->cover_hash : null,
            'type' => $this->whenLoaded('type', fn () => $this->type?->name),
            'phone' => $this->phone,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'min_order' => (float) $this->min_order,
            'prep_time_minutes' => $this->prep_time_minutes,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'is_accepting' => $this->isAcceptingOrders(),
            'opens_at' => $this->opens_at ? substr($this->opens_at, 0, 5) : null,
            'closes_at' => $this->closes_at ? substr($this->closes_at, 0, 5) : null,
            'status_text' => $this->statusText(),
            'closed_message' => $this->isAcceptingOrders() ? null : $this->closedMessage(),
            'is_favorite' => FavoriteIds::has($request->user(), Store::class, $this->id),
            'distance_km' => $this->when(isset($this->distance_km), fn () => $this->distance_km),
            // القسم الموقوف يظهر برمادي ومكتوب تحته متى يتوفر
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map->toApp()->values()),
            // الاستلام من المطعم متاح في المتجر هذا؟ (يحتاج دفع إلكتروني)
            'pickup_available' => Pickup::availableAt($this->resource),
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
