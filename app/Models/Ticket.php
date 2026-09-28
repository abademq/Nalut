<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** تذكرة دعم — محادثة بين المستخدم والإدارة */
class Ticket extends Model
{
    protected $fillable = [
        'code', 'user_id', 'app', 'category', 'subject', 'order_id', 'status', 'assigned_to',
        'user_unread', 'admin_unread', 'last_message_at', 'closed_at', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'admin_unread' => 'boolean',
            'user_unread' => 'integer',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public const STATUSES = [
        'open' => 'تستنى رد الإدارة',
        'answered' => 'الإدارة ردّت',
        'closed' => 'مقفولة',
    ];

    public const APPS = ['customer' => 'زبون', 'driver' => 'سائق', 'store' => 'متجر'];

    /** أنواع المشاكل لكل تطبيق */
    public const CATEGORIES = [
        'customer' => [
            'order' => 'مشكلة في طلب',
            'payment' => 'الدفع والمحفظة',
            'account' => 'الحساب وتسجيل الدخول',
            'app' => 'مشكلة في التطبيق',
            'suggestion' => 'اقتراح أو ملاحظة',
            'other' => 'شي آخر',
        ],
        'driver' => [
            'delivery' => 'مشكلة في توصيل طلب',
            'customer' => 'الزبون (ما يردش، العنوان غلط...)',
            'store' => 'المتجر (تأخير، الطلب مش جاهز...)',
            'earnings' => 'الأرباح والتسوية',
            'account' => 'الحساب',
            'app' => 'مشكلة في التطبيق',
            'other' => 'شي آخر',
        ],
        'store' => [
            'orders' => 'الطلبات',
            'products' => 'الأصناف والقائمة',
            'settlement' => 'الرصيد والتسوية',
            'account' => 'الحساب',
            'app' => 'مشكلة في التطبيق',
            'other' => 'شي آخر',
        ],
    ];

    public static function categoriesFor(string $app): array
    {
        return self::CATEGORIES[$app] ?? self::CATEGORIES['customer'];
    }

    public function categoryLabel(): string
    {
        return self::categoriesFor($this->app)[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '!=', 'closed');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(TicketMessage::class)->latestOfMany();
    }

    /** للتطبيق */
    public function toApp(bool $withMessages = false): array
    {
        $out = [
            'id' => $this->id,
            'code' => $this->code,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'subject' => $this->subject,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'order_id' => $this->order_id,
            'order_code' => $this->order?->code,
            'unread' => (int) $this->user_unread,
            'last_message' => $this->lastMessage ? mb_substr((string) ($this->lastMessage->body ?: '📷 صورة'), 0, 80) : null,
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
        ];

        if ($withMessages) {
            $out['messages'] = $this->messages->map->toApp()->values();
        }

        return $out;
    }
}
