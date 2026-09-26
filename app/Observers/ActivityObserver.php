<?php

namespace App\Observers;

use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;

/** يسجّل كل إضافة/تعديل/حذف في الجداول المهمة (قبل ← بعد) */
class ActivityObserver
{
    /** تغييرات روتينية ما تستاهلش سطر (موقع السائق كل ثواني، آخر ظهور...) */
    private const IGNORE = ['updated_at', 'created_at', 'password', 'remember_token', 'fcm_token', 'fcm_tokens',
        'last_seen_at', 'current_lat', 'current_lng', 'location_updated_at', 'drivers_notified_at',
        'rating_avg', 'rating_count', 'phone_verified_at'];

    public function created(Model $model): void
    {
        $attrs = array_diff_key($model->getAttributes(), array_flip(self::IGNORE));
        Activity::modelChanged($model, 'created', Activity::clean(array_map(fn ($v) => [null, $v], $attrs)));
    }

    public function updated(Model $model): void
    {
        $changes = [];
        foreach (array_diff_key($model->getChanges(), array_flip(self::IGNORE)) as $key => $new) {
            $old = $model->getOriginal($key);
            // القيم المحوّلة (enum، تاريخ) — نقارنو النص
            $o = $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d H:i') : $old);
            $n = $model->getAttribute($key);
            $n = $n instanceof \BackedEnum ? $n->value : ($n instanceof \DateTimeInterface ? $n->format('Y-m-d H:i') : $n);
            if (is_array($o) || is_array($n)) {
                $o = is_array($o) ? json_encode($o, JSON_UNESCAPED_UNICODE) : $o;
                $n = is_array($n) ? json_encode($n, JSON_UNESCAPED_UNICODE) : $n;
            }
            if ((string) $o === (string) $n) {
                continue;
            }
            $changes[$key] = [$o, $n];
        }

        if ($changes === []) {
            return;
        }

        Activity::modelChanged($model, 'updated', Activity::clean($changes));
    }

    public function deleted(Model $model): void
    {
        Activity::modelChanged($model, 'deleted', []);
    }

    public function restored(Model $model): void
    {
        Activity::modelChanged($model, 'restored', []);
    }
}
