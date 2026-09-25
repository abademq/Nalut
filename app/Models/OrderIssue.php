<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderIssue extends Model
{
    protected $fillable = [
        'ticket', 'order_id', 'driver_id', 'failure_reason_id', 'reason_label', 'note',
        'action', 'status', 'resolution', 'resolution_note', 'resolved_by', 'resolved_at', 'lat', 'lng',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public const RESOLUTIONS = [
        'continue'  => 'يكمّل السائق التوصيل',
        'reassign'  => 'إسناده لسائق آخر',
        'failed'    => 'فشل التسليم',
        'cancelled' => 'إلغاء الطلب',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
