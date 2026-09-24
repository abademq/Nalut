<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role->value, $roles, true)) {
            return response()->json(['message' => 'غير مصرّح لك بهذا الإجراء.'], 403);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'الحساب موقوف.'], 403);
        }

        return $next($request);
    }
}
