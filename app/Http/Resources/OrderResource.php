<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'status'         => $this->status->value,
            // بلاغ سائق مفتوح = الحالة تضل، لكن الزبون والمتجر يشوفو «قيد مراجعة الإدارة»
            'status_label'   => $this->openIssue ? 'قيد مراجعة الإدارة' : $this->status->label(),
            'under_review'   => (bool) $this->openIssue,
            // تفاصيل البلاغ ورابط الدعم — للسائق صاحب الطلب بس
            'issue'          => $this->when(
                $this->openIssue && $request->user()?->id === $this->driver_id,
                fn () => [
                    'ticket'      => $this->openIssue->ticket,
                    'reason'      => $this->openIssue->reason_label,
                    'created_at'  => $this->openIssue->created_at,
                    'support_url' => app(\App\Services\DeliveryIssueService::class)->supportUrl($this->openIssue),
                ]
            ),
            'is_final'       => $this->status->isFinal(),
            'payment_method' => $this->payment_method->value,
            'is_paid'        => (bool) $this->is_paid,
            'wallet_paid'    => (float) ($this->wallet_paid ?? 0),
            // المبلغ اللي يحصّله السائق نقداً فعلياً
            'cash_to_collect' => $this->payment_method->value === 'cash'
                ? max(0, round((float) $this->total - (float) ($this->wallet_paid ?? 0), 2))
                : 0.0,
            'subtotal'       => (float) $this->subtotal,
            'delivery_fee'   => (float) $this->delivery_fee,
            'discount'       => (float) $this->discount,
            'total'          => (float) $this->total,
            'distance_km'    => (float) $this->distance_km,
            'notes'          => $this->notes,
            'address'        => [
                'details'  => $this->address_details,
                'landmark' => $this->address_landmark,
                'lat'      => $this->address_lat,
                'lng'      => $this->address_lng,
            ],
            'customer'       => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone,
            ]),
            // التقييم مرة وحدة فقط
            'is_rated'       => $this->rating()->exists(),
            'rating'         => $this->whenLoaded('rating', fn () => $this->rating ? [
                'store_rating'  => $this->rating->store_rating,
                'driver_rating' => $this->rating->driver_rating,
                'comment'       => $this->rating->comment,
            ] : null),

            'store'          => $this->whenLoaded('store', fn () => [
                'id'   => $this->store->id,
                'name' => $this->store->name,
                'lat'  => $this->store->lat,
                'lng'  => $this->store->lng,
                'phone' => $this->store->phone,
                'rating_avg'   => (float) $this->store->rating_avg,
                'rating_count' => (int) $this->store->rating_count,
            ]),
            'driver'         => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id'    => $this->driver->id,
                'name'  => $this->driver->name,
                'phone' => $this->driver->phone,
                'rating_avg'   => (float) ($this->driver->driverProfile?->rating_avg ?? 0),
                'rating_count' => (int) ($this->driver->driverProfile?->rating_count ?? 0),
                'delivered'    => (int) ($this->driver->driverProfile?->delivered_count ?? 0),
            ] : null),
            'items'          => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id'         => $i->id,
                'name'       => $i->name,
                'quantity'   => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'options'    => $i->options,
                'line_total' => (float) $i->line_total,
                'note'       => $i->note,
            ])),
            'timeline'       => $this->whenLoaded('statusLogs', fn () => $this->statusLogs->map(fn ($l) => [
                'status' => $l->to_status,
                'at'     => $l->created_at,
            ])),
            // مدة التحضير ووقت الجاهزية المتوقع — يظهرو للزبون وقت التحضير
            'prep_time_minutes'   => $this->prep_time_minutes,
            'ready_eta'           => $this->readyEta()?->toIso8601String(),
            // المتجر ضغط «جاهز» — حتى لو الحالة أُسند لسائق
            'ready_at'            => $this->ready_at,
            'minutes_until_ready' => $this->status->value === 'preparing' ? $this->minutesUntilReady() : null,
            'created_at'     => $this->created_at,
            'accepted_at'    => $this->accepted_at,
            'delivered_at'   => $this->delivered_at,
        ];
    }
}
