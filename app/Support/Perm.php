<?php

namespace App\Support;

use App\Enums\UserRole;

/** فحص صلاحيات مستخدم لوحة التحكم الحالي */
class Perm
{
    public static function can(string $key): bool
    {
        return (bool) auth()->user()?->hasPermission($key);
    }

    /**
     * مدير كامل الصلاحيات (permissions = null).
     * هو بس اللي يقدر ينشئ أو يعدّل حسابات إدارة ويوزّع الصلاحيات.
     */
    public static function isSuper(): bool
    {
        $u = auth()->user();

        return $u && $u->role === UserRole::Admin && empty($u->permissions);
    }
}
