<?php

namespace App\Http\Middleware;

use App\Support\Emergency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بوابة الطوارئ: ترفض الطلبات اللي يوقفها مركز الطوارئ — قبل ما توصل لأي كود.
 *
 * دايماً مفتوح: /app/content (باش التطبيق يعرف إنه مقفول ويوري الرسالة)،
 * الشروط والسياسات، ولوحة التحكم على الموقع (باش الإدارة ترجّع الأمور).
 */
class EmergencyGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // لوحة المتجر (merchant/*) لها EmergencyMerchantGate داخل اللوحة نفسها
        if (! $request->is('api/*', 'merchant-api/*')) {
            return $next($request);
        }

        // ما فيش حتى مفتاح شغّال (الحالة العادية): استعلام كاش واحد
        $active = Emergency::active();
        if (! $active) {
            return $next($request);
        }

        $blocked = $this->blockedBy($request, $active);

        return $blocked ? $this->deny($request, $blocked) : $next($request);
    }

    /** المفتاح اللي يوقف الطلب هذا، أو null */
    private function blockedBy(Request $request, array $active): ?string
    {
        // تنبيهات لوحة المتجر = تطبيق المتجر
        if ($request->is('merchant-api/*')) {
            return in_array('app_store', $active, true) ? 'app_store' : null;
        }

        $path = preg_replace('#^api/v\d+/#', '', $request->path());

        if ($path === 'app/content' || str_starts_with($path, 'legal')) {
            return null;
        }

        // 1) التطبيق كامل مقفول
        $app = $this->appOf($request, $path);
        if (in_array("app_$app", $active, true)) {
            return "app_$app";
        }

        $isWrite = ! $request->isMethodSafe();
        $on = fn (string $s) => in_array($s, $active, true);

        // 2) رفع ملفات
        if ($on('uploads') && $request->allFiles()) {
            return 'uploads';
        }

        // 3) الدخول ورموز التحقق (تطبيقات الزبون والمتجر والسائق — الإدارة لها قفلها)
        if ($on('auth') && in_array($path, ['auth/otp', 'auth/verify', 'auth/login'], true)) {
            return 'auth';
        }

        // 4) الدفع الإلكتروني
        if ($on('payments') && $isWrite && (
            in_array($path, ['payments/checkout', 'payments/otp/send', 'payments/otp/confirm'], true)
            || ($path === 'orders' && $request->input('payment_method') === 'card')
        )) {
            return 'payments';
        }

        // 5) المحافظ
        if ($on('wallet') && $isWrite && (
            in_array($path, ['wallet/redeem', 'points/convert'], true)
            || ($path === 'orders' && $request->input('payment_method') === 'wallet')
        )) {
            return 'wallet';
        }

        // 6) الطلبات الجديدة
        if ($on('orders') && $isWrite && in_array($path, ['orders', 'orders/quote'], true)) {
            return 'orders';
        }

        return null;
    }

    private function appOf(Request $request, string $path): string
    {
        if ($path === 'admin/login' || str_starts_with($path, 'admin/')) {
            return 'admin';
        }
        if (str_starts_with($path, 'store/')) {
            return 'store';
        }
        if (str_starts_with($path, 'driver/')) {
            return 'driver';
        }

        // المسارات المشتركة (المحفظة، الدعم، حسابي...): من رأس X-App
        $header = (string) $request->header('X-App');

        return in_array($header, Emergency::APPS, true) ? $header : 'customer';
    }

    private function deny(Request $request, string $switch): Response
    {
        $message = str_starts_with($switch, 'app_')
            ? Emergency::message()
            : match ($switch) {
                'payments' => 'الدفع الإلكتروني متوقف مؤقتاً. تقدر تكمّل بالدفع نقداً عند الاستلام.',
                'wallet' => 'المحفظة متوقفة مؤقتاً. رصيدك محفوظ وما يتأثرش.',
                'orders' => 'استقبال الطلبات متوقف مؤقتاً. '.Emergency::message(),
                'auth' => 'تسجيل الدخول متوقف مؤقتاً. حاول بعد شوية.',
                'uploads' => 'رفع الصور متوقف مؤقتاً.',
                default => Emergency::message(),
            };

        return response()->json([
            'message' => $message,
            'emergency' => ['switch' => $switch, 'locked' => str_starts_with($switch, 'app_'), 'message' => $message],
        ], 503)->header('Retry-After', '300');
    }
}
