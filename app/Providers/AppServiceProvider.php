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

        // سجل النشاط: كل إضافة/تعديل/حذف في الجداول المهمة
        foreach ([
            \App\Models\Order::class, \App\Models\OrderItem::class, \App\Models\Product::class, \App\Models\Store::class,
            \App\Models\User::class, \App\Models\Coupon::class, \App\Models\Address::class, \App\Models\WalletTransaction::class,
            \App\Models\Setting::class, \App\Models\Campaign::class, \App\Models\MessageTemplate::class, \App\Models\ReadyCart::class,
            \App\Models\SavedCart::class, \App\Models\Announcement::class, \App\Models\AppSection::class, \App\Models\StoreType::class,
            \App\Models\DeliveryZone::class, \App\Models\Banner::class, \App\Models\Favorite::class, \App\Models\DriverProfile::class,
            \App\Models\MenuSection::class, \App\Models\RechargeCard::class, \App\Models\FailureReason::class, \App\Models\OrderIssue::class,
            \App\Models\Rating::class, \App\Models\PointsTransaction::class, \App\Models\ProductOption::class,
            \App\Models\ProductOptionValue::class, \App\Models\PaymentGateway::class, \App\Models\NotificationSetting::class,
            \App\Models\ReportSubscription::class, \App\Models\AppText::class, \App\Models\PaymentTransaction::class,
        ] as $model) {
            $model::observe(\App\Observers\ActivityObserver::class);
        }

        // دخول وخروج لوحة التحكم
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($e) {
            if ($e->guard === 'web') {
                \App\Support\Activity::record('admin.login', 'دخول لوحة التحكم', user: $e->user, app: 'admin');
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function ($e) {
            if ($e->guard === 'web' && $e->user) {
                \App\Support\Activity::record('admin.logout', 'خروج من لوحة التحكم', user: $e->user, app: 'admin');
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($e) {
            if ($e->guard === 'web') {
                \App\Support\Activity::record('admin.login_failed', 'محاولة دخول فاشلة للوحة: '.($e->credentials['email'] ?? ''),
                    user: $e->user, app: 'admin', status: 401);
            }
        });
    }
}
