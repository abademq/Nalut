<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** عدد التنبيهات غير المقروءة وآخر واحد — للصوت وإشعار المتصفح في اللوحة */
class AlertsController extends Controller
{
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole(UserRole::Admin), 403);

        $latest = $user->unreadNotifications()->latest()->first();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'latest' => $latest ? [
                'id'    => $latest->id,
                'title' => $latest->data['title'] ?? '',
                'body'  => strip_tags((string) ($latest->data['body'] ?? '')),
            ] : null,
        ]);
    }
}
