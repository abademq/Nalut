<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Support\Options;
use App\Support\OrderMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'code', 'customer_id', 'store_id', 'fulfillment', 'pickup_code', 'delivery_zone_id', 'driver_id', 'coupon_id', 'status',
        'payment_method', 'is_paid', 'wallet_paid', 'earnings_settled',
        'address_details', 'address_landmark',
        'address_lat', 'address_lng', 'customer_phone', 'subtotal', 'delivery_fee',
        'discount', 'delivery_subsidy', 'total', 'commission_amount', 'store_earning', 'driver_earning',
        'distance_km', 'notes', 'prep_time_minutes', 'accepted_at', 'ready_at', 'drivers_notified_at',
        'picked_up_at', 'arrived_at', 'handover_deadline_at', 'handover_expired_at', 'left_at_door_at', 'door_photo', 'delivered_at', 'cancelled_at', 'cancel_reason', 'cancelled_by',
        'points_used', 'points_discount', 'awaiting_customer_at', 'substitution_deadline_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'is_paid' => 'boolean',
            'wallet_paid' => 'float',
            'earnings_settled' => 'boolean',
            'address_lat' => 'float',
            'address_lng' => 'float',
            'subtotal' => 'float',
            'delivery_fee' => 'float',
            'discount' => 'float',
            'total' => 'float',
            'distance_km' => 'float',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'arrived_at' => 'datetime',
            'handover_deadline_at' => 'datetime',
            'handover_expired_at' => 'datetime',
            'left_at_door_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'stock_restored_at' => 'datetime',
            'awaiting_customer_at' => 'datetime',
            'substitution_deadline_at' => 'datetime',
            'points_discount' => 'float',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('id');
    }

    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** الإدارة: نخبّيو طلبات البطاقة اللي دفعها ما كمّلش (لسه ما وصلتش للمتجر) */
    public function scopeVisibleToStaff(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('payment_method', '!=', 'card')->orWhere('is_paid', true)
            ->orWhereIn('status', ['cancelled', 'failed']));
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', OrderStatus::active());
    }

    /**
     * هل الطلب متاح للسائقين؟
     * إما المتجر حدّده جاهز، أو انقضى وقت التحضير اللي حدّده.
     */
    public function isAvailableForDrivers(): bool
    {
        // الاستلام من المطعم: الزبون ياخذه بنفسه
        if ($this->driver_id || $this->isPickup()) {
            return false;
        }

        if ($this->status === OrderStatus::Ready) {
            return true;
        }

        if ($this->status !== OrderStatus::Preparing || ! $this->accepted_at) {
            return false;
        }

        // copy(): addMinutes تعدّل الكائن نفسه، وبدونها accepted_at يتغيّر في الذاكرة
        return $this->accepted_at->copy()
            ->addMinutes((int) ($this->prep_time_minutes ?? 0))
            ->isPast();
    }

    /** كم دقيقة باقية على جاهزية الطلب */
    public function minutesUntilReady(): int
    {
        if ($this->status === OrderStatus::Ready || ! $this->accepted_at) {
            return 0;
        }

        $readyAt = $this->accepted_at->copy()->addMinutes((int) ($this->prep_time_minutes ?? 0));

        return max(0, (int) ceil(now()->diffInMinutes($readyAt, false)));
    }

    /**
     * أرقام الطلبات متسلسلة: 1، 2، 3... = رقم الطلب في قاعدة البيانات (id).
     * الـ id يتولّد من قاعدة البيانات نفسها، فما فيش تكرار حتى لو جو طلبين
     * في نفس اللحظة. وقت الإنشاء نحطو رقم مؤقت، ويتبدّل مباشرة بعده.
     */
    public static function temporaryCode(): string
    {
        return 'T'.strtoupper(Str::random(11));
    }

    /** @deprecated الأرقام صارت متسلسلة — استعمل temporaryCode() */
    public static function generateCode(): string
    {
        return self::temporaryCode();
    }

    protected static function booted(): void
    {
        static::created(function (Order $order) {
            if (! str_starts_with((string) $order->code, 'T')) {
                return;
            }

            $code = (string) $order->id;

            // احتياط لقواعد بيانات فيها أرقام عشوائية قديمة ممكن تتصادم
            if (self::where('code', $code)->whereKeyNot($order->id)->exists()) {
                $code = $order->id.'-'.$order->created_at?->format('y');
            }

            $order->code = $code;
            $order->saveQuietly();
        });
    }

    /** بلاغ السائق المفتوح — الطلب «قيد مراجعة الإدارة» */
    public function openIssue(): HasOne
    {
        return $this->hasOne(OrderIssue::class)->where('status', 'open')->latestOfMany();
    }

    public function issues(): HasMany
    {
        return $this->hasMany(OrderIssue::class)->latest();
    }

    /** طلب بالبطاقة والدفع لسه ما تأكدش */
    public function isPickup(): bool
    {
        return ($this->fulfillment ?? 'delivery') === 'pickup';
    }

    // ===== بانتظار التسليم =====

    /** الثواني الباقية على مهلة الزبون (0 = انتهت). null = مش في الحالة هذي */
    public function handoverSecondsLeft(): ?int
    {
        if ($this->status !== OrderStatus::AwaitingHandover || ! $this->handover_deadline_at) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($this->handover_deadline_at, false));
    }

    /**
     * السائق يقدر يحط الطلب أمام الباب؟ بعد انتهاء المهلة بس،
     * والطلب النقدي (فيه فلوس يحصّلها) لا — إلا لو الإدارة سمحت.
     */
    public function leaveAtDoorBlocker(): ?string
    {
        if ($this->status !== OrderStatus::AwaitingHandover) {
            return 'الطلب مش في حالة «بانتظار التسليم».';
        }
        if (($this->handoverSecondsLeft() ?? 0) > 0) {
            return 'مهلة الزبون ما كمّلتش لين توّا.';
        }
        if (Options::get('handover.expired_action') === 'report_issue') {
            return 'الإجراء المعتمد بعد انتهاء المهلة: بلّغ من زر «تعذّر التسليم» والإدارة تقرر.';
        }
        if (OrderMoney::cashToCollect($this) > 0 && ! Options::get('delivery.leave_at_door_cash')) {
            return 'الطلب فيه مبلغ نقدي يتحصّل — ما ينفعش يتترك أمام الباب. استعمل «تعذّر التسليم».';
        }

        return null;
    }

    public function doorPhotoUrl(): ?string
    {
        return $this->door_photo ? asset('storage/'.$this->door_photo) : null;
    }

    public function awaitingOnlinePayment(): bool
    {
        return $this->payment_method === PaymentMethod::Card && ! $this->is_paid;
    }

    /** وقت الجاهزية المتوقع = وقت القبول + مدة التحضير */
    public function readyEta(): ?Carbon
    {
        if (! $this->accepted_at || ! $this->prep_time_minutes) {
            return null;
        }

        return Carbon::parse($this->accepted_at)->addMinutes((int) $this->prep_time_minutes);
    }
}
