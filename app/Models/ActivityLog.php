<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** سطر في سجل النشاط — يتكتب بس، ما يتعدّلش */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const APPS = [
        'customer' => 'تطبيق الزبون',
        'store'    => 'تطبيق المتجر',
        'driver'   => 'تطبيق السائق',
        'admin'    => 'لوحة التحكم',
        'system'   => 'النظام (تلقائي)',
    ];

    protected $fillable = [
        'user_id', 'app', 'action', 'description', 'subject_type', 'subject_id',
        'order_id', 'store_id', 'properties', 'status', 'ip', 'device', 'created_at',
    ];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
