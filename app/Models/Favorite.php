<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Favorite extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'favoritable_type', 'favoritable_id', 'created_at'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\FavoriteIds::flush());
        static::deleted(fn () => \App\Support\FavoriteIds::flush());
    }

    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }
}
