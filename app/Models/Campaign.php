<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Services\Messaging\Messenger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** حملة رسائل تسويقية لأرقام الزبائن — واتساب أو SMS بقالب معتمد */
class Campaign extends Model
{
    protected $fillable = [
        'title', 'message_template_id', 'audience', 'audience_params', 'status',
        'scheduled_at', 'total', 'sent', 'failed', 'created_by', 'started_at', 'finished_at',
    ];

    protected $attributes = ['status' => 'draft', 'total' => 0, 'sent' => 0, 'failed' => 0];

    protected function casts(): array
    {
        return [
            'audience_params' => 'array',
            'scheduled_at'    => 'datetime',
            'started_at'      => 'datetime',
            'finished_at'     => 'datetime',
        ];
    }

    public const AUDIENCES = [
        'all'      => 'كل الزبائن',
        'active'   => 'زبائن طلبو خلال آخر X يوم',
        'inactive' => 'زبائن ما طلبوش من X يوم (للاسترجاع)',
        'store'    => 'زبائن طلبو من متجر معيّن',
        'numbers'  => 'أرقام محددة',
    ];

    public const STATUSES = [
        'draft'   => 'مسودة',
        'queued'  => 'مجدولة',
        'sending' => 'قيد الإرسال',
        'done'    => 'انتهت',
        'failed'  => 'فشلت',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    /** الزبائن المستهدفين (بدون اللي رافضين الرسائل التسويقية) */
    public function customersQuery(): Builder
    {
        $p = $this->audience_params ?? [];
        $days = max(1, (int) ($p['days'] ?? 30));
        $delivered = OrderStatus::Delivered->value;

        $q = User::query()
            ->where('role', UserRole::Customer->value)
            ->where('is_active', true)
            ->where('marketing_opt_out', false)
            ->whereNotNull('phone');

        return match ($this->audience) {
            'active'   => $q->whereHas('orders', fn ($o) => $o->where('status', $delivered)->where('created_at', '>=', now()->subDays($days))),
            'inactive' => $q->whereHas('orders', fn ($o) => $o->where('status', $delivered))
                ->whereDoesntHave('orders', fn ($o) => $o->where('created_at', '>=', now()->subDays($days))),
            'store'    => $q->whereHas('orders', fn ($o) => $o->where('store_id', (int) ($p['store_id'] ?? 0))),
            default    => $q,
        };
    }

    /** @return Collection<int, array{phone: string, name: string}> */
    public function recipients(): Collection
    {
        if ($this->audience === 'numbers') {
            return collect(preg_split('/[\s,،;]+/u', (string) ($this->audience_params['numbers'] ?? '')))
                ->map(fn ($n) => trim($n))->filter()
                ->unique()->values()
                ->map(fn ($n) => ['phone' => $n, 'name' => '']);
        }

        return $this->customersQuery()->get(['id', 'name', 'phone'])
            ->unique('phone')
            ->map(fn (User $u) => ['phone' => $u->phone, 'name' => $u->name])
            ->values();
    }

    /** الإرسال الفعلي — يشتغل من الأمر المجدول */
    public function run(): void
    {
        $this->update(['status' => 'sending', 'started_at' => now()]);

        $list = $this->recipients();
        $this->update(['total' => $list->count(), 'sent' => 0, 'failed' => 0]);

        $messenger = app(Messenger::class);
        $sent = $failed = 0;

        foreach ($list as $r) {
            $log = $messenger->send($this->template, $r['phone'], ['name' => $r['name'] ?: 'زبوننا'], 'campaign', $this->id);
            $log->status === 'sent' ? $sent++ : $failed++;

            if (($sent + $failed) % 20 === 0) {
                $this->update(['sent' => $sent, 'failed' => $failed]);
            }

            usleep(150_000); // ما نضغطوش على المزوّد
        }

        $this->update([
            'sent'        => $sent,
            'failed'      => $failed,
            'status'      => $sent === 0 && $failed > 0 ? 'failed' : 'done',
            'finished_at' => now(),
        ]);
    }
}
