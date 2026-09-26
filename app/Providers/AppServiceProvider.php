<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Number::useLocale('en');

        // لوحة التحكم تعرض وتستقبل الأوقات بتوقيت ليبيا — والتخزين يقعد UTC
        \Filament\Support\Facades\FilamentTimezone::set(config('app.local_timezone', 'Africa/Tripoli'));
    }
}
