<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** واجهات تطبيق المتجر */
class StorePanelController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    private function store(Request $request)
    {
        return $request->user()->store ?? abort(403, 'هذا الحساب غير مرتبط بمتجر.');
    }

    public function summary(Request $request): JsonResponse
    {
        $store = $this->store($request);

        return response()->json([
            'store' => [
                'id' => $store->id, 'name' => $store->name, 'is_open' => (bool) $store->is_open,
            ],
            'today' => [
                'orders'  => $store->orders()->whereDate('created_at', today())->count(),
                'sales'   => (float) $store->orders()->whereDate('created_at', today())
                    ->where('status', OrderStatus::Delivered->value)->sum('store_earning'),
                'pending' => $store->orders()->where('status', OrderStatus::Pending->value)->count(),
                'active'  => $store->orders()->active()->count(),
            ],
            'low_stock' => $store->products()
                ->where('track_stock', true)
                ->whereNotNull('low_stock_alert')
                ->whereColumn('stock_quantity', '<=', 'low_stock_alert')
                ->count(),
        ]);
    }

    public function toggleOpen(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $store->update(['is_open' => ! $store->is_open]);

        return response()->json(['is_open' => (bool) $store->fresh()->is_open]);
    }

    public function orders(Request $request): JsonResponse
    {
        $store = $this->store($request);

        $orders = $store->orders()
            ->when($request->status === 'active', fn ($q) => $q->active())
            ->when($request->status && $request->status !== 'active',
                fn ($q) => $q->where('status', $request->status))
            ->with(['items', 'customer', 'driver'])
            ->latest()
            ->paginate(20);

        return OrderResource::collection($orders)->response();
    }

    public function updateOrderStatus(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->store_id === $this->store($request)->id, 403);

        $data = $request->validate([
            'status'            => ['required', 'in:preparing,ready,cancelled'],
            'prep_time_minutes' => ['nullable', 'integer', 'between:5,180'],
            'reason'            => ['nullable', 'string', 'max:200'],
        ]);

        $order = $this->orders->transition(
            $order,
            OrderStatus::from($data['status']),
            $request->user(),
            $data
        );

        return response()->json(['data' => new OrderResource($order->load(['items', 'customer']))]);
    }

    // ===== أقسام القائمة =====

    public function sections(Request $request): JsonResponse
    {
        $sections = $this->store($request)->sections()
            ->withCount('products')
            ->orderBy('sort')
            ->get()
            ->map(fn ($s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'sort'           => $s->sort,
                'is_active'      => (bool) $s->is_active,
                'products_count' => $s->products_count,
            ]);

        return response()->json(['data' => $sections]);
    }

    public function storeSection(Request $request): JsonResponse
    {
        $store = $this->store($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'sort' => ['nullable', 'integer'],
        ]);

        $section = $store->sections()->create([
            'name' => $data['name'],
            'sort' => $data['sort'] ?? ($store->sections()->max('sort') + 1),
        ]);

        return response()->json(['data' => $section], 201);
    }

    public function updateSection(Request $request, int $id): JsonResponse
    {
        $section = $this->store($request)->sections()->findOrFail($id);

        $section->update($request->validate([
            'name'      => ['nullable', 'string', 'max:60'],
            'sort'      => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return response()->json(['data' => $section->fresh()]);
    }

    public function destroySection(Request $request, int $id): JsonResponse
    {
        $section = $this->store($request)->sections()->findOrFail($id);

        // المنتجات ما تنحذفش — تنفك من القسم فقط
        $section->products()->update(['menu_section_id' => null]);
        $section->delete();

        return response()->json(['message' => 'تم حذف القسم، ومنتجاته صارت بدون قسم.']);
    }

    /** ترتيب الأقسام دفعة وحدة */
    public function reorderSections(Request $request): JsonResponse
    {
        $store = $this->store($request);

        $data = $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($data['ids'] as $index => $id) {
            $store->sections()->where('id', $id)->update(['sort' => $index]);
        }

        return response()->json(['message' => 'تم الترتيب']);
    }

    public function products(Request $request): JsonResponse
    {
        $products = $this->store($request)->products()->with('options.values')->orderBy('sort')->get();

        return response()->json(['data' => ProductResource::collection($products)]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $store = $this->store($request);

        $data = $request->validate([
            'name'            => ['required', 'string', 'max:120'],
            'description'     => ['nullable', 'string', 'max:500'],
            'price'           => ['required', 'numeric', 'min:0'],
            'discount_price'  => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'menu_section_id' => ['nullable', 'integer'],
            'is_available'    => ['nullable', 'boolean'],
            'track_stock'     => ['nullable', 'boolean'],
            'stock_quantity'  => ['nullable', 'integer', 'min:0'],
            'max_per_order'   => ['nullable', 'integer', 'min:1'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'image'           => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store("stores/{$store->id}", 'public');
        }

        $product = $store->products()->create($data);

        return response()->json(['data' => new ProductResource($product)], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->store_id === $this->store($request)->id, 403);

        $data = $request->validate([
            'name'            => ['nullable', 'string', 'max:120'],
            'description'     => ['nullable', 'string', 'max:500'],
            'price'           => ['nullable', 'numeric', 'min:0'],
            'discount_price'  => ['nullable', 'numeric', 'min:0'],
            'is_available'    => ['nullable', 'boolean'],
            'track_stock'     => ['nullable', 'boolean'],
            'stock_quantity'  => ['nullable', 'integer', 'min:0'],
            'max_per_order'   => ['nullable', 'integer', 'min:1'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'image'           => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store("stores/{$product->store_id}", 'public');
        }

        $product->update(array_filter($data, fn ($v) => ! is_null($v)));

        return response()->json(['data' => new ProductResource($product->fresh())]);
    }

    public function destroyProduct(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->store_id === $this->store($request)->id, 403);
        $product->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
