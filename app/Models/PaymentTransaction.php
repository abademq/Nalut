<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'user_id', 'gateway', 'purpose', 'order_id', 'invoice_no', 'amount',
        'status', 'process_id', 'provider_transaction_id', 'failure_reason',
        'meta', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'  => 'float',
            'meta'    => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'  => 'قيد التنفيذ',
            'paid'     => 'مدفوعة',
            'failed'   => 'فشلت',
            'canceled' => 'ألغاها الزبون',
            default    => $this->status,
        };
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** رقم فاتورة فريد — بلوتو ترفض المكرر */
    public static function generateInvoiceNo(): string
    {
        do {
            $no = 'NLT'.now()->format('ymdHis').random_int(10, 99);
        } while (self::where('invoice_no', $no)->exists());

        return $no;
    }
}
