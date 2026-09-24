<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RechargeCard extends Model
{
    protected $fillable = [
        'code', 'amount', 'batch', 'status', 'used_by', 'used_at',
        'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'used_at'    => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function isUsable(): bool
    {
        return $this->status === 'unused'
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'unused'   => 'غير مستعمل',
            'used'     => 'مستعمل',
            'disabled' => 'موقوف',
            default    => $this->status,
        };
    }

    /** كود بصيغة NLT-XXXX-XXXX بدون حروف ملتبسة */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $part1 = collect(range(1, 4))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->join('');
            $part2 = collect(range(1, 4))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->join('');
            $code  = "NLT-$part1-$part2";
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
