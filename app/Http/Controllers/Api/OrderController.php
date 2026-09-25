<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\DriverLocationService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** واجهات تطبيق الزبون */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('customer_id', $request->user()->id)
            ->with(['store', 'items'])
            ->latest()
            ->paginate(15);

        return OrderResource::collection($orders)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id'                 => ['required', 'exists:stores,id'],
            'address_id'               => ['required', 'integer'],
            'payment_method'           => ['nullable', 'in:cash,wallet,card'],
            'use_wallet'               => ['nullable', 'boolean'],
            'notes'                    => ['nullable', 'string', 'max:500'],
            'coupon_code'              => ['nullable', 'string', 'max:30'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.quantity'         => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.note'             => ['nullable', 'string', 'max:200'],
            'items.*.option_value_ids' => ['nullable', 'array'],
        ]);

        $order = $this->orders->create($request->user(), $data);

        return response()->json([
            'data' => new OrderResource($order->load(['store', 'items'])),
        ], 201);
    }

    /** تسعيرة قبل التأكيد — ما تنشئش طلب */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id'           => ['required', 'exists:stores,id'],
            'address_id'         => ['required', 'integer'],
            'payment_method'     => ['nullable', 'in:cash,wallet,card'],
            'use_wallet'         => ['nullable', 'boolean'],
            'coupon_code'        => ['nullable', 'string', 'max:30'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.note'       => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->orders->quote($request->user(), $data));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        return response()->json([
            'data' => new OrderResource(
                $order->load(['store', 'items', 'driver.driverProfile', 'statusLogs', 'rating'])
            ),
        ]);
    }

    /** تتبّع مباشر — موقع السائق من Redis */
    public function track(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        return response()->json([
            'status'          => $order->status->value,
            'status_label'    => $order->status->label(),
            'driver_location' => $order->driver_id ? DriverLocationService::get($order->driver_id) : null,
            'store_location'  => ['lat' => $order->store->lat, 'lng' => $order->store->lng],
            'destination'     => ['lat' => $order->address_lat, 'lng' => $order->address_lng],
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        // الإلغاء متاح قبل ما المتجر يبدا التحضير فقط — والإدارة تقدر تقفله نهائياً
        abort_if(
            \App\Support\Options::get('orders.customer_cancel_until') === 'never',
            422,
            \App\Support\Texts::get('msg.cancel_disabled')
        );

        abort_unless(
            $order->status === OrderStatus::Pending,
            422,
            \App\Support\Texts::get('msg.cancel_too_late')
        );

        $order = $this->orders->transition($order, OrderStatus::Cancelled, $request->user(), [
            'reason' => $request->input('reason') ?: \App\Support\Texts::get('msg.cancelled_by_customer'),
        ]);

        return response()->json(['data' => new OrderResource($order)]);
    }

    public function rate(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);
        abort_unless($order->status === OrderStatus::Delivered, 422, 'التقييم بعد التسليم فقط.');
        abort_if($order->rating()->exists(), 422, 'قيّمت هذا الطلب من قبل.');

        $data = $request->validate([
            'store_rating'  => ['nullable', 'integer', 'between:1,5'],
            'driver_rating' => ['nullable', 'integer', 'between:1,5'],
            'comment'       => ['nullable', 'string', 'max:400'],
        ]);

        $order->rating()->create($data + ['user_id' => $request->user()->id]);

        $this->applyRating($order, $data);

        return response()->json(['message' => 'شكراً على تقييمك.']);
    }

    private function applyRating(Order $order, array $data): void
    {
        if (! empty($data['store_rating']) && $store = $order->store) {
            $count = $store->rating_count + 1;
            $store->update([
                'rating_avg'   => round((($store->rating_avg * $store->rating_count) + $data['store_rating']) / $count, 2),
                'rating_count' => $count,
            ]);
        }

        if (! empty($data['driver_rating']) && $profile = $order->driver?->driverProfile) {
            $count = $profile->rating_count + 1;
            $profile->update([
                'rating_avg'   => round((($profile->rating_avg * $profile->rating_count) + $data['driver_rating']) / $count, 2),
                'rating_count' => $count,
            ]);
        }
    }
}
