<?php

namespace App\Console\Commands;

use App\Support\Texts;
use Illuminate\Console\Command;

/** يزامن نصوص التطبيقات والإشعارات مع قاعدة البيانات — يشتغل مع كل deploy */
class SyncTexts extends Command
{
    protected $signature = 'texts:sync';

    protected $description = 'مزامنة النصوص القابلة للتعديل مع الكود';

    public function handle(): int
    {
        foreach (Texts::sync() as $app => $count) {
            $this->line("$app: $count");
        }

        return self::SUCCESS;
    }
}
