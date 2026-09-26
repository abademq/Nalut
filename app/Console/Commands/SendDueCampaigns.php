<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use Illuminate\Console\Command;

class SendDueCampaigns extends Command
{
    protected $signature = 'campaigns:send-due';

    protected $description = 'إرسال الحملات المجدولة اللي جا وقتها';

    public function handle(): int
    {
        Campaign::with('template')
            ->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderBy('id')
            ->each(function (Campaign $c) {
                // نحجزوها قبل الإرسال — لو الأمر اشتغل مرتين ما تنبعتش مرتين
                if (Campaign::whereKey($c->id)->where('status', 'queued')->update(['status' => 'sending']) === 0) {
                    return;
                }
                $c->refresh()->run();
                $this->line("{$c->title}: {$c->sent} / {$c->total}");
            });

        return self::SUCCESS;
    }
}
