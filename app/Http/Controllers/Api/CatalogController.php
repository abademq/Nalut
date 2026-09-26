<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\StoreType;
use App\Services\GeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function types(): JsonResponse
    {
        return response()->json([
            'data' => StoreType::where('is_active', true)
                ->when(request('section'), fn ($q, $section) => $q->where('app_section_id', $section))
                ->orderBy('sort')->get(['id', 'name', 'icon', 'app_section_id']),
        ]);
    }

    public function stores(Request $request): JsonResponse
    {
        $request->validate([
            'lat'        => ['nullable', 'numeric'],
            'lng'        => ['nullable', 'numeric'],
            'type'       => ['nullable', 'integer'],
            // قسم التطبيق: مطاعم، متاجر إلكترونية، ...
            'section'    => ['nullable', 'integer'],
            'q'          => ['nullable', 'string', 'max:60'],
            'sort'       => ['nullable', 'in:nearest,rating,popular,name'],
            'min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'open_only'  => ['nullable', 'boolean'],
        ]);

        $stores = Store::visible()
            ->with('type')
            ->when($request->type, fn ($q, $type) => $q->where('store_type_id', $type))
            ->when($request->section, fn ($q, $section) => $q->whereHas('type', fn ($t) => $t->where('app_section_id', $section)))
            ->when($request->q, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($request->filled('min_rating'),
                fn ($q) => $q->where('rating_avg', '>=', (float) $request->min_rating))
            ->get();

        // المسافة بالخط المستقيم هنا كفاية — الترتيب مش الفوترة
        if ($request->filled(['lat', 'lng'])) {
            $stores->each(function ($store) use ($request) {
                $store->distance_km = ($store->lat && $store->lng)
                    ? GeoService::airDistanceKm(
                        (float) $request->lat,
                        (float) $request->lng,
                        $store->lat,
                        $store->lng
                    )
                    : null;
            });
        }

        $sort = $request->sort ?? ($request->filled(['lat', 'lng']) ? 'nearest' : 'rating');

        $stores = match ($sort) {
            'nearest' => $stores->sortBy(fn ($s) => $s->distance_km ?? 9999),
            'rating'  => $stores->sortByDesc(fn ($s) => [$s->rating_avg, $s->rating_count]),
            'popular' => $stores->sortByDesc('rating_count'),
            default   => $stores->sortBy('name'),
        };

        // المفتوح دائماً فوق المغلق مهما كان الفرز
        $stores = $stores
            ->sortByDesc(fn ($s) => $s->isAcceptingOrders() ? 1 : 0)
            ->values();

        return response()->json(['data' => StoreResource::collection($stores)]);
    }

    public function show(Store $store): JsonResponse
    {
        abort_unless($store->is_active, 404);

        $store->load([
            'type',
            'sections',
            'products' => fn ($q) => $q->orderBy('sort')->with('options.values'),
        ]);

        return response()->json([
            'data' => (new StoreResource($store))->additional([]),
            // شريط عروض المتجر + السلات الجاهزة
            'announcements' => \App\Models\Announcement::live()->where('store_id', $store->id)->get()->map->toApp()->values(),
            'ready_carts'   => \App\Models\ReadyCart::where('store_id', $store->id)->where('is_active', true)->orderBy('sort')->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'description' => $c->description,
                    'image' => $c->image ? asset('storage/'.$c->image) : null,
                    'price' => $c->price(), 'items_count' => count($c->lines()),
                ])->values(),
        ]);
    }
}
