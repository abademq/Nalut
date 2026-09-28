<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Store;
use App\Models\User;

/**
 * لوحة المتجر على الموقع (/merchant): صاحب المتجر يدير متجره بس.
 * كل استعلام في اللوحة يمر من هني — ما فيش طريقة يوصل لمتجر غيره.
 */
class Merchant
{
    public static function enabled(): bool
    {
        return (bool) Options::get('merchant.web_enabled');
    }

    public static function ordersEnabled(): bool
    {
        return (bool) Options::get('merchant.orders');
    }

    public static function canUse(User $user): bool
    {
        return self::enabled()
            && $user->is_active
            && $user->hasRole(UserRole::Store)
            && $user->store()->exists();
    }

    /** متجر المستخدم الحالي — اللوحة كاملة مبنية عليه */
    public static function store(): ?Store
    {
        $user = auth()->user();

        return $user instanceof User ? $user->store : null;
    }

    public static function storeId(): int
    {
        // 0 = ما يطابق شي (لو صار شي غريب ما يطلعش ولا متجر)
        return (int) (self::store()?->id ?? 0);
    }
}
