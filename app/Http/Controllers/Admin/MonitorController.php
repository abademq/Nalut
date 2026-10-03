<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AdminTwoFactor;
use App\Support\Analytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * شاشة المراقبة للعرض على شاشة كبيرة: /monitor
 *
 * الدخول: إداري مسجّل (صلاحية الطلبات)، أو رابط الشاشة ?key=… (للتلفزيون — بدون تسجيل دخول،
 * للعرض بس). الرابط يتبدّل من صفحة «شاشة المراقبة» في اللوحة لو تسرّب.
 */
class MonitorController extends Controller
{
    public static function key(): string
    {
        $key = (string) Setting::get('monitor.key', '');
        if ($key === '') {
            $key = Str::random(40);
            Setting::put('monitor.key', $key);
        }

        return $key;
    }

    public static function rotateKey(): string
    {
        Setting::put('monitor.key', Str::random(40));

        return self::key();
    }

    private function allowed(Request $request): bool
    {
        $key = (string) $request->query('key', '');
        if ($key !== '' && hash_equals(self::key(), $key)) {
            return true;
        }

        $user = $request->user();

        return $user && $user->hasPermission('orders.view')
            && (! AdminTwoFactor::enabled() || AdminTwoFactor::passedInSession($user));
    }

    public function page(Request $request): Response
    {
        abort_unless($this->allowed($request), 403, 'رابط الشاشة غير صحيح أو انتهى — خذ الرابط الجديد من لوحة التحكم.');

        return response()->view('monitor', [
            'dataUrl' => url('monitor/data').($request->query('key') ? '?key='.urlencode((string) $request->query('key')) : ''),
            'seconds' => 5,
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($this->allowed($request), 403);

        return response()->json(Analytics::monitor());
    }
}
