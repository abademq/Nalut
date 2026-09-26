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
use App\Support\Options;
use App\Support\Texts;
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
            throw ValidationException::withMessages(['store_id' => Texts::get('msg.store_closed')]);
        }

        $address = $customer->addresses()->findOrFail($data['address_id']);

        return DB::transaction(function () use ($customer, $store, $address, $data, $actor) {
            $lines    = $this->buildLines($store, $data['items']);
            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

            if ($subtotal < $store->min_order) {
                throw ValidationException::withMessages([
                    'items' => Texts::get('msg.min_order', ['min' => $store->min_order]),
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
                    throw ValidationException::withMessages(['coupon_code' => Texts::get('msg.coupon_invalid')]);
                }
                $discount = $coupon->discountFor($subtotal, $deliveryFee);
            }

            $total      = round($subtotal + $deliveryFee - $discount, 2);

            // نقاط الولاء (لو الإدارة مختارة «يدفع بيها مباشرة») — تنخصم قبل المحفظة
            [$pointsUsed, $pointsDiscount] = ($data['use_points'] ?? false)
                ? app(PointsService::class)->checkoutDiscount($customer, $total)
                : [0, 0.0];
            $total = round($total - $pointsDiscount, 2);

            $commission = round($subtotal * ($store->commission_percent / 100), 2);

            // الدفع من المحفظة (كامل أو جزئي)
            $walletService = app(WalletService::class);
            $walletPaid    = 0.0;

            if (($data['use_wallet'] ?? false) || ($data['payment_method'] ?? null) === 'wallet') {
                $available  = $walletService->balance($customer);
                $walletPaid = min($available, $total);

                if (($data['payment_method'] ?? null) === 'wallet' && $available < $total) {
                    throw ValidationException::withMessages([
                        'payment_method' => Texts::get('msg.wallet_insufficient', ['balance' => number_format($available, 2)]),
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
                'points_used'       => $pointsUsed,
                'points_discount'   => $pointsDiscount,
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

            app(PointsService::class)->redeemOnOrder($customer, $order, $pointsUsed);

            // إنقاص المخزون — ذرّي: «انقص بس لو الكمية تكفي» في استعلام واحد.
            // زبونين يطلبو آخر قطعة في نفس اللحظة: واحد ينجح، والثاني يرجعله
            // خطأ واضح والطلب متاعه ما ينحفظش (الـ transaction ترجع كل شي).
            $this->reserveStock($lines);

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

            // طلب بالبطاقة ما يوصلش للمتجر قبل ما الدفع يتأكد —
            // الإشعار يتبعت من PaymentController بعد نجاح الدفع
            if (! $order->awaitingOnlinePayment()) {
                $this->notifyStoreNewOrder($order);
            }

            if ($customer && NotificationSetting::isEnabled('customer', 'pending')) {
                PushService::toUser(
                    $customer,
                    "طلب {$order->code}",
                    'استلمنا طلبك، بانتظار موافقة المتجر',
                    ['type' => 'order_status', 'order_id' => (string) $order->id],
                    'customer'
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

        $points = app(PointsService::class);
        [$pointsAvailable, $pointsAvailableDiscount] = $points->checkoutDiscount($customer, $total);
        [$pointsUsed, $pointsDiscount] = ($data['use_points'] ?? false) ? [$pointsAvailable, $pointsAvailableDiscount] : [0, 0.0];
        $total = round($total - $pointsDiscount, 2);

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
            'points_balance'  => (int) $customer->points_balance,
            // كم نقطة وكم دينار يقدر يستعمل في الطلب هذا (0 لو النقاط تتحوّل للمحفظة بس)
            'points_available'          => $pointsAvailable,
            'points_available_discount' => $pointsAvailableDiscount,
            'points_used'     => $pointsUsed,
            'points_discount' => $pointsDiscount,
        ];
    }

    /** @param  array<int, array{product_id: ?int, quantity: int, name: string}>  $lines */
    private function reserveStock(array $lines): void
    {
        $totals = [];
        foreach ($lines as $line) {
            if ($line['product_id']) {
                $totals[$line['product_id']] = ($totals[$line['product_id']] ?? 0) + $line['quantity'];
            }
        }

        foreach ($totals as $productId => $qty) {
            $product = Product::find($productId);
            if (! $product?->track_stock) {
                continue;
            }

            $updated = Product::whereKey($productId)
                ->where('track_stock', true)
                ->where('stock_quantity', '>=', $qty)
                ->decrement('stock_quantity', $qty);

            if ($updated === 0) {
                $left = (int) Product::whereKey($productId)->value('stock_quantity');
                throw ValidationException::withMessages([
                    'items' => $left > 0
                        ? Texts::get('msg.stock_race_left', ['name' => $product->name, 'left' => $left])
                        : Texts::get('msg.stock_race_out', ['name' => $product->name]),
                ]);
            }

            // خلص = يختفي من القائمة لين المتجر يعبّيه
            Product::whereKey($productId)->where('stock_quantity', '<=', 0)
                ->update(['is_available' => false, 'stock_quantity' => 0, 'sold_out_at' => now()]);
        }
    }

    /** بناء أسطر الطلب من أسعار قاعدة البيانات — أبداً ما نثق في السعر الجاي من التطبيق */
    private function buildLines(Store $store, array $items): array
    {
        $lines = [];

        // الكمية الإجمالية لكل منتج — نفس المنتج ممكن يجي في أكثر من سطر
        // (خيارات مختلفة)، والحد الأقصى والمخزون ينطبقو على المجموع مش على كل سطر
        $totals = [];
        foreach ($items as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $totals[$pid] = ($totals[$pid] ?? 0) + max(1, (int) ($item['quantity'] ?? 1));
        }

        foreach ($items as $item) {
            $product = Product::with('options.values')
                ->where('store_id', $store->id)
                ->find($item['product_id']);

            // كان يرجع 404 عام — الزبون ما يعرفش إن المنتج خلص وهو في سلته
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => Texts::get('msg.product_missing'),
                ]);
            }

            if (! $product->is_available) {
                throw ValidationException::withMessages([
                    'items' => Texts::get('msg.product_unavailable', ['name' => $product->name]),
                ]);
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            $total = $totals[$product->id] ?? $quantity;

            if ($product->max_per_order && $total > $product->max_per_order) {
                throw ValidationException::withMessages([
                    'items' => Texts::get('msg.max_per_order', ['name' => $product->name, 'max' => $product->max_per_order]),
                ]);
            }

            if ($product->track_stock && $product->stock_quantity < $total) {
                throw ValidationException::withMessages([
                    'items' => $product->stock_quantity > 0
                        ? Texts::get('msg.stock_left', ['name' => $product->name, 'left' => $product->stock_quantity])
                        : Texts::get('msg.stock_out', ['name' => $product->name]),
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
                        'items' => Texts::get('msg.option_required', ['option' => $option->name, 'name' => $product->name]),
                    ]);
                }

                if (count($ids) > $option->max_choices) {
                    throw ValidationException::withMessages([
                        'items' => Texts::get('msg.option_too_many', ['option' => $option->name]),
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
            throw ValidationException::withMessages(['items' => Texts::get('msg.cart_empty')]);
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

        // الدفع الإلكتروني لازم يتأكد قبل التحضير وقبل التسليم —
        // وإلا الطلب يتسلّم ويتعلّم مدفوع وتتوزّع المستحقات بدون ما حد دفع.
        // حتى الإدارة (force) ما تتجاوزهاش: لو الزبون دفع بطريقة ثانية، غيّر طريقة الدفع أول.
        if ($order->awaitingOnlinePayment()
            && in_array($to, [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Assigned,
                OrderStatus::PickedUp, OrderStatus::OnTheWay, OrderStatus::Delivered], true)) {
            throw ValidationException::withMessages([
                'status' => Texts::get('msg.awaiting_payment'),
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

            // إرجاع المخزون والنقاط لو الطلب انلغى أو فشل
            if (in_array($to, [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
                $this->restoreStock($order);
                app(PointsService::class)->refund($order);
            }

            // نقاط الولاء على الطلب المكتمل
            if ($to === OrderStatus::Delivered) {
                app(PointsService::class)->award($order->fresh('customer'));
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

            $this->alertAdmins($order->fresh(['store', 'driver']), $to, $actor);

            return $order->fresh(['items', 'store', 'driver']);
        });
    }

    /**
     * يرجّع كميات الطلب للمخزون — مرة وحدة بس لكل طلب.
     * قبل ما السائق يستلم: دائماً. بعد الاستلام: حسب إعداد الإدارة
     * (البضاعة غالباً طلعت من المتجر ومش راجعة).
     */
    private function restoreStock(Order $order): void
    {
        if ($order->stock_restored_at) {
            return;
        }

        if ($order->picked_up_at && ! \App\Support\Options::get('stock.restore_after_pickup')) {
            return;
        }

        $totals = [];
        foreach ($order->items()->get() as $item) {
            if ($item->product_id) {
                $totals[$item->product_id] = ($totals[$item->product_id] ?? 0) + $item->quantity;
            }
        }

        foreach ($totals as $productId => $qty) {
            Product::withTrashed()->find($productId)?->restoreStock($qty);
        }

        $order->forceFill(['stock_restored_at' => now()])->saveQuietly();
    }

    /** الحالات اللي تحتاج تدخّل الإدارة — تنبيه فوري في اللوحة */
    private function alertAdmins(Order $order, OrderStatus $to, ?User $actor): void
    {
        $url = \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $order->id], panel: 'admin');
        $reason = $order->cancel_reason ? " — {$order->cancel_reason}" : '';

        if ($to === OrderStatus::Failed) {
            AdminAlerts::send(
                "فشل تسليم الطلب {$order->code}",
                "السائق: {$order->driver?->name} · المتجر: {$order->store?->name}{$reason}",
                $url, 'danger', "order-failed:{$order->id}"
            );
        }

        // المتجر رفض الطلب
        if ($to === OrderStatus::Cancelled && $actor && $order->store && $actor->id === $order->store->user_id) {
            AdminAlerts::send(
                "المتجر رفض الطلب {$order->code}",
                "{$order->store?->name}{$reason}",
                $url, 'warning', "order-rejected:{$order->id}"
            );
        }
    }

    /** إشعار «طلب جديد» لصاحب المتجر */
    public function notifyStoreNewOrder(Order $order): void
    {
        $owner = $order->store?->owner;

        if ($owner && NotificationSetting::isEnabled('store', 'pending')) {
            PushService::toUser(
                $owner,
                Texts::get('notify.store.new_order'),
                Texts::get('notify.store.new_order_body', ['code' => $order->code]),
                ['type' => 'new_order', 'order_id' => (string) $order->id],
                'store'
            );
        }
    }

    /**
     * المتجر جهّز الطلب بعد ما السائق قبله (الحالة Assigned).
     * ما نغيّروش الحالة — نسجّلو ready_at ونبلّغو السائق والزبون.
     */
    public function markReadyWhileAssigned(Order $order, ?User $actor = null): Order
    {
        if ($order->ready_at) {
            throw ValidationException::withMessages(['status' => 'الطلب متعلّم جاهز من قبل.']);
        }

        $order->update(['ready_at' => now()]);

        $data = ['type' => 'order_status', 'order_id' => (string) $order->id, 'status' => 'ready'];
        $title = Texts::get('notify.title', ['code' => $order->code]);

        if ($order->driver && NotificationSetting::isEnabled('driver', 'ready')) {
            PushService::toUser($order->driver, $title, Texts::get('notify.driver.ready'), $data, 'driver');
        }

        if ($order->customer && NotificationSetting::isEnabled('customer', 'ready')) {
            PushService::toUser($order->customer, $title, $this->customerBody($order, OrderStatus::Ready), $data, 'customer');
        }

        return $order->fresh(['items', 'store', 'driver']);
    }

    private function notify(Order $order, OrderStatus $to): void
    {
        $data = [
            'type'     => 'order_status',
            'order_id' => (string) $order->id,
            'status'   => $to->value,
        ];

        $title = Texts::get('notify.title', ['code' => $order->code]);

        // ===== الزبون =====
        if ($order->customer && NotificationSetting::isEnabled('customer', $to->value)) {
            PushService::toUser($order->customer, $title, $this->customerBody($order, $to), $data, 'customer');
        }

        // ===== السائق المسند =====
        // ملاحظة: قبل الإسناد ما فيش سائق نبعتله، فالحالات الأولى
        // ما توصلش حتى لو مفتاحها مفتوح.
        if ($order->driver && NotificationSetting::isEnabled('driver', $to->value)) {
            PushService::toUser($order->driver, $title, $this->driverBody($order, $to), $data, 'driver');
        }

        // ===== المتجر =====
        if ($order->store?->owner && NotificationSetting::isEnabled('store', $to->value)) {
            PushService::toUser($order->store->owner, $title, $this->storeBody($order, $to), $data, 'store');
        }

        // ===== السائقين المتاحين: طلب جاهز للاستلام =====
        if ($to === OrderStatus::Ready
            && ! $order->driver_id
            && NotificationSetting::isEnabled('driver', 'available')) {
            $this->notifyAvailableDrivers($order);
        }
    }

    /** المتغيرات المشتركة لنصوص الإشعارات */
    private function vars(Order $order): array
    {
        return [
            'code'     => $order->code,
            'prep'     => $order->prep_time_minutes ?: Options::get('orders.default_prep_minutes'),
            'driver'   => $order->driver?->name ?? '',
            'store'    => $order->store?->name ?? '',
            'address'  => $order->address_details,
            'reason'   => $order->cancel_reason ?? '',
            'earning'  => number_format((float) $order->driver_earning, 2),
            'distance' => number_format((float) $order->distance_km, 1),
        ];
    }

    private function customerBody(Order $order, OrderStatus $to): string
    {
        return Texts::get('notify.customer.'.$to->value, $this->vars($order));
    }

    private function driverBody(Order $order, OrderStatus $to): string
    {
        $key = 'notify.driver.'.$to->value;

        return array_key_exists($key, Texts::serverDefinitions())
            ? Texts::get($key, $this->vars($order))
            : $to->label();
    }

    private function storeBody(Order $order, OrderStatus $to): string
    {
        return Texts::get('notify.store.'.$to->value, $this->vars($order));
    }

    /**
     * إشعار كل سائق متاح يقدر ياخذ الطلب.
     * يحترم مناطق العمل وسعة السائق ووضع تعدد الطلبات.
     */
    private function notifyAvailableDrivers(Order $order): void
    {
        $drivers = User::withRole('driver')
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->whereHas('driverProfile', fn ($q) => $q
                ->where('is_approved', true)
                ->where('is_online', true))
            ->with('driverProfile')
            ->get();

        $title = Texts::get('notify.driver.available_title');
        $body  = Texts::get('notify.driver.available', $this->vars($order));

        foreach ($drivers as $driver) {
            if (! $driver->driverProfile?->canAccept($order)) {
                continue;
            }

            PushService::toUser($driver, $title, $body, [
                'type'     => 'order_available',
                'order_id' => (string) $order->id,
            ], 'driver');
        }

        $order->update(['drivers_notified_at' => now()]);
    }
}
