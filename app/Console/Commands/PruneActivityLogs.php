<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Support\Options;
use Illuminate\Console\Command;

/** يمسح السجل الأقدم من المدة المحددة في «إعدادات التشغيل» */
class PruneActivityLogs extends Command
{
    protected $signature = 'activity:prune';

    protected $description = 'Delete activity logs older than the retention period';

    public function handle(): int
    {
        $days = max(7, (int) Options::get('logs.retention_days'));
        $total = 0;

        do {
            $deleted = ActivityLog::where('created_at', '<', now()->subDays($days))->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("deleted $total");

        return self::SUCCESS;
    }
}
