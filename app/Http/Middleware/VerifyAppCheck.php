<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AppCheck;
use App\Support\Recaptcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** يحمي العمليات الحساسة (إرسال رمز التحقق، الدخول، الطلب) من السكربتات */
class VerifyAppCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        // موقع الطلب: ما فيهش App Check — بداله reCAPTCHA على إرسال الرسائل (رمز التحقق)
        if ($request->header('X-Client') === 'web') {
            return $this->web($request, $next);
        }

        $mode = AppCheck::mode();
        if ($mode === 'off') {
            return $next($request);
        }

        // تطبيق المتجر والسائق (حسابات تنشئها الإدارة بس) ما فيهمش App Check —
        // نعفيوهم لو الرقم فعلاً تابع لمتجر/سائق. الأرقام العشوائية (إغراق رسائل) ما تستفيدش.
        if ($this->staffApp($request)) {
            return $next($request);
        }

        $result = AppCheck::check($request->header('X-Firebase-AppCheck'));
        AppCheck::count($result);

        if ($result !== 'ok' && $mode === 'enforce') {
            return response()->json([
                'message' => 'نسخة التطبيق هذي مش موثّقة. حدّث التطبيق من المتجر وعاود.',
                'app_check' => $result,
            ], 403);
        }

        return $next($request);
    }

    private function web(Request $request, Closure $next): Response
    {
        $sendsMessage = $request->is('api/v1/auth/otp', 'api/v1/payments/otp/send');
        if ($sendsMessage && Recaptcha::configured()
            && ! Recaptcha::verify($request->header('X-Recaptcha'), $request->ip())) {
            return response()->json([
                'message' => 'أكّد إنك مش روبوت (علّم على المربع) وعاود.',
                'recaptcha' => true,
            ], 422);
        }

        return $next($request);
    }

    private function staffApp(Request $request): bool
    {
        $app = $request->header('X-App');
        if (! in_array($app, ['store', 'driver'], true)) {
            return false;
        }

        $user = $request->user() ?? (is_string($request->input('phone'))
            ? User::where('phone', $request->input('phone'))->first() : null);

        return $user !== null && $user->hasRole($app);
    }
}
