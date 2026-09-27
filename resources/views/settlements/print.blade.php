@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $sum = $s->summary ?? [];
    $isStore = $s->party === 'store';
    $narrow = $paper === '80';
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>واصل تسوية {{ $s->number }}</title>
<style>
    @page { size: {{ $narrow ? '80mm auto' : 'A4' }}; margin: {{ $narrow ? '3mm' : '12mm' }}; }
    * { box-sizing: border-box; }
    body { font-family: Tahoma, 'Segoe UI', Arial, sans-serif; color:#000; background:#fff; margin:0; }
    .page { width: {{ $narrow ? '74mm' : '100%' }}; max-width: {{ $narrow ? '74mm' : '190mm' }}; margin: 0 auto; padding: {{ $narrow ? '2mm' : '0' }}; font-size: {{ $narrow ? '12px' : '14px' }}; }
    .c { text-align:center; } .b { font-weight:bold; }
    .logo { max-height: {{ $narrow ? '48px' : '70px' }}; max-width:60%; filter: grayscale(1); }
    h1 { font-size: {{ $narrow ? '17px' : '22px' }}; margin:6px 0 2px; }
    .box { border:1.5px solid #000; border-radius:6px; padding:6px 8px; margin:8px 0; }
    .row { display:flex; justify-content:space-between; gap:8px; padding:2px 0; }
    .row span:last-child { text-align:left; font-weight:bold; white-space:nowrap; }
    .amount { font-size: {{ $narrow ? '22px' : '28px' }}; font-weight:900; text-align:center; margin:4px 0; }
    .words { text-align:center; font-size: {{ $narrow ? '11px' : '13px' }}; }
    .dash { border-top:1px dashed #000; margin:8px 0; }
    table { width:100%; border-collapse:collapse; font-size:12px; }
    th, td { border:1px solid #000; padding:3px 5px; text-align:right; }
    th { background:#eee; }
    .sign { display:flex; gap:12px; margin-top:{{ $narrow ? '18px' : '40px' }}; }
    .sign div { flex:1; text-align:center; border-top:1px solid #000; padding-top:4px; font-size:12px; }
    .cancel { border:3px solid #000; padding:6px; text-align:center; font-weight:900; font-size:18px; margin:8px 0; }
    .noprint { text-align:center; margin:14px 0; }
    .noprint button { padding:10px 22px; font-size:15px; border-radius:8px; border:0; background:#d84315; color:#fff; font-family:inherit; cursor:pointer; }
    @media print { .noprint { display:none; } }
</style>
</head>
<body>
<div class="page">
    <div class="c">
        @if($logo) <img class="logo" src="{{ $logo }}" alt=""> @endif
        <div class="b">{{ $app }}</div>
        <div style="font-size:11px">{{ $company }}</div>
        <h1>واصل تسوية {{ $isStore ? 'متجر' : 'سائق' }}</h1>
        <div class="b">{{ $s->number }}</div>
        <div style="font-size:11px">{{ $s->created_at->timezone('Africa/Tripoli')->format('Y-m-d H:i') }}</div>
    </div>

    @if($s->isCancelled())
        <div class="cancel">ملغية — {{ $s->cancel_reason }}</div>
    @endif

    <div class="box">
        <div class="row"><span>{{ $isStore ? 'المتجر' : 'السائق' }}</span><span>{{ $isStore && $s->store ? $s->store->name : $s->user?->name }}</span></div>
        @if($isStore) <div class="row"><span>صاحب المتجر</span><span>{{ $s->user?->name }}</span></div> @endif
        <div class="row"><span>الهاتف</span><span dir="ltr">{{ $s->user?->phone }}</span></div>
        <div class="row"><span>الفترة</span><span>{{ $s->period_from?->format('Y-m-d') ?? '—' }} ← {{ $s->period_to?->format('Y-m-d') }}</span></div>
    </div>

    <div class="box">
        <div class="c b">{{ $s->direction === 'pay' ? 'صرفنا إلى '.($isStore ? 'المتجر' : 'السائق').' مبلغ' : 'استلمنا من '.($isStore ? 'المتجر' : 'السائق').' مبلغ' }}</div>
        <div class="amount">{{ $fmt($s->amount) }} د.ل</div>
        <div class="words">{{ \App\Support\ArabicAmount::words($s->amount) }}</div>
        <div class="dash"></div>
        <div class="row"><span>طريقة الدفع</span><span>{{ $s->methodLabel() }}</span></div>
        @if($s->reference) <div class="row"><span>رقم مرجعي</span><span>{{ $s->reference }}</span></div> @endif
    </div>

    <div class="box">
        <div class="b" style="margin-bottom:4px">تفصيل الحساب</div>
        <div class="row"><span>عدد الطلبات</span><span>{{ $s->orders_count }}</span></div>
        <div class="row"><span>إجمالي ما دفعه الزبائن</span><span>{{ $fmt($sum['customer_total'] ?? 0) }}</span></div>
        @if($isStore)
            <div class="row"><span>قيمة الأصناف</span><span>{{ $fmt($sum['subtotal'] ?? 0) }}</span></div>
            <div class="row"><span>− عمولة المنصة</span><span>{{ $fmt($sum['commission'] ?? 0) }}</span></div>
            <div class="row"><span>= صافي المتجر من الطلبات</span><span>{{ $fmt($sum['store_net'] ?? 0) }}</span></div>
        @else
            <div class="row"><span>كاش حصّله من الزبائن</span><span>{{ $fmt($sum['cash'] ?? 0) }}</span></div>
            <div class="row"><span>أجرة التوصيل</span><span>{{ $fmt($sum['driver_earning'] ?? 0) }}</span></div>
            <div class="row"><span>= الصافي من الطلبات</span><span>{{ $fmt(($sum['driver_earning'] ?? 0) - ($sum['cash'] ?? 0)) }}</span></div>
        @endif
        @foreach(($sum['other'] ?? []) as $t)
            <div class="row"><span>{{ $t['type'] }}{{ $t['note'] ? ' — '.$t['note'] : '' }}</span><span>{{ $fmt($t['amount']) }}</span></div>
        @endforeach
        <div class="dash"></div>
        <div class="row"><span>الرصيد قبل التسوية</span><span>{{ $fmt($s->balance_before) }} {{ $s->balance_before > 0 ? '(له)' : ($s->balance_before < 0 ? '(عليه)' : '') }}</span></div>
        <div class="row"><span>{{ $s->direction === 'pay' ? '− المصروف' : '+ المستلم' }}</span><span>{{ $fmt($s->amount) }}</span></div>
        <div class="row b"><span>الرصيد بعد التسوية</span><span>{{ $fmt($s->balance_after) }} {{ $s->balance_after > 0 ? '(له)' : ($s->balance_after < 0 ? '(عليه)' : '') }}</span></div>
    </div>

    @if($orders->isNotEmpty())
        <div class="b" style="margin:8px 0 4px">الطلبات</div>
        <table>
            <tr><th>#</th><th>الطلب</th><th>التاريخ</th><th>على الزبون</th>
                @if($isStore)<th>الأصناف</th><th>العمولة</th><th>الصافي</th>@else<th>كاش</th><th>الأجرة</th>@endif</tr>
            @foreach($orders as $i => $o)
                @php $cash = $o->payment_method?->value === 'cash' ? max(0, $o->total - $o->wallet_paid) : 0; @endphp
                <tr><td>{{ $i + 1 }}</td><td>{{ $o->code }}</td><td>{{ $o->delivered_at?->format('Y-m-d') }}</td><td>{{ $fmt($o->total) }}</td>
                    @if($isStore)<td>{{ $fmt($o->subtotal) }}</td><td>{{ $fmt($o->commission_amount) }}</td><td>{{ $fmt($o->store_earning) }}</td>
                    @else<td>{{ $fmt($cash) }}</td><td>{{ $fmt($o->driver_earning) }}</td>@endif</tr>
            @endforeach
        </table>
    @endif

    @if($s->note) <div style="margin-top:6px">ملاحظة: {{ $s->note }}</div> @endif

    <div class="sign">
        <div>توقيع {{ $s->direction === 'pay' ? 'المستلم' : 'المسلِّم' }}<br>{{ $isStore && $s->store ? $s->store->name : $s->user?->name }}</div>
        <div>توقيع المسؤول<br>{{ $s->creator?->name }}</div>
    </div>

    <div class="c" style="font-size:11px;margin-top:10px">{{ $footer }}</div>

    <div class="noprint">
        <button onclick="window.print()">طباعة</button>
    </div>
</div>
@if($auto)
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
@endif
</body>
</html>
