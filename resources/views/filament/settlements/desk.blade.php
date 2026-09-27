@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $isStore = $party === 'store';
@endphp
<x-filament-panels::page>
<style>
    .sd-wrap { display:grid; gap:14px; grid-template-columns: minmax(260px, 340px) 1fr; }
    @media (max-width: 1000px) { .sd-wrap { grid-template-columns: 1fr; } }
    .sd-card { border:1px solid rgba(127,127,127,.25); border-radius:12px; padding:14px; background:rgba(127,127,127,.04); }
    .sd-tabs { display:flex; gap:6px; margin-bottom:10px; }
    .sd-tab { flex:1; padding:8px; border-radius:10px; border:1px solid rgba(127,127,127,.3); font-weight:600; }
    .sd-tab.on { background:#f59e0b; border-color:#f59e0b; color:#fff; }
    .sd-acc { display:flex; justify-content:space-between; gap:8px; padding:9px 8px; border-radius:10px; cursor:pointer; border:1px solid transparent; }
    .sd-acc:hover { background:rgba(127,127,127,.08); }
    .sd-acc.on { border-color:#f59e0b; background:rgba(245,158,11,.08); }
    .sd-s { font-size:12px; opacity:.7; }
    .sd-pos { color:#dc2626; font-weight:700; } /* المنصة تدفع */
    .sd-neg { color:#16a34a; font-weight:700; } /* المنصة تستلم */
    .sd-grid { display:grid; gap:10px; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); }
    .sd-k { font-size:12px; opacity:.7; } .sd-v { font-size:20px; font-weight:800; }
    .sd-t { width:100%; border-collapse:collapse; font-size:13px; }
    .sd-t th, .sd-t td { padding:6px 8px; border-bottom:1px solid rgba(127,127,127,.15); text-align:right; white-space:nowrap; }
    .sd-t th { font-size:12px; opacity:.7; }
    .sd-t tfoot td { font-weight:800; border-top:2px solid rgba(127,127,127,.4); }
    .sd-calc div { display:flex; justify-content:space-between; padding:4px 0; border-bottom:1px dashed rgba(127,127,127,.2); }
    .sd-calc .tot { font-weight:800; border-bottom:0; font-size:15px; }
</style>

<div class="sd-wrap">
    {{-- الحسابات --}}
    <div class="sd-card">
        <div class="sd-tabs">
            <button type="button" wire:click="setParty('store')" class="sd-tab {{ $isStore ? 'on' : '' }}">المتاجر</button>
            <button type="button" wire:click="setParty('driver')" class="sd-tab {{ ! $isStore ? 'on' : '' }}">السائقين</button>
        </div>
        <div class="sd-grid" style="margin-bottom:10px">
            <div><div class="sd-k">المنصة تدفعلهم</div><div class="sd-pos">{{ $fmt($totals['pay']) }} د.ل</div></div>
            <div><div class="sd-k">المنصة تستلم منهم</div><div class="sd-neg">{{ $fmt($totals['receive']) }} د.ل</div></div>
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="بحث بالاسم أو الرقم"
               style="width:100%;padding:8px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.3);background:transparent;margin-bottom:6px">
        <label class="sd-s" style="display:flex;gap:6px;align-items:center;margin-bottom:8px">
            <input type="checkbox" wire:model.live="onlyDue"> اللي عندهم رصيد أو طلبات ما تسوّتش بس
        </label>
        <div style="max-height:65vh;overflow:auto">
            @forelse($accounts as $a)
                <div wire:click="selectAccount({{ $a['id'] }})" class="sd-acc {{ $userId === $a['id'] ? 'on' : '' }}">
                    <div style="min-width:0">
                        <div style="font-weight:600">{{ $a['name'] }}</div>
                        <div class="sd-s">{{ $a['sub'] }}</div>
                        <div class="sd-s">{{ $a['orders'] }} طلب ما تسوّاش @if($a['last']) · آخر تسوية {{ \Illuminate\Support\Carbon::parse($a['last'])->format('Y-m-d') }} @endif</div>
                    </div>
                    <div style="text-align:left">
                        @if($a['balance'] > 0.004)
                            <div class="sd-pos">{{ $fmt($a['balance']) }}</div><div class="sd-s">له</div>
                        @elseif($a['balance'] < -0.004)
                            <div class="sd-neg">{{ $fmt(abs($a['balance'])) }}</div><div class="sd-s">عليه</div>
                        @else
                            <div class="sd-s">متسوّي</div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="sd-s" style="padding:20px;text-align:center">ما فيش حسابات.</div>
            @endforelse
        </div>
    </div>

    {{-- الكشف --}}
    <div>
        @if(! $statement)
            <div class="sd-card" style="text-align:center;padding:50px">
                <div style="font-size:18px;font-weight:700">اختار {{ $isStore ? 'متجر' : 'سائق' }} من القائمة</div>
                <div class="sd-s" style="margin-top:6px">تطلعلك الطلبات اللي ما تسوّتش، كيف انحسب الرصيد، وزر التسوية مع طباعة الواصل.</div>
            </div>
        @else
            @php $st = $statement; $sum = $st['sum']; @endphp
            <div class="sd-card" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <div style="flex:1;min-width:220px">
                    <div style="font-size:20px;font-weight:800">{{ $isStore && $account->store ? $account->store->name : $account->name }}</div>
                    <div class="sd-s">{{ $account->name }} · {{ $account->phone }}
                        @if($st['last']) · آخر تسوية <a href="{{ \App\Filament\Resources\Settlements\Pages\ViewSettlement::getUrl(['record' => $st['last']]) }}" style="text-decoration:underline">{{ $st['last']->number }}</a> ({{ $st['last']->created_at->format('Y-m-d') }}) @endif
                    </div>
                </div>
                <div style="text-align:center;padding:6px 16px;border-radius:12px;background:{{ $st['direction'] === 'pay' ? 'rgba(220,38,38,.08)' : ($st['direction'] === 'receive' ? 'rgba(22,163,74,.08)' : 'rgba(127,127,127,.08)') }}">
                    <div class="sd-k">{{ ['pay' => 'المنصة تدفعله', 'receive' => 'المنصة تستلم منه', 'none' => 'الرصيد'][$st['direction']] }}</div>
                    <div class="sd-v {{ $st['direction'] === 'pay' ? 'sd-pos' : ($st['direction'] === 'receive' ? 'sd-neg' : '') }}">{{ $fmt($st['due']) }} د.ل</div>
                </div>
                @if($canManage) <div>{{ $this->settleAction }}</div> @endif
            </div>

            <div class="sd-grid" style="margin-top:12px">
                <div class="sd-card"><div class="sd-k">طلبات ما تسوّتش</div><div class="sd-v">{{ $sum['orders'] }}</div>
                    <div class="sd-s">@if($st['from']) من {{ \Illuminate\Support\Carbon::parse($st['from'])->format('Y-m-d') }} @endif</div></div>
                <div class="sd-card"><div class="sd-k">دفعه الزبائن (الإجمالي)</div><div class="sd-v">{{ $fmt($sum['customer_total']) }}</div></div>
                @if($isStore)
                    <div class="sd-card"><div class="sd-k">قيمة الأصناف</div><div class="sd-v">{{ $fmt($sum['subtotal']) }}</div></div>
                    <div class="sd-card"><div class="sd-k">عمولة المنصة</div><div class="sd-v">{{ $fmt($sum['commission']) }}</div></div>
                    <div class="sd-card"><div class="sd-k">صافي المتجر</div><div class="sd-v sd-pos">{{ $fmt($sum['store_net']) }}</div></div>
                @else
                    <div class="sd-card"><div class="sd-k">كاش حصّله</div><div class="sd-v sd-neg">{{ $fmt($sum['cash']) }}</div></div>
                    <div class="sd-card"><div class="sd-k">أجرة التوصيل</div><div class="sd-v sd-pos">{{ $fmt($sum['driver_earning']) }}</div></div>
                @endif
            </div>

            <div class="sd-card" style="margin-top:12px">
                <div style="font-weight:700;margin-bottom:8px">كيف انحسب الرصيد</div>
                <div class="sd-calc">
                    @php
                        $ordersEffect = $isStore ? $sum['store_net'] : $sum['driver_earning'] - $sum['cash'];
                        $otherEffect = $st['other']->sum(fn ($t) => (float) $t->amount);
                        $prev = round($st['balance'] - $ordersEffect - $otherEffect, 2);
                    @endphp
                    <div><span>رصيد قبل الطلبات هذي (من التسويات السابقة)</span><span>{{ $fmt($prev) }}</span></div>
                    @if($isStore)
                        <div><span>+ صافي {{ $sum['orders'] }} طلب (الأصناف {{ $fmt($sum['subtotal']) }} − العمولة {{ $fmt($sum['commission']) }})</span><span>{{ $fmt($sum['store_net']) }}</span></div>
                    @else
                        <div><span>+ أجرة التوصيل لـ {{ $sum['orders'] }} طلب</span><span>{{ $fmt($sum['driver_earning']) }}</span></div>
                        <div><span>− الكاش اللي حصّله من الزبائن</span><span>{{ $fmt(-$sum['cash']) }}</span></div>
                    @endif
                    @foreach($st['other'] as $t)
                        <div><span>{{ $t->typeLabel() }} @if($t->note) — {{ $t->note }} @endif <span class="sd-s">({{ $t->created_at->format('Y-m-d') }})</span></span><span>{{ $fmt($t->amount) }}</span></div>
                    @endforeach
                    <div class="tot"><span>الرصيد الحالي {{ $st['balance'] > 0 ? '(له)' : ($st['balance'] < 0 ? '(عليه)' : '') }}</span><span>{{ $fmt($st['balance']) }} د.ل</span></div>
                </div>
            </div>

            <div class="sd-card" style="margin-top:12px;overflow:auto">
                <div style="font-weight:700;margin-bottom:8px">الطلبات اللي ما تسوّتش ({{ $sum['orders'] }})</div>
                <table class="sd-t">
                    <thead><tr>
                        <th>الطلب</th><th>التسليم</th>@if(! $isStore)<th>المتجر</th>@endif
                        <th>الدفع</th><th>على الزبون</th>
                        @if($isStore)<th>الأصناف</th><th>العمولة</th><th>صافي المتجر</th>
                        @else<th>كاش حصّله</th><th>أجرته</th><th>الصافي</th>@endif
                    </tr></thead>
                    <tbody>
                    @forelse($st['orders'] as $o)
                        @php $cash = $o->payment_method?->value === 'cash' ? max(0, $o->total - $o->wallet_paid) : 0; @endphp
                        <tr>
                            <td><a href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $o]) }}" style="text-decoration:underline">{{ $o->code }}</a></td>
                            <td>{{ $o->delivered_at?->format('m-d H:i') }}</td>
                            @if(! $isStore)<td>{{ $o->store?->name }}</td>@endif
                            <td>{{ ['cash' => 'نقداً', 'wallet' => 'محفظة', 'card' => 'إلكتروني'][$o->payment_method?->value] ?? '' }}</td>
                            <td>{{ $fmt($o->total) }}</td>
                            @if($isStore)
                                <td>{{ $fmt($o->subtotal) }}</td><td>{{ $fmt($o->commission_amount) }}</td><td style="font-weight:700">{{ $fmt($o->store_earning) }}</td>
                            @else
                                <td>{{ $fmt($cash) }}</td><td>{{ $fmt($o->driver_earning) }}</td><td style="font-weight:700">{{ $fmt($o->driver_earning - $cash) }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="8" class="sd-s" style="text-align:center;padding:16px">ما فيش طلبات جديدة من آخر تسوية.</td></tr>
                    @endforelse
                    </tbody>
                    @if($sum['orders'])
                    <tfoot><tr>
                        <td>المجموع</td><td></td>@if(! $isStore)<td></td>@endif<td></td><td>{{ $fmt($sum['customer_total']) }}</td>
                        @if($isStore)<td>{{ $fmt($sum['subtotal']) }}</td><td>{{ $fmt($sum['commission']) }}</td><td>{{ $fmt($sum['store_net']) }}</td>
                        @else<td>{{ $fmt($sum['cash']) }}</td><td>{{ $fmt($sum['driver_earning']) }}</td><td>{{ $fmt($sum['driver_earning'] - $sum['cash']) }}</td>@endif
                    </tr></tfoot>
                    @endif
                </table>
            </div>
        @endif
    </div>
</div>
</x-filament-panels::page>
