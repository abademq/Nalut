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
    public static function send(string $title, string $body, ?string $url = null, string $level = 'warning', ?string $key = null, string $permission = 'orders.view'): bool
    {
        if ($key && ! Cache::add("admin-alert:$key", 1, now()->addDays(3))) {
            return false; // انبعت من قبل
        }

        try {
            // كل إداري عنده الصلاحية (الطلبات افتراضياً، الدعم للتذاكر)
            $admins = User::withRole(UserRole::Admin)->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->hasPermission($permission));

            if ($admins->isNotEmpty()) {
                $n = Notification::make()
                    ->title($title)
                    ->body($body)
                    ->icon(match ($level) {
                        'danger' => 'heroicon-o-exclamation-triangle',
                        'info' => 'heroicon-o-information-circle',
                        default => 'heroicon-o-bell-alert',
                    })
                    ->iconColor($level);

                if ($url) {
                    $n->actions([Action::make('open')->label('فتح')->url($url)->markAsRead()]);
                }

                $n->sendToDatabase($admins);
            }

            // تطبيق الإدارة (ازانكس إدارة) — إشعار بأولوية عالية
            self::pushTo($admins, $title, $body, ['type' => 'alert', 'level' => $level, 'url' => (string) $url] + self::refFromKey($key));

            self::viaPhone($title, $body);
        } catch (\Throwable $e) {
            // التنبيه ما يطيّحش العملية الأصلية (إلغاء طلب، بلاغ...)
            Log::error('Admin alert failed', ['title' => $title, 'error' => $e->getMessage()]);
        }

        return true;
    }

    /** إشعار لتطبيق الإدارة بس (بدون جرس اللوحة) — مثلاً كل طلب جديد */
    public static function pushOnly(string $title, string $body, array $data = [], string $permission = 'orders.view'): void
    {
        try {
            $admins = User::withRole(UserRole::Admin)->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->hasPermission($permission));
            self::pushTo($admins, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::error('Admin push failed', ['title' => $title, 'error' => $e->getMessage()]);
        }
    }

    private static function pushTo($admins, string $title, string $body, array $data): void
    {
        foreach ($admins as $admin) {
            if ($admin->pushTokenFor('admin')) {
                PushService::toUser($admin, $title, $body, $data, 'admin');
            }
        }
    }

    /** order-failed:15 → order_id=15 · ticket-new:3 → ticket_id=3 (التطبيق يفتح الشاشة الصح) */
    private static function refFromKey(?string $key): array
    {
        if (! $key || ! preg_match('/^(order|stuck|ticket)[a-z\-]*:(\d+)/', $key, $m)) {
            return [];
        }

        return $m[1] === 'ticket' ? ['ticket_id' => $m[2]] : ['order_id' => $m[2]];
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
