<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Schedule::command('orders:notify-ready')->everyMinute();

// طلبات البطاقة اللي ما اندفعتش خلال المهلة تنلغى
Schedule::command('orders:cancel-unpaid')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
