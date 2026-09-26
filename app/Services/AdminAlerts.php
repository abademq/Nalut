<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Messaging\Messenger;
use App\Support\Options;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * تنبيهات فورية للإدارة: جرس اللوحة + صوت وإشعار في المتصفح،
 * واختيارياً رسالة واتساب/SMS لرقم محدد في «إعدادات التشغيل».
 */
class AdminAlerts
{
    /**
     * @param  string|null  $key  يمنع تكرار نفس التنبيه (مثلاً order-failed:15)
     */
    public static function send(string $title, string $body, ?string $url = null, string $level = 'warning', ?string $key = null): bool
    {
        if ($key && ! Cache::add("admin-alert:$key", 1, now()->addDays(3))) {
            return false; // انبعت من قبل
        }

        try {
            // كل إداري يشوف الطلبات
            $admins = User::withRole(UserRole::Admin)->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->hasPermission('orders.view'));

            if ($admins->isNotEmpty()) {
                $n = Notification::make()
                    ->title($title)
                    ->body($body)
                    ->icon(match ($level) {
                        'danger'  => 'heroicon-o-exclamation-triangle',
                        'info'    => 'heroicon-o-information-circle',
                        default   => 'heroicon-o-bell-alert',
                    })
                    ->iconColor($level);

                if ($url) {
                    $n->actions([Action::make('open')->label('فتح')->url($url)->markAsRead()]);
                }

                $n->sendToDatabase($admins);
            }

            self::viaPhone($title, $body);
        } catch (\Throwable $e) {
            // التنبيه ما يطيّحش العملية الأصلية (إلغاء طلب، بلاغ...)
            Log::error('Admin alert failed', ['title' => $title, 'error' => $e->getMessage()]);
        }

        return true;
    }

    private static function viaPhone(string $title, string $body): void
    {
        $phone = trim((string) Options::get('alerts.phone'));
        if ($phone === '') {
            return;
        }

        $template = MessageTemplate::where('purpose', 'alert')->where('is_active', true)->first();
        if (! $template) {
            return;
        }

        app(Messenger::class)->send($template, $phone, ['title' => $title, 'body' => $body], 'alert');
    }
}
