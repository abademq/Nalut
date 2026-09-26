<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointsTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'points', 'balance_after', 'type', 'order_id', 'note', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public const TYPES = [
        'earned'    => 'نقاط طلب',
        'converted' => 'تحويل للمحفظة',
        'redeemed'  => 'استعمال في طلب',
        'refund'    => 'استرجاع نقاط',
        'adjust'    => 'تعديل من الإدارة',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
