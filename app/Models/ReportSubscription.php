<?php

namespace App\Models;

use App\Services\Messaging\Messenger;
use App\Services\Reports\StoreReport;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** تقرير يوصل على واتساب/SMS: مرة وحدة أو دوري (يومي/أسبوعي/شهري) */
class ReportSubscription extends Model
{
    protected $fillable = [
        'name', 'store_id', 'recipient', 'phone', 'message_template_id', 'period', 'frequency',
        'send_time', 'weekday', 'month_day', 'send_at', 'is_active', 'next_run_at', 'last_sent_at', 'last_status',
    ];

    /** نفس قيم قاعدة البيانات الافتراضية — باش الموعد يتحسب من أول حفظ */
    protected $attributes = [
        'is_active' => true, 'frequency' => 'daily', 'period' => 'today', 'send_time' => '23:00', 'recipient' => 'store',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'send_at'      => 'datetime',
            'next_run_at'  => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public const FREQUENCIES = ['once' => 'مرة وحدة', 'daily' => 'يومي', 'weekly' => 'أسبوعي', 'monthly' => 'شهري'];

    public const WEEKDAYS = [6 => 'السبت', 7 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة'];

    protected static function booted(): void
    {
        // أي تعديل على الجدولة يعيد حساب الموعد الجاي
        static::saving(function (self $s) {
            if ($s->isDirty(['frequency', 'send_time', 'weekday', 'month_day', 'send_at', 'is_active']) || ! $s->next_run_at) {
                $s->next_run_at = $s->is_active ? $s->computeNextRun() : null;
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    /** رقم المستلم: رقم المتجر أو الرقم المحدد */
    public function recipientPhone(): ?string
    {
        if ($this->recipient === 'custom') {
            return $this->phone ?: null;
        }

        return $this->store?->phone ?: $this->store?->owner?->phone;
    }

    /** الموعد الجاي بعد $after (UTC) */
    public function computeNextRun(?Carbon $after = null): ?Carbon
    {
        $tz = LocalDay::timezone();
        $after = ($after ?? now())->copy()->setTimezone($tz);

        if ($this->frequency === 'once') {
            if (! $this->send_at) {
                return null;
            }

            return $this->last_sent_at ? null : $this->send_at->copy()->utc();
        }

        [$h, $m] = array_map('intval', explode(':', $this->send_time ?: '23:00') + [0, 0]);
        $candidate = $after->copy()->setTime($h, $m);

        switch ($this->frequency) {
            case 'weekly':
                $day = (int) ($this->weekday ?: 6);
                while ($candidate->dayOfWeekIso !== $day || $candidate->lte($after)) {
                    $candidate->addDay()->setTime($h, $m);
                }
                break;

            case 'monthly':
                $dom = max(1, min(28, (int) ($this->month_day ?: 1)));
                $candidate = $after->copy()->day($dom)->setTime($h, $m);
                if ($candidate->lte($after)) {
                    $candidate = $after->copy()->startOfMonth()->addMonthNoOverflow()->day($dom)->setTime($h, $m);
                }
                break;

            default: // daily
                if ($candidate->lte($after)) {
                    $candidate->addDay();
                }
        }

        return $candidate->utc();
    }

    public function vars(): array
    {
        return StoreReport::build($this->store, $this->period);
    }

    public function preview(): string
    {
        return $this->template ? $this->template->render($this->vars()) : '';
    }

    /** إرسال الآن — ويحدّث الموعد الجاي */
    public function sendNow(): MessageLog
    {
        $phone = $this->recipientPhone();

        $log = $phone && $this->template
            ? app(Messenger::class)->send($this->template, $phone, $this->vars(), 'report', $this->id)
            : MessageLog::create(['channel' => $this->template?->channel ?? 'sms', 'phone' => $phone ?? '-',
                'context' => 'report', 'context_id' => $this->id, 'status' => 'failed',
                'error' => $phone ? 'القالب ناقص' : 'ما فيش رقم للمستلم', 'created_at' => now()]);

        $this->forceFill([
            'last_sent_at' => now(),
            'last_status'  => $log->status,
        ]);
        $this->next_run_at = $this->frequency === 'once' ? null : $this->computeNextRun(now());
        if ($this->frequency === 'once') {
            $this->is_active = false;
        }
        $this->saveQuietly();

        return $log;
    }
}
