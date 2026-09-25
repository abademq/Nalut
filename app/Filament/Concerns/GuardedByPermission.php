<?php

namespace App\Filament\Concerns;

use App\Support\Perm;
use Illuminate\Database\Eloquent\Model;

/**
 * يربط مورد Filament بصلاحيتين من App\Support\Permissions:
 *   PERM_VIEW   — يشوف القائمة والتفاصيل
 *   PERM_MANAGE — ينشئ ويعدّل ويحذف (ويشوف طبعاً)
 * الكلاس لازم يعرّف الثابتين. أي دالة can* معرّفة في الكلاس نفسه تغلب هذي.
 */
trait GuardedByPermission
{
    protected static function canManageResource(): bool
    {
        return Perm::can(static::PERM_MANAGE);
    }

    public static function canViewAny(): bool
    {
        return Perm::can(static::PERM_VIEW) || static::canManageResource();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canManageResource();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageResource();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageResource();
    }

    public static function canDeleteAny(): bool
    {
        return static::canManageResource();
    }
}
