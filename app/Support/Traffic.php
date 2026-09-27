<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * سجل خفيف لكل طلب يوصل للسيرفر (للوحة «حالة السيرفر»).
 *
 * ملف نصي لكل دقيقة في storage/app/traffic — سطر لكل طلب، بدون قاعدة بيانات،
 * باش التسجيل نفسه ما يزيدش الضغط. الملفات الأقدم من المدة المحددة تنمسح تلقائياً.
 *
 * السطر: وقت \t مدة(ms) \t حالة \t طريقة \t مسار \t IP \t تطبيق \t مستخدم
 */
class Traffic
{
    public static function dir(): string
    {
        return storage_path('app/traffic');
    }

    public static function enabled(): bool
    {
        try {
            return (bool) Options::get('server.traffic_enabled');
        } catch (\Throwable) {
            return true;
        }
    }

    public static function record(Request $request, Response $response): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $start = defined('LARAVEL_START') ? LARAVEL_START : ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true));
            $ms = (int) round((microtime(true) - $start) * 1000);
            $route = $request->route()?->uri() ?? $request->path();
            if ($request->is('livewire*')) {
                $route = 'livewire (لوحة التحكم)';
            }

            $line = implode("\t", [
                time(),
                $ms,
                $response->getStatusCode(),
                $request->method(),
                str_replace(["\t", "\n"], ' ', mb_substr('/'.ltrim($route, '/'), 0, 120)),
                $request->ip(),
                Activity::currentApp($request),
                $request->user()?->id ?? auth('web')->id() ?? '',
            ])."\n";

            $dir = self::dir();
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($dir.'/'.date('Ymd-Hi').'.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // ما يطيّحش أي طلب
        }
    }

    /**
     * الطلبات في آخر N دقيقة.
     *
     * @return list<array{t:int, ms:int, status:int, method:string, route:string, ip:string, app:string, user:string}>
     */
    public static function recent(int $minutes = 15, int $limit = 200000): array
    {
        $rows = [];
        $now = time();
        for ($i = $minutes; $i >= 0; $i--) {
            $file = self::dir().'/'.date('Ymd-Hi', $now - $i * 60).'.log';
            if (! is_file($file)) {
                continue;
            }
            $h = @fopen($file, 'r');
            if (! $h) {
                continue;
            }
            while (($l = fgets($h)) !== false) {
                $p = explode("\t", rtrim($l, "\n"));
                if (count($p) < 8 || (int) $p[0] < $now - $minutes * 60) {
                    continue;
                }
                $rows[] = ['t' => (int) $p[0], 'ms' => (int) $p[1], 'status' => (int) $p[2], 'method' => $p[3],
                    'route' => $p[4], 'ip' => $p[5], 'app' => $p[6], 'user' => $p[7]];
                if (count($rows) >= $limit) {
                    break 2;
                }
            }
            fclose($h);
        }

        return $rows;
    }

    /** يمسح الملفات الأقدم من المدة */
    public static function prune(): int
    {
        $hours = max(1, (int) (rescue(fn () => Options::get('server.traffic_hours'), 24, false) ?: 24));
        $cutoff = time() - $hours * 3600;
        $n = 0;
        foreach (File::glob(self::dir().'/*.log') as $f) {
            if (@filemtime($f) < $cutoff && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }
}
