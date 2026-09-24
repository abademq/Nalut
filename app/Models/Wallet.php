<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance', 'is_active'];

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
