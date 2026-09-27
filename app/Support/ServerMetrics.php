<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\DriverLocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قراءات حالة السيرفر — كلها من /proc و قاعدة البيانات، بدون أدوات خارجية.
 * أي قراءة تفشل ترجع null (مثلاً على ويندوز محلياً) والصفحة تكتب «غير متاح».
 */
class ServerMetrics
{
    // ===== النظام =====

    public static function cores(): int
    {
        $c = @file_get_contents('/proc/cpuinfo');
        $n = $c ? preg_match_all('/^processor\s*:/m', $c) : 0;

        return max(1, $n ?: (int) (getenv('NUMBER_OF_PROCESSORS') ?: 1));
    }

    /** [1, 5, 15] دقيقة */
    public static function load(): ?array
    {
        $l = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return $l ? array_map(fn ($v) => round($v, 2), $l) : null;
    }

    /** نسبة استعمال المعالج توّا (عيّنتين بينهم 200ms) */
    public static function cpuPercent(): ?float
    {
        $read = function () {
            $l = @file('/proc/stat');
            if (! $l) {
                return null;
            }
            $p = preg_split('/\s+/', trim($l[0]));
            $v = array_map('intval', array_slice($p, 1));
            $idle = ($v[3] ?? 0) + ($v[4] ?? 0);

            return [array_sum($v), $idle];
        };
        $a = $read();
        if (! $a) {
            return null;
        }
        usleep(200000);
        $b = $read();
        $total = $b[0] - $a[0];

        return $total > 0 ? round(100 * (1 - ($b[1] - $a[1]) / $total), 1) : null;
    }

    /** بالميجا: total, used, percent, swap_used */
    public static function memory(): ?array
    {
        $c = @file_get_contents('/proc/meminfo');
        if (! $c) {
            return null;
        }
        preg_match_all('/^(\w+):\s+(\d+)/m', $c, $m);
        $i = array_combine($m[1], array_map('intval', $m[2]));
        $total = ($i['MemTotal'] ?? 0) / 1024;
        $avail = ($i['MemAvailable'] ?? $i['MemFree'] ?? 0) / 1024;
        if ($total <= 0) {
            return null;
        }

        return [
            'total' => round($total),
            'used' => round($total - $avail),
            'percent' => round(100 * ($total - $avail) / $total, 1),
            'swap_used' => round((($i['SwapTotal'] ?? 0) - ($i['SwapFree'] ?? 0)) / 1024),
        ];
    }

    /** بالجيجا */
    public static function disk(): ?array
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (! $total) {
            return null;
        }

