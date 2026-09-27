<?php

namespace App\Console\Commands;

use App\Filament\Pages\ServerStatus;
use App\Services\AdminAlerts;
use App\Support\Options;
use App\Support\ServerMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** يتفحص السيرفر كل 5 دقايق — لو ضغط عالي مرتين ورا بعض، تنبيه في اللوحة (مرة في الساعة كحد أقصى) */
class ServerCheck extends Command
{
    protected $signature = 'server:check';

    protected $description = 'Alert admins when the server is under high load';

    public function handle(): int
    {
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
}
