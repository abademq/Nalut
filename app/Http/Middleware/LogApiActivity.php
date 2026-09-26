<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\User;
use App\Support\Activity;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * كل عملية من التطبيقات (إضافة، تعديل، طلب، دخول...) = سطر في سجل النشاط،
 * ومعاه البيانات اللي بعثها التطبيق والتغييرات اللي صارت والنتيجة.
 */
class LogApiActivity
{
    /** طلبات متكررة بلا قيمة في السجل */
    private const SKIP = [
        'POST api/v1/driver/location',
        'POST api/v1/orders/quote',
        'POST api/v1/activity',
    ];

    /** اسم العملية بالعربي */
    private const LABELS = [
        'POST api/v1/auth/otp'                           => 'طلب رمز تحقق',
        'POST api/v1/auth/verify'                        => 'دخول/تسجيل برمز التحقق',
        'POST api/v1/auth/login'                         => 'تسجيل دخول بكلمة المرور',
        'POST api/v1/logout'                             => 'تسجيل خروج',
        'PUT api/v1/me'                                  => 'تعديل الحساب',
        'POST api/v1/wallet/redeem'                      => 'شحن المحفظة بكرت',
        'POST api/v1/payments/otp/send'                  => 'دفع إلكتروني: طلب رمز',
        'POST api/v1/payments/otp/confirm'               => 'دفع إلكتروني: تأكيد',
        'POST api/v1/payments/checkout'                  => 'دفع إلكتروني',
        'POST api/v1/addresses'                          => 'إضافة عنوان',
        'PUT api/v1/addresses/{address}'                 => 'تعديل عنوان',
        'PATCH api/v1/addresses/{address}'               => 'تعديل عنوان',
        'DELETE api/v1/addresses/{address}'              => 'حذف عنوان',
        'POST api/v1/addresses/{address}/default'        => 'تعيين عنوان افتراضي',
        'POST api/v1/orders'                             => 'طلب جديد',
        'POST api/v1/orders/{order}/cancel'              => 'إلغاء طلب',
        'POST api/v1/orders/{order}/rate'                => 'تقييم طلب',
        'POST api/v1/orders/{order}/reorder'             => 'إعادة طلب',
        'POST api/v1/orders/{order}/substitution'        => 'رد على صنف غير متوفر',
        'POST api/v1/favorites/toggle'                   => 'المفضلة',
        'POST api/v1/points/convert'                     => 'تحويل نقاط للمحفظة',
        'POST api/v1/saved-carts'                        => 'حفظ سلة',
        'DELETE api/v1/saved-carts/{savedCart}'          => 'حذف سلة محفوظة',
        'POST api/v1/store/orders/{order}/status'        => 'المتجر غيّر حالة طلب',
        'POST api/v1/store/orders/{order}/unavailable-items' => 'المتجر علّم أصناف غير متوفرة',
        'POST api/v1/store/products'                     => 'إضافة منتج',
        'POST api/v1/store/products/{product}'           => 'تعديل منتج',
        'DELETE api/v1/store/products/{product}'         => 'حذف منتج',
        'POST api/v1/store/products/{product}/toggle'    => 'تفعيل/إيقاف منتج',
        'POST api/v1/store/sections'                     => 'إضافة قسم قائمة',
        'POST api/v1/store/sections/reorder'             => 'ترتيب أقسام القائمة',
        'POST api/v1/store/sections/{id}'                => 'تعديل قسم قائمة',
        'DELETE api/v1/store/sections/{id}'              => 'حذف قسم قائمة',
        'POST api/v1/store/toggle-open'                  => 'فتح/إغلاق المتجر',
        'POST api/v1/driver/online'                      => 'السائق متاح/غير متاح',
        'POST api/v1/driver/zones'                       => 'السائق غيّر مناطق العمل',
        'POST api/v1/driver/orders/{order}/accept'       => 'السائق قبل طلب',
        'POST api/v1/driver/orders/{order}/status'       => 'السائق غيّر حالة طلب',
        'POST api/v1/driver/orders/{order}/issue'        => 'السائق بلّغ عن مشكلة',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->key($request);

        // القراءة (GET) ما تنسجلش هني — التطبيق يبعث «فتح متجر/صنف» كأحداث
        if ($request->isMethodSafe() || in_array($key, self::SKIP, true)) {
            return $next($request);
        }

        Activity::beginRequest();

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->write($request, $key, null, Activity::endRequest() ?? [], $e);
            throw $e;
        }

        $this->write($request, $key, $response, Activity::endRequest() ?? []);

        return $response;
    }

    private function key(Request $request): string
    {
        $uri = $request->route()?->uri() ?? $request->path();

        return $request->method().' '.$uri;
    }

    private function write(Request $request, string $key, ?Response $response, array $collected, ?\Throwable $e = null): void
    {
        $status = $response?->getStatusCode() ?? 500;
        $label = self::LABELS[$key] ?? $key;

        $json = null;
        if ($response && str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $json = json_decode((string) $response->getContent(), true);
        }

        // الدخول: المستخدم يتعرف من الرد
        $user = $request->user();
        if (! $user && is_array($json) && isset($json['user']['id'])) {
            $user = User::find($json['user']['id']);
        }

        $subject = collect($request->route()?->parameters() ?? [])->first(fn ($p) => $p instanceof Model);
        $orderId = $subject instanceof Order ? $subject->id : ($collected['order_id'] ?? null);
        if ($key === 'POST api/v1/orders' && is_array($json)) {
            $orderId = $json['data']['id'] ?? $json['id'] ?? $orderId;
        }

        $desc = $label;
        if ($subject instanceof Order) {
            $desc .= ' '.$subject->code;
        } elseif ($orderId && ($o = Order::find($orderId))) {
            $desc .= ' '.$o->code;
        } elseif ($subject && ($n = $subject->getAttribute('name'))) {
            $desc .= ': '.$n;
        }
        if ($request->filled('status')) {
            $desc .= ' ← '.$request->input('status');
        }
        if ($status >= 400) {
            $desc .= ' (فشل)';
        }

        $props = array_filter([
            'request' => Activity::clean($request->except(['_token'])) ?: null,
            'files'   => collect($request->allFiles())->flatten()->map(fn ($f) => $f->getClientOriginalName())->values()->all() ?: null,
            'changes' => $collected['changes'] ?? null ?: null,
            'error'   => $status >= 400
                ? ($e?->getMessage() ?: (is_array($json) ? ($json['message'] ?? null) : null))
                : null,
            'errors'  => $status === 422 && is_array($json) ? ($json['errors'] ?? null) : null,
            'endpoint' => $key,
        ], fn ($v) => $v !== null && $v !== []);

        Activity::record(
            action: 'api.'.strtolower(str_replace(['api/v1/', '/', '{', '}', ' '], ['', '.', '', '', '.'], $key)),
            description: $desc,
            subject: $subject,
            properties: $props,
            user: $user,
            orderId: $orderId,
            storeId: $collected['store_id'] ?? null,
            status: $status,
        );
    }
}
