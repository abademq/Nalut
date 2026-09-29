<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    /** كل صفة لها محفظتها — مستحقات المتجر ما تتخلطش مع شحن صاحبه كزبون */
    public const PARTIES = [
        'customer' => 'زبون',
        'store' => 'متجر',
        'driver' => 'سائق',
    ];

    protected $fillable = ['user_id', 'party', 'balance', 'is_active'];

    public function partyLabel(): string
    {
        return self::PARTIES[$this->party] ?? $this->party;
    }

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    /** رصيد سالب = مدين للمنصة (سائق ماسك كاش مثلاً) */
    public function isDebtor(): bool
    {
        return $this->balance < 0;
    }
}