        return [
            'total' => round($total / 1073741824, 1),
            'used' => round(($total - $free) / 1073741824, 1),
            'percent' => round(100 * ($total - $free) / $total, 1),
        ];
    }

    public static function uptime(): ?string
    {
        $u = @file_get_contents('/proc/uptime');
        if (! $u) {
            return null;
        }
        $s = (int) explode(' ', $u)[0];
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);

        return ($d ? "$d يوم و " : '')."$h ساعة";
    }

    /** العمليات الأكثر استهلاكاً (من /proc — بدون exec) */
    public static function processes(int $limit = 8): array
    {
        $dirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [];
        $groups = [];
        $pageKb = 4;
        foreach ($dirs as $d) {
            $comm = trim((string) @file_get_contents("$d/comm"));
            $statm = @file_get_contents("$d/statm");
            $stat = @file_get_contents("$d/stat");
            if ($comm === '' || ! $statm || ! $stat) {
                continue;
            }
            $rssMb = ((int) (explode(' ', $statm)[1] ?? 0)) * $pageKb / 1024;
            // الوقت الكلي على المعالج (utime + stime) من بداية العملية
            $after = substr($stat, strrpos($stat, ')') + 2);
            $f = explode(' ', $after);
            $cpu = (int) ($f[11] ?? 0) + (int) ($f[12] ?? 0);

            $key = preg_replace('/[:\d]+$/', '', $comm) ?: $comm;
            $groups[$key] ??= ['name' => $key, 'count' => 0, 'mem' => 0.0, 'cpu_ticks' => 0];
            $groups[$key]['count']++;
            $groups[$key]['mem'] += $rssMb;
            $groups[$key]['cpu_ticks'] += $cpu;
        }
        usort($groups, fn ($a, $b) => $b['mem'] <=> $a['mem']);

        return array_map(fn ($g) => ['name' => $g['name'], 'count' => $g['count'], 'mem' => round($g['mem'])],
            array_slice($groups, 0, $limit));
    }

    // ===== قاعدة البيانات والطوابير =====

    public static function database(): array
    {
        $out = ['driver' => DB::getDriverName(), 'size_mb' => null, 'connections' => null, 'slow_queries' => null, 'ping_ms' => null];
        try {
            $t = microtime(true);
            DB::select('select 1');
            $out['ping_ms'] = round((microtime(true) - $t) * 1000, 1);

            if (in_array($out['driver'], ['mysql', 'mariadb'])) {
                $row = DB::selectOne('select sum(data_length + index_length) as s from information_schema.tables where table_schema = database()');
                $out['size_mb'] = round(((float) ($row->s ?? 0)) / 1048576, 1);
                $st = collect(DB::select("show global status where Variable_name in ('Threads_connected','Slow_queries')"))
                    ->pluck('Value', 'Variable_name');
                $out['connections'] = (int) ($st['Threads_connected'] ?? 0);
                $out['slow_queries'] = (int) ($st['Slow_queries'] ?? 0);
            } elseif ($out['driver'] === 'pgsql') {
                $out['size_mb'] = round(((float) DB::selectOne('select pg_database_size(current_database()) as s')->s) / 1048576, 1);
                $out['connections'] = (int) DB::selectOne('select count(*) as c from pg_stat_activity')->c;
            } elseif ($out['driver'] === 'sqlite') {
                $f = DB::connection()->getConfig('database');
                $out['size_mb'] = is_file($f) ? round(filesize($f) / 1048576, 1) : null;
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /** الكبار في قاعدة البيانات (MySQL بس) */
    public static function biggestTables(int $limit = 6): array
    {
        try {
            if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                return [];
            }

            return collect(DB::select('select table_name as t, table_rows as r, round((data_length + index_length)/1048576, 1) as mb
                from information_schema.tables where table_schema = database() order by (data_length + index_length) desc limit '.(int) $limit))
                ->map(fn ($x) => ['table' => $x->t, 'rows' => (int) $x->r, 'mb' => (float) $x->mb])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function queue(): array
    {
        $out = ['connection' => config('queue.default'), 'pending' => null, 'failed' => null, 'failed_last_hour' => null, 'oldest_min' => null];
        try {
            if (Schema::hasTable('jobs') && config('queue.default') === 'database') {
                $out['pending'] = DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('created_at');
                $out['oldest_min'] = $oldest ? (int) round((time() - (int) $oldest) / 60) : 0;
            }
            if (Schema::hasTable('failed_jobs')) {
                $out['failed'] = DB::table('failed_jobs')->count();
                $out['failed_last_hour'] = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    // ===== المنصة =====

    public static function platform(): array
    {
        $out = ['active_orders' => null, 'orders_last_hour' => null, 'online_drivers' => null];
        try {
            $out['active_orders'] = Order::whereIn('status', OrderStatus::active())->count();
            $out['orders_last_hour'] = Order::where('created_at', '>=', now()->subHour())->count();
            $out['online_drivers'] = count(DriverLocationService::onlineDrivers());
        } catch (\Throwable) {
        }

        return $out;
    }

    /** آخر أخطاء من laravel.log (آخر 200KB بس) */
    public static function recentErrors(int $limit = 8): array
    {
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            // daily logs
            $files = glob(storage_path('logs/laravel-*.log')) ?: [];
            rsort($files);
            $file = $files[0] ?? null;
        }
        if (! $file || ! is_file($file)) {
            return [];
        }
        $size = filesize($file);
        $h = fopen($file, 'r');
        fseek($h, max(0, $size - 204800));
        $chunk = stream_get_contents($h);
        fclose($h);

        preg_match_all('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\]\s+\w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.{0,220})/m', $chunk, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => ['at' => $x[1], 'level' => $x[2], 'message' => trim($x[3])],
            array_slice(array_reverse($m), 0, $limit));
    }

    // ===== حركة الطلبات (من Traffic) =====

    public static function traffic(int $minutes = 15): array
    {
        $rows = Traffic::recent($minutes);
        $slowMs = (int) (rescue(fn () => Options::get('server.slow_ms'), 1500, false) ?: 1500);
        $n = count($rows);

        $perMinute = [];
        $now = time();
        for ($i = $minutes - 1; $i >= 0; $i--) {
            $perMinute[date('H:i', $now - $i * 60)] = 0;
        }
        $routes = $ips = $apps = $users = [];
        $errors = 0;
        $client = 0;
        $ms = [];
        $slow = [];

        foreach ($rows as $r) {
            $k = date('H:i', $r['t']);
            if (isset($perMinute[$k])) {
                $perMinute[$k]++;
            }
            $ms[] = $r['ms'];
            $r['status'] >= 500 && $errors++;
            $r['status'] === 429 && $client++;

            $rk = $r['method'].' '.$r['route'];
            $routes[$rk] ??= ['route' => $rk, 'count' => 0, 'total_ms' => 0, 'max_ms' => 0, 'errors' => 0];
            $routes[$rk]['count']++;
            $routes[$rk]['total_ms'] += $r['ms'];
            $routes[$rk]['max_ms'] = max($routes[$rk]['max_ms'], $r['ms']);
            $r['status'] >= 500 && $routes[$rk]['errors']++;

            $ips[$r['ip']] ??= ['ip' => $r['ip'], 'count' => 0, 'apps' => [], 'users' => [], 'blocked' => 0];
            $ips[$r['ip']]['count']++;
            $ips[$r['ip']]['apps'][$r['app']] = true;
            $r['user'] !== '' && $ips[$r['ip']]['users'][$r['user']] = true;
            $r['status'] === 429 && $ips[$r['ip']]['blocked']++;

            $apps[$r['app']] = ($apps[$r['app']] ?? 0) + 1;
            $r['user'] !== '' && $users[$r['user']] = true;

            if ($r['ms'] >= $slowMs) {
                $slow[] = $r;
            }
        }

        sort($ms);
        $p95 = $n ? $ms[(int) floor(0.95 * ($n - 1))] : 0;

        usort($routes, fn ($a, $b) => $b['count'] <=> $a['count']);
        usort($ips, fn ($a, $b) => $b['count'] <=> $a['count']);
        usort($slow, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        arsort($apps);

        return [
            'minutes' => $minutes,
            'total' => $n,
            'per_min' => $minutes ? round($n / $minutes, 1) : 0,
            'per_minute' => $perMinute,
            'avg_ms' => $n ? (int) round(array_sum($ms) / $n) : 0,
            'p95_ms' => $p95,
            'errors' => $errors,
            'rate_limited' => $client,
            'error_rate' => $n ? round(100 * $errors / $n, 1) : 0,
            'active_users' => count($users),
            'apps' => $apps,
            'routes' => array_map(fn ($r) => $r + ['avg_ms' => (int) round($r['total_ms'] / max(1, $r['count']))], array_slice($routes, 0, 12)),
            'ips' => array_map(fn ($r) => ['ip' => $r['ip'], 'count' => $r['count'], 'apps' => implode('، ', array_keys($r['apps'])),
                'users' => count($r['users']), 'blocked' => $r['blocked']], array_slice($ips, 0, 10)),
            'slow' => array_slice($slow, 0, 10),
            'slow_ms' => $slowMs,
        ];
    }

    // ===== الحكم العام =====

    /** @return array{level: string, label: string, reasons: list<string>} level: ok | warn | high */
    public static function verdict(?array $load, int $cores, ?float $cpu, ?array $mem, ?array $disk, array $traffic, array $queue): array
    {
        $reasons = [];
        $score = 0;
        $flag = function (int $lvl, string $why) use (&$score, &$reasons) {
            $score = max($score, $lvl);
            $reasons[] = $why;
        };

        if ($load) {
            $ratio = $load[0] / $cores;
            $ratio >= 1.5 ? $flag(2, "حمل المعالج عالي ({$load[0]} على $cores نواة)")
                : ($ratio >= 0.8 && $flag(1, "حمل المعالج مرتفع ({$load[0]} على $cores نواة)"));
        }
        if ($cpu !== null && $cpu >= 90) {
            $flag(2, "المعالج مشغول {$cpu}%");
        }
        if ($mem) {
            $mem['percent'] >= 92 ? $flag(2, "الذاكرة شبه ممتلئة ({$mem['percent']}%)")
                : ($mem['percent'] >= 80 && $flag(1, "الذاكرة {$mem['percent']}%"));
        }
        if ($disk) {
            $disk['percent'] >= 95 ? $flag(2, "المساحة شبه ممتلئة ({$disk['percent']}%)")
                : ($disk['percent'] >= 85 && $flag(1, "المساحة {$disk['percent']}%"));
        }
        if ($traffic['total'] >= 20) {
            $traffic['error_rate'] >= 5 ? $flag(2, "أخطاء السيرفر {$traffic['error_rate']}% من الطلبات")
                : ($traffic['error_rate'] >= 1 && $flag(1, "أخطاء السيرفر {$traffic['error_rate']}%"));
            $traffic['p95_ms'] >= 3000 ? $flag(2, "الرد بطيء (95% تحت {$traffic['p95_ms']}ms)")
                : ($traffic['p95_ms'] >= 1200 && $flag(1, "الرد أبطأ من العادة ({$traffic['p95_ms']}ms)"));
        }
        if (($queue['oldest_min'] ?? 0) >= 10) {
            $flag(1, "مهام متأخرة في الطابور ({$queue['oldest_min']} دقيقة) — تأكد إن queue:work شغّال");
        }
        if (($queue['failed_last_hour'] ?? 0) > 0) {
            $flag(1, "{$queue['failed_last_hour']} مهمة فشلت آخر ساعة");
        }

        return [
            'level' => ['ok', 'warn', 'high'][$score],
            'label' => ['طبيعي', 'ضغط متوسط', 'ضغط عالي'][$score],
            'reasons' => $reasons,
        ];
    }

    /** كل شي مع بعض — للصفحة وللتنبيه */
    public static function snapshot(int $minutes = 15): array
    {
        $cores = self::cores();
        $load = self::load();
        $cpu = self::cpuPercent();
        $mem = self::memory();
        $disk = self::disk();
        $traffic = self::traffic($minutes);
        $queue = self::queue();

        return [
            'at' => now()->format('H:i:s'),
            'cores' => $cores,
            'load' => $load,
            'cpu' => $cpu,
            'memory' => $mem,
            'disk' => $disk,
            'uptime' => self::uptime(),
            'php' => PHP_VERSION,
            'database' => self::database(),
            'queue' => $queue,
            'platform' => self::platform(),
            'traffic' => $traffic,
            'verdict' => self::verdict($load, $cores, $cpu, $mem, $disk, $traffic, $queue),
        ];
    }
}
