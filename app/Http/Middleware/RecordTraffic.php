<?php

namespace App\Http\Middleware;

use App\Support\Traffic;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** يسجّل كل طلب بعد ما الرد يوصل للمستخدم (terminate) — لصفحة «حالة السيرفر» */
class RecordTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        Traffic::record($request, $response);
    }
}
