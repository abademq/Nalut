<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Filament\Pages\AppSettings;
use App\Models\FailureReason;
use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\User;
use App\Support\Texts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * بلاغات السائق لما يتعذّر التسليم.
 *
 * حسب إعداد السبب في لوحة التحكم:
 *  - hold_for_review: الطلب يتعلّق «قيد مراجعة الإدارة» — لا يفشل ولا يكمّل لين الإدارة تقرر
 *  - غير ذلك: الطلب يفشل مباشرة (زي قبل)
 *  - open_support: يرجع رابط واتساب للدعم الفني برسالة جاهزة فيها تفاصيل البلاغ
 */
class DeliveryIssueService
{
    public function __construct(private readonly OrderService $orders) {}

    public function report(Order $order, User $driver, FailureReason $reason, ?string $note = null, ?float $lat = null, ?float $lng = null): OrderIssue
    {
        if ($order->driver_id !== $driver->id) {
            abort(403);
        }

        if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::PickedUp, OrderStatus::OnTheWay], true)) {
            throw ValidationException::withMessages(['reason_id' => 'ما تقدرش تبلّغ على طلب في هذي الحالة.']);
        }

        if ($order->openIssue()->exists()) {
            throw ValidationException::withMessages(['reason_id' => 'الطلب عليه بلاغ مفتوح — الإدارة تتابع.']);
        }

        // قبل الاستلام من المتجر ما فيش «فشل تسليم» — أي مشكلة تمشي للمراجعة
        $review = $reason->hold_for_review || $order->status === OrderStatus::Assigned;

        return DB::transaction(function () use ($order, $driver, $reason, $note, $lat, $lng, $review) {
            $n = OrderIssue::where('order_id', $order->id)->lockForUpdate()->count() + 1;

            $issue = OrderIssue::create([
                'ticket'            => 'T'.$order->code.'-'.$n,
                'order_id'          => $order->id,
                'driver_id'         => $driver->id,
                'failure_reason_id' => $reason->id,
                'reason_label'      => $reason->label,
                'note'              => $note,
                'action'            => $review ? 'review' : 'failed',
                'status'            => $review ? 'open' : 'resolved',
                'resolution'        => $review ? null : 'failed',
                'resolved_at'       => $review ? null : now(),
                'lat'               => $lat,
                'lng'               => $lng,
            ]);

            if ($review) {
                $order->statusLogs()->create([
                    'from_status' => $order->status->value,
                    'to_status'   => $order->status->value,
                    'changed_by'  => $driver->id,
                    'note'        => "بلاغ {$issue->ticket}: {$reason->label} — قيد مراجعة الإدارة",
                    'lat'         => $lat,
                    'lng'         => $lng,
                    'created_at'  => now(),
                ]);

                if ($order->customer && NotificationSetting::isEnabled('customer', 'failed')) {
                    PushService::toUser(
                        $order->customer,
                        Texts::get('notify.title', ['code' => $order->code]),
                        Texts::get('notify.customer.under_review'),
                        ['type' => 'order_status', 'order_id' => (string) $order->id, 'status' => 'review'],
                        'customer'
                    );
                }
            } else {
                $this->orders->transition($order, OrderStatus::Failed, $driver, [
                    'reason' => $reason->label.($note ? " — {$note}" : ''),
                    'lat'    => $lat,
                    'lng'    => $lng,
                ]);
            }

            if ($review) {
                AdminAlerts::send(
                    "بلاغ من السائق — {$issue->ticket}",
                    "{$reason->label}".($note ? " — {$note}" : '')." · الطلب {$order->code} قيد مراجعتك",
                    \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $order->id], panel: 'admin'),
                    'danger',
                    "issue:{$issue->ticket}"
                );
            }

            return $issue->fresh(['order.store', 'driver']);
        });
    }

    /** قرار الإدارة على بلاغ مفتوح */
    public function resolve(OrderIssue $issue, User $admin, string $resolution, ?string $note = null): OrderIssue
    {
        if (! $issue->isOpen()) {
            throw ValidationException::withMessages(['resolution' => 'البلاغ مقفول من قبل.']);
        }

        if (! array_key_exists($resolution, OrderIssue::RESOLUTIONS)) {
            throw ValidationException::withMessages(['resolution' => 'قرار غير معروف.']);
        }

        return DB::transaction(function () use ($issue, $admin, $resolution, $note) {
            $order = $issue->order;
            $reason = "بلاغ {$issue->ticket}: {$issue->reason_label}".($note ? " — {$note}" : '');

            // نقفلو البلاغ أول — باش الانتقالات تحت ما يوقفهاش «بلاغ مفتوح»
            $issue->update([
                'status'          => 'resolved',
                'resolution'      => $resolution,
                'resolution_note' => $note,
                'resolved_by'     => $admin->id,
                'resolved_at'     => now(),
            ]);

            match ($resolution) {
                'continue'  => $issue->driver && PushService::toUser(
                    $issue->driver, Texts::get('notify.title', ['code' => $order->code]), Texts::get('notify.driver.review_continue'),
                    ['type' => 'order_status', 'order_id' => (string) $order->id],
                    'driver'
                ),
                'reassign'  => $this->reassign($order, $admin, $reason),
                'failed'    => $this->orders->transition($order, OrderStatus::Failed, $admin, ['reason' => $reason, 'force' => true]),
                'cancelled' => $this->orders->transition($order, OrderStatus::Cancelled, $admin, ['reason' => $reason, 'force' => true]),
            };

            return $issue->fresh();
        });
    }

    /** يرجّع الطلب «جاهز» بدون سائق — يوصل للسائقين المتاحين من جديد */
    private function reassign(Order $order, User $admin, string $reason): void
    {
        $old = $order->driver;
        $order->update(['driver_id' => null, 'drivers_notified_at' => null]);

        $this->orders->transition($order->fresh(), OrderStatus::Ready, $admin, ['reason' => $reason, 'force' => true]);

        if ($old) {
            PushService::toUser($old, Texts::get('notify.title', ['code' => $order->code]), Texts::get('notify.driver.reassigned_away'), [
                'type' => 'order_status', 'order_id' => (string) $order->id,
            ], 'driver');
        }
    }

    /**
     * رابط واتساب الدعم الفني برسالة جاهزة — null لو السبب ما يفتحش دعم
     * أو رقم الدعم مش مضبوط في «عن التطبيق».
     */
    public function supportUrl(OrderIssue $issue): ?string
    {
        $reason = $issue->failure_reason_id ? FailureReason::find($issue->failure_reason_id) : null;
        if (! $reason?->open_support) {
            return null;
        }

        // رقم الدعم الفني — ولو فاضي نستعملو واتساب «عن التطبيق»
        $about = AppSettings::values();
        $number = preg_replace('/\D/', '', (string) (($about['support_whatsapp'] ?? '') ?: ($about['whatsapp'] ?? '')));
        // 091xxxxxxx → 21891xxxxxxx
        if (preg_match('/^09\d{8}$/', $number)) {
            $number = '218'.substr($number, 1);
        }
        if ($number === '') {
            return null;
        }

        $order = $issue->order;
        $lines = [
            "🚨 بلاغ تعذّر تسليم — {$issue->ticket}",
            "الطلب: {$order->code}",
            "السبب: {$issue->reason_label}",
            'الحالة: '.($issue->action === 'review' ? 'قيد مراجعة الإدارة' : 'فشل التسليم'),
            "السائق: {$issue->driver?->name} ({$issue->driver?->phone})",
            "المتجر: {$order->store?->name}",
            "عنوان الزبون: {$order->address_details}",
        ];
        if ($issue->note) {
            $lines[] = "ملاحظات: {$issue->note}";
        }
        if ($issue->lat && $issue->lng) {
            $lines[] = "موقع السائق: https://maps.google.com/?q={$issue->lat},{$issue->lng}";
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode(implode("\n", $lines));
    }
}
