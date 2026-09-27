@php
    $o = $getRecord();
    $n = \App\Support\OrderMoney::numbers($o);
    $lines = collect(\App\Support\OrderMoney::lines($o, 'admin'));
    $fmt = fn ($v) => number_format((float) $v, 2);
    $delivered = $o->status->value === 'delivered';
    $link = fn ($id) => $id && ($st = \App\Models\Settlement::find($id))
        ? '<a style="text-decoration:underline" href="'.\App\Filament\Resources\Settlements\Pages\ViewSettlement::getUrl(['record' => $st]).'">'.e($st->number).'</a>'
        : null;
@endphp
<style>
    .om { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
    .om-c { border:1px solid rgba(127,127,127,.25); border-radius:12px; padding:12px; }
    .om-h { font-weight:700; margin-bottom:6px; }
    .om-r { display:flex; justify-content:space-between; gap:8px; padding:4px 0; font-size:14px; border-bottom:1px dashed rgba(127,127,127,.2); }
    .om-r.total { font-weight:800; border-bottom:0; font-size:15px; }
    .om-r.minus span:last-child { color:#dc2626; }
    .om-r.muted { opacity:.7; }
    .om-r.highlight { font-weight:700; color:#b45309; }
    .om-s { font-size:12px; opacity:.7; }
</style>
<div class="om">
    @foreach(\App\Support\OrderMoney::GROUPS as $g => $title)
        <div class="om-c">
            <div class="om-h">{{ $title }}</div>
            @foreach($lines->where('group', $g) as $l)
                <div class="om-r {{ $l['style'] }}"><span>{{ $l['label'] }} @isset($l['hint'])<span class="om-s">· {{ $l['hint'] }}</span>@endisset</span><span>{{ $fmt($l['amount']) }} د.ل</span></div>
            @endforeach
        </div>
    @endforeach
    <div class="om-c">
        <div class="om-h">مين يحصّل ومين يسدد</div>
        @if($n['cash_to_collect'] > 0)
            <div class="om-r"><span>السائق حصّل نقداً من الزبون</span><span>{{ $fmt($n['cash_to_collect']) }}</span></div>
            <div class="om-r"><span>يستحق أجرته</span><span>{{ $fmt($n['driver_earning']) }}</span></div>
            <div class="om-r total"><span>{{ $n['driver_owes'] >= 0 ? 'يسلّم للمنصة' : 'المنصة تدفعله' }}</span><span>{{ $fmt(abs($n['driver_owes'])) }}</span></div>
        @else
            <div class="om-r"><span>الزبون دفع {{ $o->payment_method?->value === 'wallet' ? 'من المحفظة' : 'إلكترونياً' }} — ما فيش كاش</span><span></span></div>
            <div class="om-r total"><span>المنصة تدفع للسائق أجرته</span><span>{{ $fmt($n['driver_earning']) }}</span></div>
        @endif
        <div class="om-r total"><span>المنصة تدفع للمتجر صافيه</span><span>{{ $fmt($n['store_net']) }}</span></div>
        <div class="om-s" style="margin-top:8px">
            @if(! $delivered)
                المبالغ تدخل أرصدة المتجر والسائق بعد التسليم.
            @else
                تسوية المتجر: {!! $link($o->store_settlement_id) ?? 'لسه' !!} · تسوية السائق: {!! $o->driver_id ? ($link($o->driver_settlement_id) ?? 'لسه') : '—' !!}
            @endif
        </div>
    </div>
</div>
