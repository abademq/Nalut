<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'settings.all';

    /** كل الإعدادات في استعلام واحد مخزّن في الكاش — الإعدادات قليلة وتنقرا كثير */
    public static function allValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::allValues();

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, static::allValues());
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        static::flush();
    }

    public static function forget(string $key): void
    {
        static::whereKey($key)->delete();
        static::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
