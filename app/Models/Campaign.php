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
        'title', 'channel', 'target_role', 'push_title', 'push_body', 'push_link',
        'message_template_id', 'audience', 'audience_params', 'status',
        'scheduled_at', 'total', 'sent', 'failed', 'created_by', 'started_at', 'finished_at',
    ];

    protected $attributes = ['status' => 'draft', 'total' => 0, 'sent' => 0, 'failed' => 0,
        'channel' => 'template', 'target_role' => 'customer', 'audience' => 'all'];

    public const CHANNELS = ['template' => 'واتساب / SMS (قالب)', 'push' => 'إشعار في التطبيق'];

    public const ROLES = ['customer' => 'الزبائن', 'driver' => 'السائقين', 'store' => 'المتاجر'];

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

        $role = $this->channel === 'push' ? ($this->target_role ?: 'customer') : 'customer';

        $q = User::query()
            ->where('role', $role)
            ->where('is_active', true)
            ->where('marketing_opt_out', false)
            ->when($this->channel === 'push', fn ($w) => $w->whereNotNull('fcm_token'), fn ($w) => $w->whereNotNull('phone'));

        // السائقين والمتاجر: الكل بس
        if ($role !== 'customer') {
            return $q;
        }

        return match ($this->audience) {
            'active'   => $q->whereHas('orders', fn ($o) => $o->where('status', $delivered)->where('created_at', '>=', now()->subDays($days))),
            'inactive' => $q->whereHas('orders', fn ($o) => $o->where('status', $delivered))
                ->whereDoesntHave('orders', fn ($o) => $o->where('created_at', '>=', now()->subDays($days))),
            'store'    => $q->whereHas('orders', fn ($o) => $o->where('store_id', (int) ($p['store_id'] ?? 0))),
            default    => $q,
        };
    }

    /** @return Collection<int, array{phone: string, name: string, user?: User}> */
    public function recipients(): Collection
    {
        if ($this->channel === 'push') {
            return $this->customersQuery()->get()
                ->map(fn (User $u) => ['phone' => (string) $u->phone, 'name' => $u->name, 'user' => $u])->values();
        }

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
            $log = $this->channel === 'push'
                ? $this->sendPush($r['user'], $r['name'])
                : $messenger->send($this->template, $r['phone'], ['name' => $r['name'] ?: 'زبوننا'], 'campaign', $this->id);
            $log->status === 'sent' ? $sent++ : $failed++;

            if (($sent + $failed) % 20 === 0) {
                $this->update(['sent' => $sent, 'failed' => $failed]);
            }

            if ($this->channel !== 'push') {
                usleep(150_000); // ما نضغطوش على مزوّد الرسائل
            }
        }

        $this->update([
            'sent'        => $sent,
            'failed'      => $failed,
            'status'      => $sent === 0 && $failed > 0 ? 'failed' : 'done',
            'finished_at' => now(),
        ]);
    }

    /** إشعار في التطبيق — {name} يتعوّض باسم المستلم، والرابط يفتح متجر/صنف/شاشة */
    private function sendPush(User $user, string $name): MessageLog
    {
        $vars = ['name' => $name ?: 'زبوننا'];
        $log = ['channel' => 'push', 'phone' => (string) $user->phone, 'context' => 'campaign',
            'context_id' => $this->id, 'created_at' => now()];

        try {
            \App\Services\PushService::toUser(
                $user,
                \App\Support\Texts::fill((string) $this->push_title, $vars),
                \App\Support\Texts::fill((string) $this->push_body, $vars),
                array_filter(['type' => 'promo', 'campaign_id' => (string) $this->id, 'link' => $this->push_link])
            );

            return MessageLog::create($log + ['status' => 'sent']);
        } catch (\Throwable $e) {
            return MessageLog::create($log + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
