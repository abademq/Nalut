<?php

namespace App\Console\Commands;

use App\Models\ReportSubscription;
use Illuminate\Console\Command;

class SendDueReports extends Command
{
    protected $signature = 'reports:send-due';

    protected $description = 'إرسال التقارير الدورية اللي جا وقتها';

    public function handle(): int
    {
        ReportSubscription::with(['store.owner', 'template'])
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->each(function (ReportSubscription $s) {
                $log = $s->sendNow();
                $this->line("{$s->name}: {$log->status}");
            });

        return self::SUCCESS;
    }
}
