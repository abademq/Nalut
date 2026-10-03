<?php

namespace App\Http\Middleware;

use App\Support\AdminTwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** لوحة التحكم: لو التحقق بخطوتين مفعّل والموظف ما دخلش الرمز — لصفحة الرمز */
class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! AdminTwoFactor::enabled() || AdminTwoFactor::passedInSession($user)) {
            return $next($request);
        }

        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return response()->json(['message' => 'لازم رمز التحقق — حدّث الصفحة.'], 403);
        }

        session(['url.intended' => $request->fullUrl()]);

        return redirect()->route('admin.2fa');
    }
}
