@php
    $t = $this->ticket()->load(['messages.user', 'user', 'order', 'assignee']);
    $tz = \App\Support\LocalDay::timezone();
@endphp
<x-filament-panels::page>
    <div wire:poll.15s="refreshTicket" class="space-y-4">
        <x-filament::section>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
                <div><div style="font-size:12px;color:#6b7280">من</div><b>{{ $t->user?->name ?? '—' }}</b>
                    <div dir="ltr" style="text-align:right">
                        @if ($t->user?->phone)<a href="tel:{{ $t->user->phone }}" style="color:#2563eb">{{ $t->user->phone }}</a>@endif
                    </div>
                    <x-filament::badge :color="match($t->app){'driver'=>'info','store'=>'warning',default=>'gray'}">{{ \App\Models\Ticket::APPS[$t->app] ?? $t->app }}</x-filament::badge>
                </div>
                <div><div style="font-size:12px;color:#6b7280">النوع</div><b>{{ $t->categoryLabel() }}</b></div>
                <div><div style="font-size:12px;color:#6b7280">الحالة</div>
                    <x-filament::badge :color="\App\Filament\Resources\Tickets\TicketResource::statusColor($t->status)">{{ $t->statusLabel() }}</x-filament::badge></div>
                <div><div style="font-size:12px;color:#6b7280">الطلب</div><b>{{ $t->order?->code ?? '—' }}</b>
                    @if ($t->order)<div style="font-size:12px;color:#6b7280">{{ $t->order->status->label() }}</div>@endif</div>
                <div><div style="font-size:12px;color:#6b7280">المسؤول</div><b>{{ $t->assignee?->name ?? '—' }}</b></div>
            </div>
            <div style="margin-top:10px;font-weight:700">{{ $t->subject }}</div>
        </x-filament::section>

        <x-filament::section>
            <div style="display:flex;flex-direction:column;gap:10px;max-height:60vh;overflow-y:auto;padding:4px"
                 x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">
                @foreach ($t->messages as $m)
                    <div style="display:flex;justify-content:{{ $m->is_staff ? 'flex-end' : 'flex-start' }}">
                        <div style="max-width:78%;padding:10px 12px;border-radius:14px;{{ $m->is_staff ? 'background:#fff7ed;border:1px solid #fed7aa' : 'background:rgba(127,127,127,.12)' }}">
                            <div style="font-size:11px;color:#6b7280;margin-bottom:4px">
                                {{ $m->is_staff ? 'الدعم — '.($m->user?->name ?? '') : ($t->user?->name ?? 'المستخدم') }}
                                · {{ $m->created_at?->timezone($tz)->format('d/m H:i') }}
                            </div>
                            @if ($m->body)<div style="white-space:pre-wrap;line-height:1.6">{{ $m->body }}</div>@endif
                            @if ($m->image)
                                <a href="{{ $m->imageUrl() }}" target="_blank"><img src="{{ $m->imageUrl() }}" alt="" style="max-width:260px;max-height:260px;border-radius:10px;margin-top:6px"></a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <form wire:submit="send" class="space-y-3">
                {{ $this->replyForm }}
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">إرسال الرد</x-filament::button>
                    <x-filament::button type="button" color="gray" wire:click="sendAndClose" icon="heroicon-o-lock-closed">إرسال وقفل التذكرة</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
