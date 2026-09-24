<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPES = [
        'topup_card'     => 'شحن بكرت',
        'topup_cash'     => 'شحن نقدي',
        'order_payment'  => 'دفع طلب',
        'order_refund'   => 'استرجاع طلب',
        'store_earning'  => 'مستحقات متجر',
        'driver_earning' => 'أجرة توصيل',
        'cash_collected' => 'كاش محصّل للمنصة',
        'payout'         => 'صرف مستحقات',
        'settlement'     => 'تسوية نقدية',
        'adjustment'     => 'تعديل يدوي',
        'topup_online'   => 'دفع إلكتروني',
    ];

    protected $fillable = [
        'wallet_id', 'type', 'amount', 'balance_after',
        'order_id', 'recharge_card_id', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isCredit(): bool
    {
        return $this->amount >= 0;
    }
}
