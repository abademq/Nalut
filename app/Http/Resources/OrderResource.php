<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use App\Services\DeliveryIssueService;
use App\Support\Options;
use App\Support\OrderMoney;
use App\Support\Texts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->base($request);
        $viewer = OrderMoney::viewer($request);

        if (! in_array($viewer, ['store', 'driver'], true)) {
            // الطلب خلص (وصل أو انلغى): رقم السائق ما يقعدش عند الزبون
            if ($viewer === 'customer' && $this->status->isFinal() && is_array($data['driver'] ?? null)) {
                $data['driver']['phone'] = null;
            }

            return $data;
        }

        // ===== اللي يشوفه المتجر والسائق — من «ما يظهر للمتجر والسائق» =====
        $can = fn (string $w) => OrderMoney::can($viewer, $w);
        $show = [];
        foreach (Options::definitions() as $key => $def) {
            if (str_starts_with($key, "show.$viewer.")) {
                $show[substr($key, strlen("show.$viewer."))] = (bool) Options::get($key);
            }
        }
        $data['show'] = $show;
        $data['money'] = OrderMoney::forApi($this->resource, $viewer);

        $customer = $data['customer'] ?? null;
        if (is_array($customer)) {
            if (! $can('customer_name')) {
                $customer['name'] = 'زبون';
            }
            if (! $can('customer_phone')) {
                $customer['phone'] = null;
            }
            $data['customer'] = $customer;
        }

        if (! $can('item_prices') || ($viewer === 'driver' && ! $can('items'))) {
            $data['subtotal'] = null;
            if (isset($data['items']) && ! $data['items'] instanceof MissingValue) {
                $data['items'] = collect($data['items'])
                    ->map(fn ($i) => array_merge($i, ['unit_price' => null, 'line_total' => null]))->all();
            }
        }

        if (! $can('order_total')) {
            foreach (['delivery_fee', 'discount', 'points_discount', 'total', 'wallet_paid'] as $k) {
                $data[$k] = null;
            }
            // المبلغ اللي يحصّله السائق ما يتخبّاش أبداً — واصل السائق يحتاجه
        }

        if ($viewer === 'store') {
            if (! $can('customer_address')) {
                $data['address'] = null;
            }
            if (! $can('driver') && is_array($data['driver'] ?? null)) {
                $data['driver'] = null;
            }
        } else {
            if (! $can('items')) {
                $data['items'] = [];
            }
            if (! $can('notes')) {
                $data['notes'] = null;
            }
            if (! $can('store_phone') && is_array($data['store'] ?? null)) {
                $data['store']['phone'] = null;
            }
            $data['driver_earning'] = $can('earning') ? (float) $this->driver_earning : null;
        }

        return $data;
    }

    private function base(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            // بلاغ سائق مفتوح = الحالة تضل، لكن الزبون والمتجر يشوفو «قيد مراجعة الإدارة»
            'status_label' => match (true) {
                (bool) $this->openIssue => Texts::get('status.under_review'),
                $this->awaiting_customer_at !== null => Texts::get('status.awaiting_customer'),
                $this->left_at_door_at !== null && $this->status->value === 'delivered' => Texts::get('status.left_at_door'),
                $this->resource->isPickup() && in_array($this->status->value, ['ready', 'delivered'], true) => Texts::get('status.'.$this->status->value.'_pickup'),
                default => $this->status->label(),
            },
            'under_review' => (bool) $this->openIssue,
            // المتجر علّم أصناف مش متوفرة — الطلب يستنى رد الزبون
            'awaiting_customer' => $this->awaiting_customer_at !== null,
            'substitution_deadline_at' => $this->substitution_deadline_at,
            // تفاصيل البلاغ ورابط الدعم — للسائق صاحب الطلب بس
            'issue' => $this->when(
                $this->openIssue && $request->user()?->id === $this->driver_id,
                fn () => [
                    'ticket' => $this->openIssue->ticket,
                    'reason' => $this->openIssue->reason_label,
                    'created_at' => $this->openIssue->created_at,
                    'support_url' => app(DeliveryIssueService::class)->supportUrl($this->openIssue),
                    // تذكرة الدعم المربوطة بالطلب (السائق يتابعها من التطبيق)
                    'support_ticket_id' => Ticket::where('user_id', $this->driver_id)->where('app', 'driver')
                        ->where('order_id', $this->id)->latest('id')->value('id'),
                ]
            ),
            'is_final' => $this->status->isFinal(),
            // بانتظار التسليم: السائق عند الباب — مؤقت الزبون، وبعده «اتركه أمام الباب» بصورة
            'handover' => $this->status->value === 'awaiting_handover' ? [
                'arrived_at' => $this->arrived_at?->toIso8601String(),
                'deadline_at' => $this->handover_deadline_at?->toIso8601String(),
                'seconds_left' => $this->resource->handoverSecondsLeft(),
                'wait_minutes' => (int) Options::get('delivery.handover_wait_minutes'),
                // للسائق: يقدر يتركه توّا؟ ولو لا، ليش (مثلاً طلب نقدي)
                'leave_at_door_blocker' => $this->resource->leaveAtDoorBlocker(),
            ] : null,
            'left_at_door' => $this->left_at_door_at !== null,
            'left_at_door_at' => $this->left_at_door_at?->toIso8601String(),
            // صورة الإثبات: للزبون والسائق والإدارة (المتجر ما يحتاجهاش)
            'door_photo_url' => $this->when(OrderMoney::viewer($request) !== 'store', fn () => $this->resource->doorPhotoUrl()),
            // delivery | pickup — الاستلام: بدون سائق، والزبون يورّي الرمز للمتجر
            'fulfillment' => $this->fulfillment ?? 'delivery',
            'is_pickup' => $this->resource->isPickup(),
            // الرمز للزبون صاحب الطلب بس — المتجر لازم ياخذه منه
            'pickup_code' => $this->when(
                $this->resource->isPickup() && $request->user()?->id === $this->customer_id && OrderMoney::viewer($request) !== 'store',
                fn () => $this->pickup_code
            ),
            'pickup_requires_code' => $this->resource->isPickup() && (bool) Options::get('pickup.require_code'),
            'payment_method' => $this->payment_method->value,
            'is_paid' => (bool) $this->is_paid,
            'wallet_paid' => (float) ($this->wallet_paid ?? 0),
            // المبلغ اللي يحصّله السائق نقداً فعلياً
            'cash_to_collect' => OrderMoney::cashToCollect($this->resource),
            'subtotal' => (float) $this->subtotal,
            'delivery_fee' => (float) $this->delivery_fee,
            'discount' => (float) $this->discount,
            'points_used' => (int) $this->points_used,
            'points_discount' => (float) $this->points_discount,
            'points_awarded' => (int) $this->points_awarded,
            'total' => (float) $this->total,
            'distance_km' => (float) $this->distance_km,
            'notes' => $this->notes,
            'address' => [
                'details' => $this->address_details,
                'landmark' => $this->address_landmark,
                'lat' => $this->address_lat,
                'lng' => $this->address_lng,
            ],
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone,
            ]),
            // التقييم مرة وحدة فقط
            'is_rated' => $this->rating()->exists(),
            'rating' => $this->whenLoaded('rating', fn () => $this->rating ? [
                'store_rating' => $this->rating->store_rating,
                'driver_rating' => $this->rating->driver_rating,
                'comment' => $this->rating->comment,
            ] : null),

            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'lat' => $this->store->lat,
                'lng' => $this->store->lng,
                'phone' => $this->store->phone,
                'rating_avg' => (float) $this->store->rating_avg,
                'rating_count' => (int) $this->store->rating_count,
            ]),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'phone' => $this->driver->phone,
                'rating_avg' => (float) ($this->driver->driverProfile?->rating_avg ?? 0),
                'rating_count' => (int) ($this->driver->driverProfile?->rating_count ?? 0),
                'delivered' => (int) ($this->driver->driverProfile?->delivered_count ?? 0),
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->name,
                'is_unavailable' => (bool) $i->is_unavailable,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'options' => $i->options,
                'options_text' => $i->optionsText(),
                // للتلوين: الزيادة (أخضر) والإزالة «بدون» (أحمر)
                'added_text' => $i->addedText(),
                'removed_text' => $i->removedText(),
                'line_total' => (float) $i->line_total,
                'note' => $i->note,
            ])),
            'timeline' => $this->whenLoaded('statusLogs', fn () => $this->statusLogs->map(fn ($l) => [
                'status' => $l->to_status,
                'at' => $l->created_at,
            ])),
            // مدة التحضير ووقت الجاهزية المتوقع — يظهرو للزبون وقت التحضير
            'prep_time_minutes' => $this->prep_time_minutes,
            'ready_eta' => $this->readyEta()?->toIso8601String(),
            // المتجر ضغط «جاهز» — حتى لو الحالة أُسند لسائق
            'ready_at' => $this->ready_at,
            'minutes_until_ready' => $this->status->value === 'preparing' ? $this->minutesUntilReady() : null,
            'created_at' => $this->created_at,
            'accepted_at' => $this->accepted_at,
            'delivered_at' => $this->delivered_at,
        ];
    }
}
