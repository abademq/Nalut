<?php

namespace App\Enums;

/**
 * آلة حالات الطلب — أي انتقال غير مذكور هنا مرفوض.
 */
enum OrderStatus: string
{
    case Pending   = 'pending';
    case Accepted  = 'accepted';
    case Preparing = 'preparing';
    case Ready     = 'ready';
    case Assigned  = 'assigned';
    case PickedUp  = 'picked_up';
    case OnTheWay  = 'on_the_way';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed    = 'failed';

    /** الاسم الظاهر — قابل للتعديل من لوحة التحكم (النصوص ← حالات الطلب) */
    public function label(): string
    {
        return \App\Support\Texts::get('status.'.$this->value);
    }

    /** @return array<int, OrderStatus> */
    public function allowedNext(): array
    {
        return match ($this) {
            // القبول يعني بدء التحضير مباشرة
            self::Pending   => [self::Preparing, self::Cancelled],
            // موجودة للطلبات القديمة فقط
            self::Accepted  => [self::Preparing, self::Ready, self::Cancelled],
            self::Preparing => [self::Ready, self::Assigned, self::Cancelled],
            self::Ready     => [self::Assigned, self::Cancelled],
            self::Assigned  => [self::PickedUp, self::Cancelled],
            self::PickedUp  => [self::OnTheWay, self::Failed],
            self::OnTheWay  => [self::Delivered, self::Failed],
            default         => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Failed], true);
    }

    /** @return array<int, string> */
    public static function active(): array
    {
        return [
            self::Pending->value, self::Accepted->value, self::Preparing->value,
            self::Ready->value, self::Assigned->value, self::PickedUp->value,
            self::OnTheWay->value,
        ];
    }
}
