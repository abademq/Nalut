@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $sum = $s->summary ?? [];
    $isStore = $s->party === 'store';
@endphp
<x-filament-panels::page>
<style>
    .sv-card { border:1px solid rgba(127,127,127,.25); border-radius:12px; padding:14px; background:rgba(127,127,127,.04); }
    .sv-grid { display:grid; gap:10px; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); }
    .sv-k { font-size:12px; opacity:.7; } .sv-v { font-size:18px; font-weight:800; }
    .sv-t { width:100%; border-collapse:collapse; font-size:13px; }
    .sv-t th, .sv-t td { padding:6px 8px; border-bottom:1px solid rgba(127,127,127,.15); text-align:right; white-space:nowrap; }
    .sv-t th { font-size:12px; opacity:.7; }
</style>

@if($s->isCancelled())
    <div class="sv-card" style="border-color:#dc2626;color:#dc2626;font-weight:700">
        ملغية {{ $s->cancelled_at->format('Y-m-d H:i') }} — {{ $s->cancel_reason }}
    </div>
@endif

<div class="sv-card">
    <div class="sv-grid">
        <div><div class="sv-k">الحساب</div><div class="sv-v">{{ $s->partyName() }}</div><div class="sv-k">{{ $s->partyLabel() }} · {{ $s->user?->phone }}</div></div>
        <div><div class="sv-k">العملية</div><div class="sv-v" style="color:{{ $s->direction === 'pay' ? '#dc2626' : '#16a34a' }}">{{ $s->direction === 'pay' ? 'صرفنا له' : 'استلمنا منه' }}</div></div>
        <div><div class="sv-k">المبلغ</div><div class="sv-v">{{ $fmt($s->amount) }} د.ل</div><div class="sv-k">{{ $s->methodLabel() }} @if($s->reference) · {{ $s->reference }} @endif</div></div>
        <div><div class="sv-k">الرصيد</div><div class="sv-v">{{ $fmt($s->balance_before) }} ← {{ $fmt($s->balance_after) }}</div><div class="sv-k">موجب = له · سالب = عليه</div></div>
        <div><div class="sv-k">الفترة</div><div class="sv-v" style="font-size:14px">{{ $s->period_from?->format('Y-m-d') ?? '—' }} ← {{ $s->period_to?->format('Y-m-d') }}</div></div>
        <div><div class="sv-k">بواسطة</div><div class="sv-v" style="font-size:14px">{{ $s->creator?->name ?? '—' }}</div><div class="sv-k">{{ $s->created_at->format('Y-m-d H:i') }}</div></div>
    </div>
    @if($s->note) <div class="sv-k" style="margin-top:10px">ملاحظة: {{ $s->note }}</div> @endif
</div>

<div class="sv-card">
    <div style="font-weight:700;margin-bottom:8px">ملخص الطلبات في التسوية ({{ $s->orders_count }})</div>
    <div class="sv-grid">
        <div><div class="sv-k">دفعه الزبائن</div><div class="sv-v">{{ $fmt($sum['customer_total'] ?? 0) }}</div></div>
        @if($isStore)
            <div><div class="sv-k">قيمة الأصناف</div><div class="sv-v">{{ $fmt($sum['subtotal'] ?? 0) }}</div></div>
            <div><div class="sv-k">عمولة المنصة</div><div class="sv-v">{{ $fmt($sum['commission'] ?? 0) }}</div></div>
            <div><div class="sv-k">صافي المتجر</div><div class="sv-v">{{ $fmt($sum['store_net'] ?? 0) }}</div></div>
        @else
            <div><div class="sv-k">كاش حصّله</div><div class="sv-v">{{ $fmt($sum['cash'] ?? 0) }}</div></div>
            <div><div class="sv-k">أجرة التوصيل</div><div class="sv-v">{{ $fmt($sum['driver_earning'] ?? 0) }}</div></div>
            <div><div class="sv-k">الصافي من الطلبات</div><div class="sv-v">{{ $fmt(($sum['driver_earning'] ?? 0) - ($sum['cash'] ?? 0)) }}</div></div>
        @endif
    </div>
    @if(! empty($sum['other']))
        <div style="font-weight:700;margin:12px 0 6px">حركات ثانية في الفترة</div>
        <table class="sv-t">
            @foreach($sum['other'] as $t)
                <tr><td>{{ $t['at'] ?? '' }}</td><td>{{ $t['type'] }}</td><td>{{ $t['note'] }}</td><td>{{ $fmt($t['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif
</div>

<div class="sv-card" style="overflow:auto">
    <div style="font-weight:700;margin-bottom:8px">الطلبات</div>
    <table class="sv-t">
        <tr><th>الطلب</th><th>التسليم</th><th>{{ $isStore ? 'السائق' : 'المتجر' }}</th><th>على الزبون</th>
            @if($isStore)<th>الأصناف</th><th>العمولة</th><th>الصافي</th>@else<th>كاش</th><th>الأجرة</th>@endif</tr>
        @forelse($orders as $o)
            @php $cash = $o->payment_method?->value === 'cash' ? max(0, $o->total - $o->wallet_paid) : 0; @endphp
            <tr>
                <td><a href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $o]) }}" style="text-decoration:underline">{{ $o->code }}</a></td>
                <td>{{ $o->delivered_at?->format('Y-m-d H:i') }}</td>
                <td>{{ $isStore ? $o->driver?->name : $o->store?->name }}</td>
                <td>{{ $fmt($o->total) }}</td>
                @if($isStore)<td>{{ $fmt($o->subtotal) }}</td><td>{{ $fmt($o->commission_amount) }}</td><td>{{ $fmt($o->store_earning) }}</td>
                @else<td>{{ $fmt($cash) }}</td><td>{{ $fmt($o->driver_earning) }}</td>@endif
            </tr>
        @empty
            <tr><td colspan="7" style="opacity:.6">{{ $s->isCancelled() ? 'الطلبات رجعت «ما تسوّتش» بعد الإلغاء.' : 'تسوية على الرصيد بدون طلبات جديدة.' }}</td></tr>
        @endforelse
    </table>
</div>
</x-filament-panels::page>
