<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StoreResource;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\PointsTransaction;
use App\Models\Product;
use App\Models\ReadyCart;
use App\Models\Store;
use App\Services\CartBuilder;
use App\Services\PointsService;
use App\Services\SubstitutionService;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** المفضلة، إعادة الطلب، السلات الجاهزة، النقاط، والرد على الأصناف الناقصة */
class CustomerExtrasController extends Controller
{
    // ===== المفضلة =====

    public function favorites(Request $request): JsonResponse
    {
        $favs = Favorite::where('user_id', $request->user()->id)->latest('id')->get();

        $stores = Store::visible()->with('type')
            ->whereIn('id', $favs->where('favoritable_type', Store::class)->pluck('favoritable_id'))->get();

        $products = Product::with('store')
            ->whereIn('id', $favs->where('favoritable_type', Product::class)->pluck('favoritable_id'))
            ->whereHas('store', fn ($q) => $q->where('is_active', true))
            ->get();

        return response()->json([
            'stores'   => StoreResource::collection($stores),
            'products' => $products->map(fn (Product $p) => [
                'product' => new ProductResource($p),
                'store'   => ['id' => $p->store->id, 'name' => $p->store->name],
            ]),
        ]);
    }

    public function toggleFavorite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:store,product'],
            'id'   => ['required', 'integer'],
        ]);

        $class = $data['type'] === 'store' ? Store::class : Product::class;
        abort_unless($class::whereKey($data['id'])->exists(), 404);

        $attrs = ['user_id' => $request->user()->id, 'favoritable_type' => $class, 'favoritable_id' => $data['id']];
        $existing = Favorite::where($attrs)->first();

        if ($existing) {
            $existing->delete();
        } else {
            Favorite::create($attrs + ['created_at' => now()]);
        }

        return response()->json(['favorited' => ! $existing]);
    }

    // ===== إعادة الطلب والسلات الجاهزة =====

    public function reorder(Request $request, Order $order, CartBuilder $carts): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $lines = $order->items()->where('is_unavailable', false)->whereNotNull('product_id')->get()
            ->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => $i->quantity, 'note' => $i->note])->all();

        return response()->json($carts->build($order->store, $lines));
    }

    // ===== سلات الزبون المحفوظة =====

    public function savedCarts(Request $request): JsonResponse
    {
        $carts = \App\Models\SavedCart::with('store')
            ->where('user_id', $request->user()->id)
            ->whereHas('store', fn ($q) => $q->where('is_active', true))
            ->latest('updated_at')->get();

        return response()->json([
            'enabled' => (bool) Options::get('carts.saved_enabled'),
            'max'     => (int) Options::get('carts.saved_max'),
            'data'    => $carts->map->toApp()->values(),
        ]);
    }

    public function saveCart(Request $request): JsonResponse
    {
        abort_unless((bool) Options::get('carts.saved_enabled'), 403, 'حفظ السلات موقف حالياً.');

        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:60'],
            'store_id'             => ['required', 'integer', 'exists:stores,id'],
            'items'                => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id'   => ['required', 'integer'],
            'items.*.quantity'     => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.note'         => ['nullable', 'string', 'max:200'],
        ], [], ['name' => 'اسم السلة']);

        $user = $request->user();
        $max = (int) Options::get('carts.saved_max');

        if (\App\Models\SavedCart::where('user_id', $user->id)->count() >= $max) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => "وصلت للحد ($max سلات). امسح سلة قديمة وعاود.",
            ]);
        }

        // الأصناف لازم تكون من نفس المتجر
        $valid = Product::where('store_id', $data['store_id'])
            ->whereIn('id', array_column($data['items'], 'product_id'))->pluck('id')->all();
        $items = collect($data['items'])->filter(fn ($i) => in_array((int) $i['product_id'], $valid, true))
            ->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity'], 'note' => $i['note'] ?? null])
            ->values()->all();

        abort_if($items === [], 422, 'الأصناف مش من المتجر هذا.');

        $cart = \App\Models\SavedCart::create([
            'user_id' => $user->id, 'store_id' => $data['store_id'], 'name' => $data['name'], 'items' => $items,
        ]);

        return response()->json(['message' => 'تم حفظ السلة', 'data' => $cart->load('store')->toApp()], 201);
    }

    /** يرجع أصناف السلة بالأسعار والتوفّر الحالي — التطبيق يعبّي بيها السلة */
    public function savedCart(Request $request, \App\Models\SavedCart $savedCart, CartBuilder $carts): JsonResponse
    {
        abort_unless($savedCart->user_id === $request->user()->id, 404);
        $savedCart->touch();

        return response()->json($carts->build($savedCart->store, $savedCart->lines()));
    }

    public function deleteSavedCart(Request $request, \App\Models\SavedCart $savedCart): JsonResponse
    {
        abort_unless($savedCart->user_id === $request->user()->id, 404);
        $savedCart->delete();

        return response()->json(['message' => 'تم مسح السلة']);
    }

    public function readyCart(ReadyCart $readyCart, CartBuilder $carts): JsonResponse
    {
        abort_unless($readyCart->is_active && $readyCart->store?->is_active, 404);

        return response()->json($carts->build($readyCart->store, $readyCart->lines()));
    }

    // ===== النقاط =====

    public function points(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled'      => PointsService::enabled(),
            'balance'      => (int) $user->points_balance,
            'value'        => PointsService::money((int) $user->points_balance),
            'point_value'  => PointsService::value(),
            'redeem_mode'  => Options::get('points.redeem_mode'),
            'min_redeem'   => (int) Options::get('points.min_redeem'),
            'earn_mode'    => Options::get('points.earn_mode'),
            'earn_rate'    => (float) Options::get('points.earn_rate'),
            'history'      => PointsTransaction::where('user_id', $user->id)->latest('id')->limit(50)->get()
                ->map(fn ($t) => [
                    'points' => $t->points, 'type' => $t->type,
                    'label'  => PointsTransaction::TYPES[$t->type] ?? $t->type,
                    'note'   => $t->note, 'at' => $t->created_at,
                ]),
        ]);
    }

    public function convertPoints(Request $request, PointsService $points): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'min:1']]);

        $amount = $points->convertToWallet($request->user(), (int) $data['points']);

        return response()->json([
            'message' => 'تحوّلت '.$data['points'].' نقطة لـ '.number_format($amount, 2).' د.ل في محفظتك',
            'amount'  => $amount,
            'balance' => (int) $request->user()->fresh()->points_balance,
        ]);
    }

    // ===== أصناف مش متوفرة =====

    public function substitution(Request $request, Order $order, SubstitutionService $subs): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $data = $request->validate(['action' => ['required', 'in:continue,edit,cancel']]);

        if ($data['action'] === 'continue') {
            return response()->json(['data' => new OrderResource($subs->continueWithout($order, $request->user()))]);
        }

        if ($data['action'] === 'edit') {
            // الطلب ينلغى وترجع السلة بالأصناف المتوفرة للتعديل
            return response()->json(['cart' => $subs->editOrder($order, $request->user())]);
        }

        $order->update(['awaiting_customer_at' => null, 'substitution_deadline_at' => null]);
        $order = app(\App\Services\OrderService::class)->transition($order, \App\Enums\OrderStatus::Cancelled,
            $request->user(), ['reason' => 'ألغاه الزبون (أصناف مش متوفرة)', 'force' => true]);

        return response()->json(['data' => new OrderResource($order)]);
    }
}
