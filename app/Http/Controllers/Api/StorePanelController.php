<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
use App\Services\OrderService;
use App\Support\LocalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
        [$from, $to] = LocalDay::range();

        return response()->json([
            'store' => [
                'id' => $store->id, 'name' => $store->name, 'is_open' => (bool) $store->is_open,
            ],
            'today' => [
                'orders'  => $store->orders()->whereBetween('created_at', [$from, $to])->count(),
                'sales'   => (float) $store->orders()->whereBetween('created_at', [$from, $to])
                    ->where('status', OrderStatus::Delivered->value)->sum('store_earning'),
                'pending' => $store->orders()->where('status', OrderStatus::Pending->value)
                    ->where(fn ($q) => $q->where('payment_method', '!=', 'card')->orWhere('is_paid', true))
                    ->count(),
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

        $request->validate([
            'status' => ['nullable', 'string'],
            'date'   => ['nullable', 'date_format:Y-m-d'],
            'q'      => ['nullable', 'string', 'max:20'],
        ]);

        $final = [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::Failed->value];

        $orders = $store->orders()
            // طلبات البطاقة اللي دفعها ما تأكدش ما تظهرش للمتجر
            ->where(fn ($q) => $q->where('payment_method', '!=', 'card')->orWhere('is_paid', true))
            ->when($request->status === 'active', fn ($q) => $q->active())
            // السجل: كل الطلبات المنتهية (مكتملة + ملغية + فاشلة)
            ->when($request->status === 'history', fn ($q) => $q->whereIn('status', $final))
            ->when($request->status && ! in_array($request->status, ['active', 'history'], true),
                fn ($q) => $q->where('status', $request->status))
            ->when($request->date, function ($q) use ($request) {
                [$from, $to] = LocalDay::range($request->date);
                $q->whereBetween('created_at', [$from, $to]);
            })
            ->when($request->q, fn ($q) => $q->where('code', 'like', '%'.$request->q.'%'))
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
            'prep_time_minutes' => ['nullable', 'integer', 'between:1,600'],
            'reason'            => ['nullable', 'string', 'max:200'],
        ]);

        // السائق خذا الطلب قبل ما المتجر يضغط «جاهز» (انقضت مدة التحضير):
        // الحالة تضل «أُسند لسائق»، لكن نسجّلو إن الأكل جاهز ونبلّغو السائق
        if ($data['status'] === 'ready' && $order->status === OrderStatus::Assigned) {
            $order = $this->orders->markReadyWhileAssigned($order, $request->user());
        } else {
            $order = $this->orders->transition(
                $order,
                OrderStatus::from($data['status']),
                $request->user(),
                $data
            );
        }

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
            'menu_section_id' => ['nullable', 'integer', $this->ownSectionRule($store->id)],
            'is_available'    => ['nullable', 'boolean'],
            'track_stock'     => ['nullable', 'boolean'],
            'stock_quantity'  => ['nullable', 'integer', 'min:0'],
            'max_per_order'   => ['nullable', 'integer', 'min:1'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            ...self::IMAGE_RULES,
        ]);

        [$data['images']] = $this->resolveImages($request, [], $store->id);
        unset($data['image'], $data['remove_images'], $data['main_image']);

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
            // كان ناقص من القائمة — فتغيير القسم ينحفظ بدون ما يتغيّر فعلياً
            'menu_section_id' => ['nullable', 'integer', $this->ownSectionRule($product->store_id)],
            'is_available'    => ['nullable', 'boolean'],
            'track_stock'     => ['nullable', 'boolean'],
            'stock_quantity'  => ['nullable', 'integer', 'min:0'],
            'max_per_order'   => ['nullable', 'integer', 'min:1'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            ...self::IMAGE_RULES,
        ]);

        [$images, $removed] = $this->resolveImages($request, (array) $product->images, $product->store_id);
        unset($data['image'], $data['images'], $data['remove_images'], $data['main_image']);

        $product->fill(array_filter($data, fn ($v) => ! is_null($v)));

        // القسم لازم يقبل null: «بدون قسم» = نفك المنتج من قسمه
        if ($request->exists('menu_section_id')) {
            $product->menu_section_id = $data['menu_section_id'] ?? null;
        }

        $product->images = $images;
        $product->save();

        // نمسحو الملفات بعد ما ينحفظ المنتج — لو الحفظ فشل ما نخسروش الصور
        Storage::disk('public')->delete($removed);

        return response()->json(['data' => new ProductResource($product->fresh())]);
    }

    public function destroyProduct(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->store_id === $this->store($request)->id, 403);
        $product->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }

    /** قواعد الصور — صورة واحدة (image) للتوافق مع النسخ القديمة، أو عدة صور (images[]) */
    private const IMAGE_RULES = [
        'image'           => ['nullable', 'image', 'max:5120'],
        'images'          => ['nullable', 'array', 'max:'.Product::MAX_IMAGES],
        'images.*'        => ['image', 'max:5120'],
        'remove_images'   => ['nullable', 'array'],
        'remove_images.*' => ['string'],
        'main_image'      => ['nullable', 'string'],
    ];

    /**
     * يحسب قائمة الصور الجديدة: الحالية − المحذوفة + المرفوعة، والرئيسية أولاً.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} [الصور, الملفات اللي تنمسح]
     */
    private function resolveImages(Request $request, array $current, int $storeId): array
    {
        $remove = array_map([$this, 'toPath'], (array) $request->input('remove_images', []));
        $removed = array_values(array_intersect($current, $remove));
        $images = array_values(array_diff($current, $removed));

        $uploads = array_merge(
            $request->hasFile('image') ? [$request->file('image')] : [],
            (array) $request->file('images', []),
        );

        if (count($images) + count($uploads) > Product::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => 'أقصى عدد '.Product::MAX_IMAGES.' صور للمنتج.',
            ]);
        }

        foreach ($uploads as $file) {
            $images[] = $file->store("stores/{$storeId}", 'public');
        }

        if ($main = $request->input('main_image')) {
            $main = $this->toPath($main);
            if (in_array($main, $images, true)) {
                $images = [$main, ...array_values(array_diff($images, [$main]))];
            }
        }

        return [$images, $removed];
    }

    /** يقبل رابط كامل أو مسار — ويرجّع المسار داخل قرص public */
    private function toPath(string $value): string
    {
        $pos = strpos($value, '/storage/');

        return $pos === false ? ltrim($value, '/') : substr($value, $pos + strlen('/storage/'));
    }

    /**
     * تقرير يومي للمتجر: الطلبات والمبيعات وأكثر المنتجات مبيعاً.
     * المبيعات = الطلبات المسلّمة فقط. اليوم بتوقيت ليبيا.
     */
    public function dailyReport(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        [$from, $to, $day] = LocalDay::range($request->date);

        $orders = $store->orders()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $delivered = $orders->filter(fn ($o) => $o->status === OrderStatus::Delivered);
        $count = fn (OrderStatus $s) => $orders->filter(fn ($o) => $o->status === $s)->count();
        $money = fn ($c, string $col) => round((float) $c->sum($col), 2);

        $byPayment = $delivered
            ->groupBy(fn ($o) => $o->payment_method->value)
            ->map(fn ($g) => [
                'method' => $g->first()->payment_method->value,
                'label'  => $g->first()->payment_method->label(),
                'orders' => $g->count(),
                'amount' => $money($g, 'subtotal'),
            ])
            ->values();

        $topProducts = OrderItem::query()
            ->whereIn('order_id', $delivered->pluck('id'))
            ->selectRaw('name, SUM(quantity) as qty, SUM(line_total) as amount')
            ->groupBy('name')
            ->orderByDesc('qty')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'name'     => $r->name,
                'quantity' => (int) $r->qty,
                'amount'   => round((float) $r->amount, 2),
            ]);

        return response()->json([
            'date'         => $day->toDateString(),
            'store'        => $store->name,
            'generated_at' => now(LocalDay::timezone())->format('Y-m-d H:i'),
            'orders'       => [
                'total'     => $orders->count(),
                'delivered' => $delivered->count(),
                'cancelled' => $count(OrderStatus::Cancelled),
                'failed'    => $count(OrderStatus::Failed),
                'active'    => $orders->filter(fn ($o) => ! $o->status->isFinal())->count(),
            ],
            'sales'        => [
                // قيمة الأصناف قبل التوصيل
                'gross'      => $money($delivered, 'subtotal'),
                'discount'   => $money($delivered, 'discount'),
                'commission' => $money($delivered, 'commission_amount'),
                // صافي المتجر بعد عمولة المنصة
                'net'        => $money($delivered, 'store_earning'),
                'average'    => $delivered->count() ? round((float) $delivered->avg('subtotal'), 2) : 0.0,
            ],
            'by_payment'   => $byPayment,
            'top_products' => $topProducts,
            'list'         => $orders->map(fn ($o) => [
                'code'         => $o->code,
                'time'         => LocalDay::toLocal($o->created_at)?->format('H:i'),
                'status'       => $o->status->value,
                'status_label' => $o->status->label(),
                'payment'      => $o->payment_method->label(),
                'subtotal'     => (float) $o->subtotal,
            ])->values(),
        ]);
    }

    /** القسم لازم يكون تابع لنفس المتجر */
    private function ownSectionRule(int $storeId): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('menu_sections', 'id')->where('store_id', $storeId);
    }
}
