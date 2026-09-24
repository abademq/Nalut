<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * تحكّم الإدارة في: منو ياخذ إشعار عند أي حالة.
 */
class NotificationSetting extends Model
{
    protected $fillable = ['role', 'status', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public const ROLES = [
        'customer' => 'الزبون',
        'store'    => 'المتجر',
        'driver'   => 'السائق',
    ];

    /** حالة خاصة مش من آلة الحالات */
    public const EXTRA_STATUSES = [
        'available' => 'طلب متاح للاستلام',
    ];

    public static function statusLabel(string $status): string
    {
        if (isset(self::EXTRA_STATUSES[$status])) {
            return self::EXTRA_STATUSES[$status];
        }

        return OrderStatus::tryFrom($status)?->label() ?? $status;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /** الفحص المستعمل في OrderService — مخزّن في الكاش */
     public static function isEnabled(string $role, string $status): bool
    {
        return (bool) self::where('role', $role)
            ->where('status', $status)
            ->value('enabled');
    }

    protected static function booted(): void
    {
        // أي تعديل يمسح الكاش فوراً
        static::saved(fn () => Cache::forget('notification_settings'));
        static::deleted(fn () => Cache::forget('notification_settings'));
    }
}
