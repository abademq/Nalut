<?php

namespace App\Console\Commands;

use App\Filament\Pages\ServerStatus;
use App\Services\AdminAlerts;
use App\Support\Options;
use App\Support\ServerMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** يتفحص السيرفر كل 5 دقايق — لو ضغط عالي مرتين ورا بعض، تنبيه في اللوحة (مرة في الساعة كحد أقصى) */
class ServerCheck extends Command
{
    protected $signature = 'server:check';

    protected $description = 'Alert admins when the server is under high load';

    public function handle(): int
    {
        $this->checkOffsiteBackup();

        $s = ServerMetrics::snapshot(5);
        $v = $s['verdict'];
        $this->line($v['label'].($v['reasons'] ? ': '.implode(' | ', $v['reasons']) : ''));

        if ($v['level'] !== 'high') {
            Cache::forget('server:high-streak');

            return self::SUCCESS;
        }

        $streak = (int) Cache::get('server:high-streak', 0) + 1;
        Cache::put('server:high-streak', $streak, now()->addMinutes(30));

        if ($streak >= 2 && (bool) rescue(fn () => Options::get('server.alert_enabled'), true, false)) {
            AdminAlerts::send('⚠️ السيرفر تحت ضغط عالي', implode(' · ', $v['reasons']),
                rescue(fn () => ServerStatus::getUrl(), null, false), 'danger', 'server-high:'.date('YmdH'));
        }

        return self::SUCCESS;
    }

    /**
     * النسخ الاحتياطي برّا السيرفر (deploy/offsite-backup.sh يكتب الحالة كل ليلة):
     * فشلت أو فاتت 30 ساعة بدون نسخة → تنبيه للإدارة (مرة كل 6 ساعات).
     */
    private function checkOffsiteBackup(): void
    {
        $file = storage_path('app/backup-status.json');
        if (! is_file($file)) {
            return; // ما تضبطش لين توّا
        }
        $st = json_decode((string) @file_get_contents($file), true) ?: [];
        $at = isset($st['at']) ? Carbon::parse($st['at']) : null;
        $problem = match (true) {
            ($st['ok'] ?? false) !== true => 'آخر محاولة فشلت: '.($st['message'] ?? '—'),
            ! $at || $at->lt(now()->subHours(30)) => 'ما فيش نسخة جديدة من '.($at?->diffForHumans() ?? 'مدة'),
            default => null,
        };
        if ($problem) {
            AdminAlerts::send('⚠️ النسخ الاحتياطي برّا السيرفر واقف', $problem.' — شوف /var/log/nalut-offsite.log',
                null, 'danger', 'backup-offsite:'.intdiv((int) date('G'), 6).date('Ymd'), 'settings.manage', 'server');
        }
    }
}
