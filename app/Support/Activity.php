<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * سجل نشاط المنصة.
 *
 * - طلبات التطبيقات (API): سطر واحد لكل عملية، ومعاه التغييرات اللي صارت في قاعدة البيانات أثناءها.
 * - لوحة التحكم والنظام: كل تغيير في البيانات سطر لحاله (قبل ← بعد).
 * - أحداث داخل التطبيق (إضافة للسلة، فتح متجر...): التطبيق يبعثها لـ /activity.
 */
class Activity
{
    /** طلب API شغّال توّا — التغييرات تتجمّع فيه */
    private static ?array $request = null;

    /** نوقفو التسجيل مؤقتاً (مثلاً أثناء الحذف القديم) */
    private static bool $paused = false;

    /** حقول ما نسجلوهاش أبداً */
    public const SECRET_KEYS = ['password', 'password_confirmation', 'code', 'otp', 'token', 'fcm_token',
        'fcm_tokens', 'remember_token', 'app_hash', 'card_number', 'cvv', 'pin', 'secret'];

    // ===== الواجهة =====

    public static function record(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?User $user = null,
        ?string $app = null,
        ?int $orderId = null,
        ?int $storeId = null,
        ?int $status = null,
        mixed $at = null,
    ): ?ActivityLog {
        if (self::$paused || ! self::enabled()) {
            return null;
        }

        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $user ??= auth()->user();

            [$orderId, $storeId] = self::links($subject, $orderId, $storeId);

            return ActivityLog::create([
                'user_id'      => $user?->id,
                'app'          => $app ?? self::currentApp($request),
                'action'       => Str::limit($action, 58, ''),
                'description'  => Str::limit($description, 250),
                // جداول مفتاحها نص (الإعدادات) ما تنربطش — الاسم في الوصف
                'subject_type' => is_numeric($subject?->getKey()) ? $subject->getMorphClass() : null,
                'subject_id'   => is_numeric($subject?->getKey()) ? $subject->getKey() : null,
                'order_id'     => $orderId,
                'store_id'     => $storeId,
                'properties'   => $properties ?: null,
                'status'       => $status,
                'ip'           => $request?->ip(),
                'device'       => $request ? Str::limit((string) $request->userAgent(), 158, '') : null,
                'created_at'   => $at ?? now(),
            ]);
        } catch (\Throwable $e) {
            // السجل ما يطيّحش أي عملية
            Log::warning('activity log failed: '.$e->getMessage());

            return null;
        }
    }

    public static function enabled(): bool
    {
        try {
            return (bool) Options::get('logs.enabled');
        } catch (\Throwable) {
            return true;
        }
    }

    public static function pause(callable $fn): mixed
    {
        $was = self::$paused;
        self::$paused = true;
        try {
            return $fn();
        } finally {
            self::$paused = $was;
        }
    }

    /** customer | store | driver | admin | system */
    public static function currentApp(?Request $request = null): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'system';
        }

        return self::guessApp($request ?? request());
    }

    private static function guessApp(Request $request): string
    {
        $header = $request->header('X-App');
        if (in_array($header, ['customer', 'store', 'driver'], true)) {
            return $header;
        }

        if ($request->is('admin*', 'livewire*', 'admin-api*')) {
            return 'admin';
        }

        // تطبيقات قديمة بدون X-App: من المسار
        if ($request->is('api/v1/store/*')) {
            return 'store';
        }
        if ($request->is('api/v1/driver/*')) {
            return 'driver';
        }
        if ($request->is('api/*')) {
            return $request->user()?->role?->value === 'driver' ? 'driver'
                : ($request->user()?->role?->value === 'store' ? 'store' : 'customer');
        }

        // مدير داخل (مثلاً تصدير أو صفحة في اللوحة بمسار مختلف)
        if (auth('web')->user()?->hasRole('admin')) {
            return 'admin';
        }

        return 'system';
    }

    // ===== طلبات الـ API =====

    public static function beginRequest(): void
    {
        self::$request = ['changes' => []];
    }

    public static function endRequest(): ?array
    {
        $r = self::$request;
        self::$request = null;

        return $r;
    }

    public static function inRequest(): bool
    {
        return self::$request !== null;
    }

    // ===== تغييرات البيانات (من الـ Observer) =====

    public static function modelChanged(Model $model, string $event, array $changes): void
    {
        if (self::$paused) {
            return;
        }

        $label = self::modelLabel($model);
        $entry = ['model' => $label, 'id' => $model->getKey(), 'event' => $event, 'changes' => $changes];

        // داخل طلب من التطبيق: تتجمّع وتنكتب مع سطر العملية
        if (self::$request !== null) {
            if (count(self::$request['changes']) < 40) {
                self::$request['changes'][] = $entry;
            }
            if (! isset(self::$request['order_id'])) {
                [$o, $s] = self::links($model, null, null);
                self::$request['order_id'] = $o;
                self::$request['store_id'] ??= $s;
            }

            return;
        }

        $verb = ['created' => 'إضافة', 'updated' => 'تعديل', 'deleted' => 'حذف', 'restored' => 'استرجاع'][$event] ?? $event;
        $name = self::modelName($model);

        self::record(
            "model.$event",
            "$verb $label".($name !== '' ? ": $name" : " #{$model->getKey()}"),
            $model,
            ['changes' => $changes],
        );
    }

    /** اسم الجدول بالعربي */
    public static function modelLabel(Model $model): string
    {
        return [
            'Order' => 'طلب', 'OrderItem' => 'صنف في طلب', 'Product' => 'منتج', 'Store' => 'متجر', 'User' => 'مستخدم',
            'Coupon' => 'كوبون', 'Address' => 'عنوان', 'WalletTransaction' => 'حركة محفظة', 'Setting' => 'إعداد',
            'Campaign' => 'حملة', 'MessageTemplate' => 'قالب رسالة', 'ReadyCart' => 'سلة جاهزة', 'SavedCart' => 'سلة محفوظة',
            'Announcement' => 'شريط عروض', 'AppSection' => 'قسم', 'StoreType' => 'نوع متجر', 'DeliveryZone' => 'منطقة توصيل',
            'Banner' => 'إعلان', 'Favorite' => 'مفضلة', 'DriverProfile' => 'ملف سائق', 'MenuSection' => 'قسم قائمة',
            'RechargeCard' => 'كرت شحن', 'FailureReason' => 'سبب تعذّر', 'OrderIssue' => 'مشكلة توصيل',
            'Rating' => 'تقييم', 'PointsTransaction' => 'حركة نقاط', 'ProductOption' => 'خيار منتج',
            'ProductOptionValue' => 'قيمة خيار', 'PaymentGateway' => 'بوابة دفع', 'NotificationSetting' => 'إعداد إشعار',
            'ReportSubscription' => 'تقرير دوري', 'AppText' => 'نص في التطبيق',
        ][class_basename($model)] ?? class_basename($model);
    }

    private static function modelName(Model $model): string
    {
        foreach (['code', 'name', 'title', 'key', 'text'] as $attr) {
            $v = $model->getAttribute($attr);
            if (is_scalar($v) && $v !== '') {
                return Str::limit((string) $v, 60);
            }
        }

        return '';
    }

    /** يربط السطر بالطلب والمتجر (للفلترة) */
    private static function links(?Model $subject, ?int $orderId, ?int $storeId): array
    {
        if ($subject instanceof Order) {
            return [$orderId ?? $subject->getKey(), $storeId ?? $subject->store_id];
        }
        if ($subject) {
            $orderId ??= is_numeric($subject->getAttribute('order_id')) ? (int) $subject->getAttribute('order_id') : null;
            $storeId ??= is_numeric($subject->getAttribute('store_id')) ? (int) $subject->getAttribute('store_id') : null;
            if ($subject instanceof \App\Models\Store) {
                $storeId ??= $subject->getKey();
            }
        }

        return [$orderId, $storeId];
    }

    /** يمسح الأسرار ويقصّر القيم الطويلة */
    public static function clean(mixed $data, int $depth = 0): mixed
    {
        if (! is_array($data)) {
            if (is_string($data)) {
                return Str::limit($data, 300);
            }

            return is_scalar($data) || $data === null ? $data : (is_object($data) && method_exists($data, 'getClientOriginalName')
                ? '[ملف: '.$data->getClientOriginalName().']' : '['.gettype($data).']');
        }

        if ($depth > 3) {
            return '[…]';
        }

        $out = [];
        foreach (array_slice($data, 0, 60, true) as $k => $v) {
            $out[$k] = in_array(strtolower((string) $k), self::SECRET_KEYS, true) ? '•••' : self::clean($v, $depth + 1);
        }

        return $out;
    }

    public static function except(array $attrs, array $keys): array
    {
        return Arr::except($attrs, $keys);
    }
}
