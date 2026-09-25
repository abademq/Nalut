<?php

namespace App\Models;

use App\Support\Texts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * نص واحد قابل للتعديل من لوحة التحكم.
 *
 * - app = customer/store/driver: key هو النص الأصلي في التطبيق نفسه.
 * - app = server: key مفتاح ثابت (notify.customer.ready مثلاً).
 */
class AppText extends Model
{
    protected $fillable = ['app', 'group', 'key_hash', 'key', 'default', 'value', 'vars', 'is_used'];

    protected function casts(): array
    {
        return ['is_used' => 'boolean'];
    }

    public const APPS = [
        'server'   => 'الإشعارات والرسائل',
        'customer' => 'تطبيق الزبون',
        'store'    => 'تطبيق المتجر',
        'driver'   => 'تطبيق السائق',
    ];

    protected static function booted(): void
    {
        static::saving(function (AppText $t) {
            $t->key_hash = md5($t->key);

            // تعديل فاضي أو نفس الأصل = رجوع للأصل
            if ($t->value !== null && (trim($t->value) === '' || $t->value === $t->default)) {
                $t->value = null;
            }
        });

        static::saved(fn (AppText $t) => Texts::flush($t->app));
        static::deleted(fn (AppText $t) => Texts::flush($t->app));
    }

    public function scopeFor(Builder $q, string $app): Builder
    {
        return $q->where('app', $app);
    }

    public function current(): string
    {
        return $this->value ?? $this->default;
    }

    public function isCustomized(): bool
    {
        return $this->value !== null;
    }
}
