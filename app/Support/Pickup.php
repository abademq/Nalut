<?php

namespace App\Support;

use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

/**
 * الاستلام من المطعم.
 *
 * الطلب بدون سائق ولا رسوم توصيل. يتدفع إلكترونياً قبل (بطاقة، ومحفظة كاملة لو الإدارة سامحة)
 * باش ما يصيرش طلب يتجهّز والزبون ما يجيش.
 * المسار: جديد → قيد التحضير → جاهز للاستلام → المتجر يدخل رمز الزبون → تم الاستلام.
 */
class Pickup
{
    public static function enabled(): bool
    {
        return (bool) Options::get('pickup.enabled');
    }

    /** طرق الدفع المسموحة للاستلام — card دائماً (لو فيه بوابة)، wallet حسب الإعداد */
    public static function paymentMethods(): array
    {
        $methods = [];
        if (PaymentGateway::availableFor('order')->isNotEmpty()) {
            $methods[] = 'card';
        }
        if (Options::get('pickup.allow_wallet')) {
            $methods[] = 'wallet';
        }

        return $methods;
    }

    public static function availableAt(Store $store): bool
    {
        return self::enabled() && (bool) ($store->pickup_enabled ?? true) && self::paymentMethods() !== [];
    }

    /** يرفض الطلب لو الاستلام مش متاح أو طريقة الدفع مش إلكترونية */
    public static function validate(Store $store, array $data): void
    {
        if (! self::availableAt($store)) {
            throw ValidationException::withMessages(['fulfillment' => 'الاستلام من المطعم مش متاح في المتجر هذا.']);
        }

        $method = $data['payment_method'] ?? null;
        if (! in_array($method, self::paymentMethods(), true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'الاستلام من المطعم بالدفع الإلكتروني بس'
                    .(in_array('wallet', self::paymentMethods(), true) ? ' (بطاقة أو المحفظة).' : ' (بطاقة).'),
            ]);
        }
    }

    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public static function is(Order $order): bool
    {
        return ($order->fulfillment ?? 'delivery') === 'pickup';
    }
}
