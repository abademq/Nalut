<?php

namespace App\Support;

use App\Models\Favorite;
use App\Models\User;

/** مفضلة المستخدم — استعلام واحد لكل طلب HTTP بدل استعلام لكل متجر/صنف */
class FavoriteIds
{
    /** @var array<string, array<int, true>> */
    private static array $cache = [];

    public static function has(?User $user, string $class, int $id): bool
    {
        if (! $user) {
            return false;
        }

        $key = $user->id.'|'.$class;
        if (! isset(self::$cache[$key])) {
            self::$cache[$key] = array_fill_keys(
                Favorite::where('user_id', $user->id)->where('favoritable_type', $class)->pluck('favoritable_id')->all(),
                true
            );
        }

        return isset(self::$cache[$key][$id]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
