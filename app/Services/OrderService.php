<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * إنشاء طلب جديد.
     *
     * @param  array  $data  [store_id, address_id, payment_method, notes, coupon_code,
     *                       items => [[product_id, quantity, note, option_value_ids[]]]]
     */
    public function create(User $customer, array $data, ?User $actor = null): Order
    {
        $store = Store::findOrFail($data['store_id']);

        if (! $store->isAcceptingOrders()) {
            throw ValidationException::withMessages(['store_id' => 'المتجر مغلق توّا.']);
        }

        $address = $customer->addresses()->findOrFail($data['address_id']);

        return DB::transaction(function () use ($customer, $store, $address, $data, $actor) {
            $lines    = $this->buildLines($store, $data['items']);
            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

            if ($subtotal < $store->min_order) {
                throw ValidationException::withMessages([
                    'items' => "الحد الأدنى للطلب من هذا المتجر {$store->min_order} د.ل",
                ]);
            }

            $distance = ($store->lat && $store->lng)
                ? GeoService::distanceKm($store->lat, $store->lng, $address->lat, $address->lng)
                : 0;

            // المنطقة تتحسب من جديد — لو الإدارة عدّلت المناطق بعد حفظ العنوان
            $zone        = GeoService::requireZone((float) $address->lat, (float) $address->lng, 'address_id');
            $deliveryFee = GeoService::deliveryFee($distance, $zone);

            // الكوبون بعد حساب التوصيل — باش يشتغل عرض «توصيل مجاني»
            $coupon   = null;
            $discount = 0;
            if (! empty($data['coupon_code'])) {
                $coupon = Coupon::where('code', $data['coupon_code'])->first();
                if (! $coupon || ! $coupon->isUsableBy($customer, $subtotal, $store->id)) {
                    throw ValidationException::withMessages(['coupon_code' => 'الكوبون غير صالح.']);
                }
                $discount = $coupon->discountFor($subtotal, $deliveryFee);
            }

            $total      = round($subtotal + $deliveryFee - $discount, 2);
            $commission = round($subtotal * ($store->commission_percent / 100), 2);

            // الدفع من المحفظة (كامل أو جزئي)
            $walletService = app(WalletService::class);
            $walletPaid    = 0.0;

            if (($data['use_wallet'] ?? false) || ($data['payment_method'] ?? null) === 'wallet') {
                $available  = $walletService->balance($customer);
                $walletPaid = min($available, $total);

                if (($data['payment_method'] ?? null) === 'wallet' && $available < $total) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'رصيد المحفظة ما يكفيش. المتوفر: '
                            .number_format($available, 2).' د.ل',
                    ]);
                }
            }

            $order = Order::create([
                // رقم مؤقت — يتبدّل برقم متسلسل (id) مباشرة بعد الإنشاء
                'code'              => Order::temporaryCode(),
                'customer_id'       => $customer->id,
                'store_id'          => $store->id,
                'delivery_zone_id'  => $zone?->id,
                'coupon_id'         => $coupon?->id,
                'status'            => OrderStatus::Pending,
                'payment_method'    => $walletPaid >= $total ? 'wallet' : ($data['payment_method'] ?? 'cash'),
                'wallet_paid'       => $walletPaid,
                'is_paid'           => $walletPaid >= $total,
                'address_details'   => $address->details,
                'address_landmark'  => $address->landmark,
                'address_lat'       => $address->lat,
                'address_lng'       => $address->lng,
                'customer_phone'    => $customer->phone,
                'subtotal'          => $subtotal,
                'delivery_fee'      => $deliveryFee,
                'discount'          => $discount,
                'total'             => $total,
                'commission_amount' => $commission,
                'store_earning'     => round($subtotal - $commission, 2),
                // أجرة السائق ما تتأثرش بعرض التوصيل المجاني — المنصة تتحمّلها
                'driver_earning'    => $deliveryFee,
                'distance_km'       => $distance,
                'notes'             => $data['notes'] ?? null,
                'prep_time_minutes' => $store->prep_time_minutes,
            ]);

            if ($walletPaid > 0) {
                $walletService->debit(
                    $customer,
                    $walletPaid,
                    'order_payment',
                    $order,
                    "طلب {$order->code}"
                );
            }

            $order->items()->createMany($lines);

            // إنقاص المخزون للمنتجات اللي تتتبّع الكمية
            foreach ($lines as $line) {
                if ($line['product_id']) {
                    Product::find($line['product_id'])?->reduceStock($line['quantity']);
                }
            }

            // منو أنشأ الطلب فعلاً: الزبون من التطبيق، أو موظف الإدارة لو طلب يدوي
            $isManual = $actor && $actor->id !== $customer->id;

            $order->statusLogs()->create([
                'from_status' => null,
                'to_status'   => OrderStatus::Pending->value,
                'changed_by'  => $actor?->id ?? $customer->id,
                'note'        => $isManual ? 'طلب يدوي أنشأته الإدارة' : null,
                'created_at'  => now(),
            ]);

            if ($coupon) {
                $coupon->increment('used_count');
                DB::table('coupon_user')->insert([
                    'coupon_id'  => $coupon->id,
                    'user_id'    => $customer->id,
                    'order_id'   => $order->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($store->owner && NotificationSetting::isEnabled('store', 'pending')) {
                PushService::toUser(
                    $store->owner,
                    'طلب جديد',
                    "طلب رقم {$order->code} — افتح التطبيق",
                    ['type' => 'new_order', 'order_id' => (string) $order->id]
                );
            }

            if ($customer && NotificationSetting::isEnabled('customer', 'pending')) {
                PushService::toUser(
                    $customer,
                    "طلب {$order->code}",
                    'استلمنا طلبك، بانتظار موافقة المتجر',
                    ['type' => 'order_status', 'order_id' => (string) $order->id]
                );
            }

            return $order->load('items');
        });
    }

    /**
     * حساب التسعيرة قبل إنشاء الطلب — يستعملها الزبون
     * باش يشوف رسوم التوصيل والإجمالي قبل التأكيد.
     */
    public function quote(User $customer, array $data): array
    {
        $store   = Store::findOrFail($data['store_id']);
        $address = $customer->addresses()->findOrFail($data['address_id']);

        $lines    = $this->buildLines($store, $data['items']);
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

        $distance = ($store->lat && $store->lng)
            ? GeoService::distanceKm($store->lat, $store->lng, $address->lat, $address->lng)
            : 0;

        // المنطقة تتحسب من جديد — لو الإدارة عدّلت المناطق بعد حفظ العنوان
            $zone        = GeoService::requireZone((float) $address->lat, (float) $address->lng, 'address_id');
        $deliveryFee = GeoService::deliveryFee($distance, $zone);

        $discount   = 0;
        $couponError = null;

        if (! empty($data['coupon_code'])) {
            $coupon = Coupon::where('code', $data['coupon_code'])->first();

            if (! $coupon || ! $coupon->isUsableBy($customer, $subtotal, $store->id)) {
                $couponError = 'الكوبون غير صالح.';
            } else {
                $discount = $coupon->discountFor($subtotal, $deliveryFee);
            }
        }

        $total = round($subtotal + $deliveryFee - $discount, 2);

        $walletBalance = app(WalletService::class)->balance($customer);
        $walletPaid    = 0.0;

        if (($data['use_wallet'] ?? false) || ($data['payment_method'] ?? null) === 'wallet') {
            $walletPaid = min($walletBalance, $total);
        }

        return [
            'subtotal'        => $subtotal,
            'delivery_fee'    => $deliveryFee,
            'discount'        => $discount,
            'total'           => $total,
            'distance_km'     => $distance,
            'min_order'       => (float) $store->min_order,
            'below_min_order' => $subtotal < $store->min_order,
            'wallet_balance'  => $walletBalance,
            'wallet_paid'     => round($walletPaid, 2),
            'cash_due'        => round($total - $walletPaid, 2),
            'coupon_error'    => $couponError,
        ];
    }

    /** بناء أسطر الطلب من أسعار قاعدة البيانات — أبداً ما نثق في السعر الجاي من التطبيق */
    private function buildLines(Store $store, array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $product = Product::with('options.values')
                ->where('store_id', $store->id)
                ->where('is_available', true)
                ->findOrFail($item['product_id']);

            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if ($product->max_per_order && $quantity > $product->max_per_order) {
                throw ValidationException::withMessages([
                    'items' => "أقصى كمية من «{$product->name}» في الطلب الواحد {$product->max_per_order}.",
                ]);
            }

            if ($product->track_stock && $product->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'items' => $product->stock_quantity > 0
                        ? "المتوفر من «{$product->name}» توّا {$product->stock_quantity} فقط."
                        : "«{$product->name}» خلص من المخزن.",
                ]);
            }

            $optionsPrice = 0;
            $chosen       = [];

            foreach ($product->options as $option) {
                $ids = array_intersect(
                    $item['option_value_ids'] ?? [],
                    $option->values->pluck('id')->all()
                );

                if ($option->is_required && count($ids) === 0) {
                    throw ValidationException::withMessages([
                        'items' => "اختيار «{$option->name}» مطلوب في «{$product->name}».",
                    ]);
                }

                if (count($ids) > $option->max_choices) {
                    throw ValidationException::withMessages([
                        'items' => "تجاوزت الحد المسموح في «{$option->name}».",
                    ]);
                }

                foreach ($option->values->whereIn('id', $ids) as $value) {
                    $optionsPrice += $value->extra_price;
                    $chosen[] = [
                        'option' => $option->name,
                        'value'  => $value->name,
                        'price'  => $value->extra_price,
                    ];
                }
            }

            $unitPrice = $product->effectivePrice();

            $lines[] = [
                'product_id'    => $product->id,
                'name'          => $product->name,
                'unit_price'    => $unitPrice,
                'quantity'      => $quantity,
                'options'       => $chosen,
                'options_price' => $optionsPrice,
                'line_total'    => round(($unitPrice + $optionsPrice) * $quantity, 2),
                'note'          => $item['note'] ?? null,
            ];
        }

        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => 'السلة فارغة.']);
        }

        return $lines;
    }

    /** الانتقال بين الحالات — البوابة الوحيدة لتغيير حالة الطلب */
    public function transition(
        Order $order,
        OrderStatus $to,
        ?User $actor = null,
        array $extra = []
    ): Order {
        $from = $order->status;

        // الإدارة تقدر تفرض أي انتقال (force) — التطبيقات لا
        $force      = (bool) ($extra['force'] ?? false);
        $isAbnormal = ! $from->canMoveTo($to);

        if ($from === $to) {
            throw ValidationException::withMessages([
                'status' => "الطلب أصلاً في حالة «{$to->label()}».",
            ]);
        }

        if ($isAbnormal && ! $force) {
            throw ValidationException::withMessages([
                'status' => "لا يمكن الانتقال من «{$from->label()}» إلى «{$to->label()}».",
            ]);
        }

        return DB::transaction(function () use ($order, $from, $to, $actor, $extra, $isAbnormal) {
            $payload = ['status' => $to];

            match ($to) {
                OrderStatus::Accepted => $payload = $payload + [
                    'accepted_at'       => now(),
                    'prep_time_minutes' => $extra['prep_time_minutes'] ?? $order->prep_time_minutes,
                ],
                // القبول = بدء التحضير: نسجّلو وقت القبول ومدة التحضير اللي حددها المتجر
                OrderStatus::Preparing => $payload = $payload + [
                    'accepted_at'       => $order->accepted_at ?? now(),
                    'prep_time_minutes' => $extra['prep_time_minutes'] ?? $order->prep_time_minutes,
                ],
                OrderStatus::Ready     => $payload['ready_at'] = now(),
                OrderStatus::Assigned  => $payload['driver_id'] = $extra['driver_id'] ?? $order->driver_id,
                OrderStatus::PickedUp  => $payload['picked_up_at'] = now(),
                OrderStatus::Delivered => $payload = $payload + [
                    'delivered_at' => now(),
                    'is_paid'      => true,
                ],
                OrderStatus::Cancelled, OrderStatus::Failed => $payload = $payload + [
                    'cancelled_at'  => now(),
                    'cancel_reason' => $extra['reason'] ?? null,
                    'cancelled_by'  => $actor?->id,
                ],
                default => null,
            };

            $order->update($payload);

            $note = $extra['reason'] ?? null;

            if ($isAbnormal) {
                $note = '⚠ تغيير استثنائي من الإدارة'.($note ? " — {$note}" : '');
            }

            $order->statusLogs()->create([
                'from_status' => $from->value,
                'to_status'   => $to->value,
                'changed_by'  => $actor?->id,
                'note'        => $note,
                'lat'         => $extra['lat'] ?? null,
                'lng'         => $extra['lng'] ?? null,
                'created_at'  => now(),
            ]);

            // إرجاع المخزون لو الطلب انلغى أو فشل
            if (in_array($to, [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
                foreach ($order->items as $item) {
                    if ($item->product_id) {
                        Product::find($item->product_id)?->restoreStock($item->quantity);
                    }
                }
            }

            // استرجاع ما دُفع من المحفظة
            $alreadyRefunded = WalletTransaction::where('order_id', $order->id)
                ->where('type', 'order_refund')
                ->exists();

            if (in_array($to, [OrderStatus::Cancelled, OrderStatus::Failed], true)
                && $order->wallet_paid > 0
                && ! $alreadyRefunded) {
                app(WalletService::class)->credit(
                    $order->customer,
                    (float) $order->wallet_paid,
                    'order_refund',
                    $order,
                    "إلغاء طلب {$order->code}",
                    $actor
                );
            }

            // توزيع المستحقات عند التسليم
            if ($to === OrderStatus::Delivered) {
                app(WalletService::class)->settleOrderEarnings($order->fresh(['store.owner', 'driver']));
            }

            if ($to === OrderStatus::Delivered && $order->driver) {
                $profile = $order->driver->driverProfile;
                if ($profile) {
                    $profile->increment('delivered_count');
                }
            }

            $this->notify($order->fresh(), $to);

            return $order->fresh(['items', 'store', 'driver']);
        });
    }

    private function notify(Order $order, OrderStatus $to): void
    {
        $data = [
            'type'     => 'order_status',
            'order_id' => (string) $order->id,
            'status'   => $to->value,
        ];

        $title = "طلب {$order->code}";

        // ===== الزبون =====
        if ($order->customer && NotificationSetting::isEnabled('customer', $to->value)) {
            PushService::toUser($order->customer, $title, $this->customerBody($order, $to), $data);
        }

        // ===== السائق المسند =====
        // ملاحظة: قبل الإسناد ما فيش سائق نبعتله، فالحالات الأولى
        // ما توصلش حتى لو مفتاحها مفتوح.
        if ($order->driver && NotificationSetting::isEnabled('driver', $to->value)) {
            PushService::toUser($order->driver, $title, $this->driverBody($order, $to), $data);
        }

        // ===== المتجر =====
        if ($order->store?->owner && NotificationSetting::isEnabled('store', $to->value)) {
            PushService::toUser($order->store->owner, $title, $this->storeBody($order, $to), $data);
        }

        // ===== السائقين المتاحين: طلب جاهز للاستلام =====
        if ($to === OrderStatus::Ready
            && ! $order->driver_id
            && NotificationSetting::isEnabled('driver', 'available')) {
            $this->notifyAvailableDrivers($order);
        }
    }

    private function customerBody(Order $order, OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Pending   => 'استلمنا طلبك، بانتظار موافقة المتجر',
            OrderStatus::Accepted  => $order->prep_time_minutes
                ? "المتجر قبل طلبك — جاهز خلال {$order->prep_time_minutes} دقيقة"
                : 'المتجر قبل طلبك',
            OrderStatus::Preparing => $order->prep_time_minutes
                ? "المتجر قبل طلبك وبدا التحضير — جاهز خلال {$order->prep_time_minutes} دقيقة تقريباً"
                    .($order->readyEta() ? ' (حوالي الساعة '.\App\Support\LocalDay::toLocal($order->readyEta())->format('H:i').')' : '')
                : 'المتجر قبل طلبك وبدا التحضير',
            OrderStatus::Ready     => 'طلبك جاهز، السائق في الطريق للمتجر',
            OrderStatus::Assigned  => $order->driver
                ? "أُسند طلبك للسائق {$order->driver->name}"
                : 'أُسند طلبك لسائق',
            OrderStatus::PickedUp  => 'السائق استلم طلبك من المتجر',
            OrderStatus::OnTheWay  => 'السائق في الطريق إليك',
            OrderStatus::Delivered => 'تم تسليم طلبك — شكراً لك',
            OrderStatus::Cancelled => 'تم إلغاء طلبك'
                .($order->cancel_reason ? ": {$order->cancel_reason}" : ''),
            OrderStatus::Failed    => 'ما نجحش تسليم طلبك'
                .($order->cancel_reason ? ": {$order->cancel_reason}" : ''),
        };
    }

    private function driverBody(Order $order, OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Assigned  => "{$order->store?->name} ← {$order->address_details}",
            OrderStatus::PickedUp  => 'سجّلت استلام الطلب من المتجر',
            OrderStatus::OnTheWay  => 'أنت في الطريق للزبون',
            OrderStatus::Delivered => 'تم تسليم الطلب — أجرتك '
                .number_format((float) $order->driver_earning, 2).' د.ل',
            OrderStatus::Cancelled => 'الطلب انلغى'
                .($order->cancel_reason ? ": {$order->cancel_reason}" : ''),
            OrderStatus::Failed    => 'تم تسجيل فشل التسليم',
            default                => $to->label(),
        };
    }

    private function storeBody(Order $order, OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Pending   => 'طلب جديد وصلك — افتح التطبيق',
            OrderStatus::Accepted  => 'تم قبول الطلب',
            OrderStatus::Preparing => 'الطلب قيد التحضير',
            OrderStatus::Ready     => 'الطلب جاهز، بانتظار السائق',
            OrderStatus::Assigned  => $order->driver
                ? "السائق {$order->driver->name} في الطريق ليك"
                : 'أُسند الطلب لسائق',
            OrderStatus::PickedUp  => 'استلمه السائق وخرج',
            OrderStatus::OnTheWay  => 'السائق في الطريق للزبون',
            OrderStatus::Delivered => 'تم تسليم الطلب للزبون',
            OrderStatus::Cancelled => 'تم إلغاء الطلب'
                .($order->cancel_reason ? ": {$order->cancel_reason}" : ''),
            OrderStatus::Failed    => 'فشل تسليم الطلب'
                .($order->cancel_reason ? ": {$order->cancel_reason}" : ''),
        };
    }

    /**
     * إشعار كل سائق متاح يقدر ياخذ الطلب.
     * يحترم مناطق العمل وسعة السائق ووضع تعدد الطلبات.
     */
    private function notifyAvailableDrivers(Order $order): void
    {
        $drivers = User::where('role', 'driver')
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->whereHas('driverProfile', fn ($q) => $q
                ->where('is_approved', true)
                ->where('is_online', true))
            ->with('driverProfile')
            ->get();

        $title = 'طلب جديد متاح';
        $body  = "{$order->store?->name} — أجرتك "
            .number_format((float) $order->driver_earning, 2).' د.ل'
            .' · '.number_format((float) $order->distance_km, 1).' كم';

        foreach ($drivers as $driver) {
            if (! $driver->driverProfile?->canAccept($order)) {
                continue;
            }

            PushService::toUser($driver, $title, $body, [
                'type'     => 'order_available',
                'order_id' => (string) $order->id,
            ]);
        }

        $order->update(['drivers_notified_at' => now()]);
    }
}
