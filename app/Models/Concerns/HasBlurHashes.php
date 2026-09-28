<?php

namespace App\Models\Concerns;

use App\Support\BlurHash;

/**
 * يحسب BlurHash تلقائياً لما صورة تتغيّر.
 * الموديل يعرّف: protected const BLURHASH = ['logo' => 'logo_hash', ...];
 */
trait HasBlurHashes
{
    /** @return array<string, string> حقل الصورة ← عمود الـ hash */
    public static function BLURHASH_FIELDS(): array
    {
        return static::BLURHASH;
    }

    public static function bootHasBlurHashes(): void
    {
        static::saving(function ($model) {
            foreach (static::BLURHASH as $field => $hashCol) {
                if ($model->isDirty($field) || ($model->{$field} && ! $model->{$hashCol})) {
                    $model->{$hashCol} = BlurHash::fromPath($model->{$field});
                }
            }
        });
    }
}
