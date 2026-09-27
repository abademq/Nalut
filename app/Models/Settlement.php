<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

class Settlement extends Model
{
    public const PARTIES = ['store' => 'متجر', 'driver' => 'سائق'];

    public const METHODS = [
        'cash' => 'نقداً',
        'transfer' => 'تحويل مصرفي',
        'cheque' => 'صك',
        'card' => 'بطاقة / دفع إلكتروني',
        'other' => 'أخرى',
    ];

    protected $fillable = [
        'number', 'user_id', 'party', 'store_id', 'direction', 'amount', 'balance_before', 'balance_after',
        'method', 'reference', 'period_from', 'period_to', 'orders_count', 'summary', 'note',
        'wallet_transaction_id', 'created_by', 'cancelled_at', 'cancel_reason', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float', 'balance_before' => 'float', 'balance_after' => 'float',
            'summary' => 'array', 'period_from' => 'datetime', 'period_to' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, $this->party === 'driver' ? 'driver_settlement_id' : 'store_settlement_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function partyLabel(): string
    {
        return self::PARTIES[$this->party] ?? $this->party;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    /** «صرفنا للمتجر» / «استلمنا من السائق» */
    public function directionLabel(): string
    {
        return $this->direction === 'pay'
            ? 'صرف للـ'.$this->partyLabel()
            : 'استلام من الـ'.$this->partyLabel();
    }

    /** اسم الطرف كما يطلع في الواصل */
    public function partyName(): string
    {
        return $this->party === 'store' && $this->store ? $this->store->name.' — '.$this->user?->name : (string) $this->user?->name;
    }

    /** رابط طباعة يفتح بدون دخول (للتطبيقات) — صالح 30 يوم */
    public function signedPrintUrl(string $paper = '80'): string
    {
        return URL::temporarySignedRoute('settlements.print', now()->addDays(30), ['settlement' => $this->id, 'paper' => $paper]);
    }
}
