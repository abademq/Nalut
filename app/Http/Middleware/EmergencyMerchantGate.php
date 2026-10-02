<?php

namespace App\Http\Middleware;

use App\Support\Emergency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * لوحة المتجر على الموقع: تتقفل مع «قفل تطبيق المتجر».
 * مسجّلة في اللوحة كـ persistent — فتشمل طلبات Livewire (الأزرار) مش بس فتح الصفحة.
 */
class EmergencyMerchantGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Emergency::on('app_store')) {
            return $next($request);
        }

        $message = Emergency::message();

        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => $message], 503);
        }

        return response()->view('emergency', ['message' => $message], 503)->header('Retry-After', '300');
    }
}
