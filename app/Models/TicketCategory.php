<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** نوع مشكلة في التذاكر (لكل تطبيق قائمته) — يتعدّل من اللوحة */
class TicketCategory extends Model
{
    protected $fillable = ['app', 'key', 'label', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // المفتاح يتولّد مرة وحدة — تغيير الاسم بعدين ما يأثرش على التذاكر القديمة
        static::creating(function (TicketCategory $c) {
            if (blank($c->key)) {
                $c->key = 'c'.Str::lower(Str::random(8));
            }
            if ($c->sort === null) {
                $c->sort = (int) static::where('app', $c->app)->max('sort') + 1;
            }
        });
    }

    public function ticketsCount(): int
    {
        return Ticket::where('app', $this->app)->where('category', $this->key)->count();
    }
}
