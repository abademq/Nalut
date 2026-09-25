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

    /**
     * رقم الكرت: أرقام فقط، طوله من config('wallet.card_digits').
     * أول رقم ما يكونش صفر (باش ما يضيعش لو انكتب في Excel كرقم).
     * random_int آمن للتشفير — الأرقام ما تتوقّعش.
     */
    public static function generateCode(?int $digits = null): string
    {
        $digits ??= (int) \App\Support\Options::get('wallet.card_digits');

        do {
            $code = (string) random_int(1, 9);
            for ($i = 1; $i < $digits; $i++) {
                $code .= random_int(0, 9);
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * توحيد اللي كتبه الزبون: الكروت الرقمية تقبل مسافات وشرطات
     * (1234 5678 9012)، والكروت القديمة (NLT-XXXX-XXXX) تضل زي ما هي.
     */
    public static function normalize(string $input): string
    {
        $input = strtoupper(trim($input));
        $digits = preg_replace('/[\s\-]/', '', $input);

        return ctype_digit($digits) ? $digits : $input;
    }

    /** للطباعة والعرض: 1234 5678 9012 */
    public static function format(string $code): string
    {
        return ctype_digit($code) ? trim(chunk_split($code, 4, ' ')) : $code;
    }
}
