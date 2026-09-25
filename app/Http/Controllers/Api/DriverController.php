<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Services\DriverLocationService;
use App\Services\GeoService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** واجهات تطبيق السائق */
class DriverController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    private function profile(Request $request)
    {
        $profile = $request->user()->driverProfile ?? abort(403, 'ملف السائق غير موجود.');
        abort_unless($profile->is_approved, 403, 'حسابك لسه ما تمش اعتماده من الإدارة.');

        return $profile;
    }

    // ===== مناطق العمل =====

    public function zones(Request $request): JsonResponse
    {
        $profile  = $this->profile($request);
        $selected = $profile->zones()->pluck('delivery_zones.id')->all();

        $zones = DeliveryZone::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($z) => [
                'id'         => $z->id,
                'name'       => $z->name,
                'selected'   => in_array($z->id, $selected, true),
                'base_fee'   => (float) $z->base_fee,
                'fee_per_km' => (float) $z->fee_per_km,
            ]);

        return response()->json([
            'data'      => $zones,
            'all_zones' => empty($selected),
            'note'      => 'لو ما اخترت ولا منطقة، بتوصلك طلبات كل المناطق.',
        ]);
    }

    public function updateZones(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $data = $request->validate([
            'zone_ids'   => ['present', 'array'],
            'zone_ids.*' => ['integer', 'exists:delivery_zones,id'],
        ]);

        $profile->zones()->sync($data['zone_ids']);

        return response()->json([
            'message'  => 'تم تحديث مناطق عملك',
            'zone_ids' => $profile->zones()->pluck('delivery_zones.id'),
        ]);
    }

    // ===== الحالة والموقع =====

    public function setOnline(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate(['is_online' => ['required', 'boolean']]);

        $profile->update(['is_online' => $data['is_online']]);

        if (! $data['is_online']) {
            DriverLocationService::goOffline($request->user()->id);
        }

        return response()->json(['is_online' => (bool) $profile->fresh()->is_online]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $this->profile($request);

        $data = $request->validate([
            'lat'     => ['required', 'numeric', 'between:-90,90'],
            'lng'     => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric'],
        ]);

        DriverLocationService::put(
            $request->user()->id,
            (float) $data['lat'],
            (float) $data['lng'],
            isset($data['heading']) ? (float) $data['heading'] : null
        );

        return response()->json(['ok' => true]);
    }

    // ===== الطلبات =====

    /** الطلبات المتاحة — مفلترة حسب مناطق عمل السائق */
    public function available(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $zoneIds = $profile->zones()->pluck('delivery_zones.id');

        $orders = Order::whereNull('driver_id')
            ->whereIn('status', [OrderStatus::Ready->value, OrderStatus::Preparing->value])
            ->when($zoneIds->isNotEmpty(), fn ($q) => $q->whereIn('delivery_zone_id', $zoneIds))
            ->with(['store', 'items'])
            ->latest()
            ->limit(20)
            ->get()
            // يظهر بعد ما المتجر يقول «جاهز» أو ينقضي وقت التحضير
            ->filter(fn ($o) => $o->isAvailableForDrivers())
            // ونخفي اللي ما يقدرش ياخذه أصلاً بدل ما يضغط ويترفض
            ->filter(fn ($o) => $profile->canAccept($o))
            ->values();

        if ($request->filled(['lat', 'lng'])) {
            $orders = $orders->sortBy(fn ($o) => $o->store->lat
                ? GeoService::distanceKm((float) $request->lat, (float) $request->lng, $o->store->lat, $o->store->lng)
                : 999)->values();
        }

        return response()->json([
            'data'           => OrderResource::collection($orders),
            'filtered_zones' => $zoneIds,
            'capacity'       => [
                'max'       => $profile->max_active_orders,
                'active'    => $profile->activeOrders()->count(),
                'remaining' => $profile->remainingCapacity(),
                'mode'      => $profile->multi_order_mode,
                'mode_label'=> $profile->modeLabel(),
            ],
        ]);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $profile = $this->profile($request);

        abort_if($order->driver_id, 422, 'الطلب أُسند لسائق ثاني.');
        abort_unless(
            $order->isAvailableForDrivers(),
            422,
            'الطلب لسه مش جاهز — باقي '.$order->minutesUntilReady().' دقيقة.'
        );

        // السعة ومناطق العمل ووضع تعدد الطلبات
        if ($reason = $profile->refusalReason($order)) {
            abort(422, $reason);
        }

        $order = $this->orders->transition($order, OrderStatus::Assigned, $request->user(), [
            'driver_id' => $request->user()->id,
        ]);

        return response()->json(['data' => new OrderResource($order->load(['store', 'items', 'customer']))]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->profile($request);
        abort_unless($order->driver_id === $request->user()->id, 403);

        // البلاغ المفتوح يوقف الطلب لين الإدارة تقرر
        if ($order->openIssue()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'الطلب قيد مراجعة الإدارة — استنى قرارهم.',
            ]);
        }

        $data = $request->validate([
            'status' => ['required', 'in:picked_up,on_the_way,delivered,failed'],
            'reason' => ['nullable', 'string', 'max:200'],
            'lat'    => ['nullable', 'numeric'],
            'lng'    => ['nullable', 'numeric'],
        ]);

        $order = $this->orders->transition(
            $order,
            OrderStatus::from($data['status']),
            $request->user(),
            $data
        );

        return response()->json(['data' => new OrderResource($order)]);
    }

    /** أسباب تعذّر التسليم — من لوحة التحكم */
    public function failureReasons(Request $request): JsonResponse
    {
        $this->profile($request);

        return response()->json(['data' => \App\Models\FailureReason::where('is_active', true)
            ->orderBy('sort')->get(['id', 'label', 'hold_for_review', 'open_support'])]);
    }

    /** بلاغ تعذّر تسليم — يرجع التذكرة ورابط الدعم الفني */
    public function reportIssue(Request $request, Order $order, \App\Services\DeliveryIssueService $issues): JsonResponse
    {
        $this->profile($request);

        $data = $request->validate([
            'reason_id' => ['required', 'integer'],
            'note'      => ['nullable', 'string', 'max:300'],
            'lat'       => ['nullable', 'numeric', 'between:-90,90'],
            'lng'       => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $reason = \App\Models\FailureReason::where('is_active', true)->findOrFail($data['reason_id']);

        $issue = $issues->report(
            $order, $request->user(), $reason, $data['note'] ?? null,
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json([
            'ticket'       => $issue->ticket,
            'under_review' => $issue->action === 'review',
            'message'      => $issue->action === 'review'
                ? 'تم إرسال البلاغ — الطلب قيد مراجعة الإدارة'
                : 'تم تسجيل فشل التسليم',
            'support_url'  => $issues->supportUrl($issue),
            'data'         => new OrderResource($issue->order->fresh(['store', 'items', 'customer', 'openIssue'])),
        ], 201);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $this->profile($request);

        $orders = Order::where('driver_id', $request->user()->id)
            ->when($request->status === 'active', fn ($q) => $q->active())
            ->with(['store', 'items', 'customer', 'openIssue'])
            ->latest()
            ->paginate(20);

        return OrderResource::collection($orders)->response();
    }

    public function earnings(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $base = Order::where('driver_id', $request->user()->id)
            ->where('status', OrderStatus::Delivered->value);

        return response()->json([
            'today'      => (float) (clone $base)->whereDate('delivered_at', today())->sum('driver_earning'),
            'this_month' => (float) (clone $base)->whereMonth('delivered_at', now()->month)->sum('driver_earning'),
            'total'      => (float) (clone $base)->sum('driver_earning'),
            'delivered'  => $profile->delivered_count,
            // الرصيد السالب = كاش المنصة اللي عند السائق
            'balance'    => $request->user()->walletBalance(),
        ]);
    }
}
