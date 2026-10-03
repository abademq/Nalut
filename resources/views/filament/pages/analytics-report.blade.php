<x-filament-panels::page>
@php
    $fmt = fn ($v, $s = '') => $v === null ? '—' : $v.$s;
    $mn = fn ($v) => $v === null ? '—' : round($v).' دقيقة';
    $money = fn ($v) => number_format((float) $v, 2).' د.ل';
    $tile = 'padding:14px;border-radius:12px;border:1px solid rgba(127,127,127,.2)';
    $big = 'font-size:26px;font-weight:800;line-height:1.2';
    $lbl = 'font-size:12px;opacity:.7';
    $grid = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px';
    $maxDay = max(1, collect($r['daily'])->max('orders'));
    $maxHour = max(1, max($r['hourly']));
@endphp

{{-- الفترة --}}
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    @foreach (['today' => 'اليوم', '7' => 'آخر 7 أيام', '30' => 'آخر 30 يوم', 'month' => 'هذا الشهر', '90' => 'آخر 90 يوم'] as $k => $label)
        <x-filament::button size="sm" :color="$preset === $k && ! $from ? 'primary' : 'gray'" wire:click="setPreset('{{ $k }}')">{{ $label }}</x-filament::button>
    @endforeach
    <span style="opacity:.6;margin-inline:6px">أو من</span>
    <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
    <span style="opacity:.6">لين</span>
    <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
    <span style="opacity:.6;font-size:12px">{{ $r['from'] }} ← {{ $r['to'] }} ({{ $r['days'] }} يوم)</span>
</div>

<x-filament::section heading="الطلبات">
    <div style="{{ $grid }}">
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $r['orders']['total'] }}</div><div style="{{ $lbl }}">كل الطلبات · {{ $r['orders']['per_day'] }} في اليوم</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }};color:#16a34a">{{ $fmt($r['orders']['completion_rate'], '%') }}</div><div style="{{ $lbl }}">مكتملة ({{ $r['orders']['delivered'] }})</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }};color:#d97706">{{ $fmt($r['orders']['cancel_rate'], '%') }}</div><div style="{{ $lbl }}">ملغية ({{ $r['orders']['cancelled'] }}) — زبون {{ $r['orders']['cancel_by']['customer'] }} · متجر {{ $r['orders']['cancel_by']['store'] }} · إدارة {{ $r['orders']['cancel_by']['admin'] }} · النظام {{ $r['orders']['cancel_by']['system'] }}</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }};color:#dc2626">{{ $fmt($r['orders']['fail_rate'], '%') }}</div><div style="{{ $lbl }}">فشل التسليم ({{ $r['orders']['failed'] }})</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $fmt($r['orders']['pickup_share'], '%') }}</div><div style="{{ $lbl }}">استلام من المطعم</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $r['orders']['left_at_door'] }}</div><div style="{{ $lbl }}">تُركت أمام الباب</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $r['orders']['payment']['cash'] }} / {{ $r['orders']['payment']['wallet'] }} / {{ $r['orders']['payment']['card'] }}</div><div style="{{ $lbl }}">الدفع: نقدي / محفظة / إلكتروني</div></div>
    </div>

    <div style="margin-top:18px;font-weight:700">الطلبات في اليوم</div>
    <div style="display:flex;align-items:flex-end;gap:3px;height:140px;margin-top:8px;overflow-x:auto">
        @foreach ($r['daily'] as $d)
            <div title="{{ $d['date'] }}: {{ $d['orders'] }} طلب · {{ $d['delivered'] }} مكتمل" style="flex:1;min-width:8px;display:flex;flex-direction:column;justify-content:flex-end;height:100%">
                <div style="height:{{ round(100 * $d['orders'] / $maxDay) }}%;background:#FF7900;border-radius:4px 4px 0 0;opacity:.85;min-height:{{ $d['orders'] ? 2 : 0 }}px"></div>
            </div>
        @endforeach
    </div>
    <div style="display:flex;justify-content:space-between;font-size:11px;opacity:.6;direction:ltr"><span>{{ $r['daily'][0]['date'] ?? '' }}</span><span>{{ end($r['daily'])['date'] ?? '' }}</span></div>

    <div style="margin-top:18px;font-weight:700">أوقات الذروة (حسب ساعة الطلب)</div>
    <div style="display:flex;align-items:flex-end;gap:3px;height:110px;margin-top:8px">
        @foreach ($r['hourly'] as $h => $c)
            <div title="الساعة {{ $h }}: {{ $c }} طلب" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%">
                <div style="height:{{ round(100 * $c / $maxHour) }}%;background:#075C52;border-radius:4px 4px 0 0;min-height:{{ $c ? 2 : 0 }}px"></div>
            </div>
        @endforeach
    </div>
    <div style="display:flex;justify-content:space-between;font-size:11px;opacity:.6;direction:ltr"><span>0</span><span>6</span><span>12</span><span>18</span><span>23</span></div>
</x-filament::section>

<x-filament::section heading="السرعة والأوقات (طلبات التوصيل المكتملة)">
    <div style="{{ $grid }}">
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $mn($r['times']['total']) }}</div><div style="{{ $lbl }}">متوسط من الطلب للتسليم · الوسيط {{ $mn($r['times']['median_total']) }}</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $fmt($r['times']['within_45'], '%') }}</div><div style="{{ $lbl }}">وصلت خلال 45 دقيقة (60 دقيقة: {{ $fmt($r['times']['within_60'], '%') }})</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $mn($r['times']['accept']) }}</div><div style="{{ $lbl }}">لين المتجر يقبل</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $mn($r['times']['prep']) }}</div><div style="{{ $lbl }}">التحضير</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $mn($r['times']['wait_driver']) }}</div><div style="{{ $lbl }}">جاهز ← استلمه السائق</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $mn($r['times']['road']) }}</div><div style="{{ $lbl }}">في الطريق للزبون</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $fmt($r['times']['avg_speed_kmh'], ' كم/س') }}</div><div style="{{ $lbl }}">متوسط سرعة التوصيل · مسافة {{ $fmt($r['times']['avg_distance_km'], ' كم') }}</div></div>
    </div>
</x-filament::section>

<x-filament::section heading="الزبائن والرجوع للتطبيق">
    <div style="{{ $grid }}">
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $r['customers']['new_signups'] }}</div><div style="{{ $lbl }}">حسابات جديدة</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $r['customers']['ordering'] }}</div><div style="{{ $lbl }}">زبائن طلبو · {{ $r['customers']['orders_per_customer'] }} طلب للزبون</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $fmt($r['customers']['returning_rate'], '%') }}</div><div style="{{ $lbl }}">زبائن راجعين (طلبو قبل الفترة كمان) — {{ $r['customers']['returning'] }}</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $fmt($r['customers']['repeat_in_period'], '%') }}</div><div style="{{ $lbl }}">طلبو مرتين أو أكثر في الفترة</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $fmt($r['customers']['retention_30'], '%') }}</div><div style="{{ $lbl }}">رجعو خلال 30 يوم من أول طلب ({{ $r['customers']['new_buyers'] }} زبون جديد)</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $fmt($r['ratings']['store']) }} / {{ $fmt($r['ratings']['driver']) }}</div><div style="{{ $lbl }}">تقييم المتاجر / السائقين · {{ $fmt($r['ratings']['rated_share'], '%') }} من الطلبات اتقيّمت</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $r['referrals']['applied'] }} / {{ $r['referrals']['rewarded'] }}</div><div style="{{ $lbl }}">دعوة صديق: انضمّو / كمّلو أول طلب</div></div>
    </div>
</x-filament::section>

@if ($money)
<x-filament::section heading="الفلوس (الطلبات المكتملة)">
    <div style="{{ $grid }}">
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $money($r['money']['sales']) }}</div><div style="{{ $lbl }}">المبيعات</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $money($r['money']['avg_order_value']) }}</div><div style="{{ $lbl }}">متوسط قيمة الطلب</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $money($r['money']['commission']) }}</div><div style="{{ $lbl }}">عمولة المتاجر</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $money($r['money']['delivery_paid_by_customers']) }}</div><div style="{{ $lbl }}">رسوم توصيل دفعها الزبائن</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }};color:#d97706">{{ $money($r['money']['delivery_subsidy']) }}</div><div style="{{ $lbl }}">دعم التوصيل (دفعته الشركة)</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $money($r['money']['driver_earnings']) }}</div><div style="{{ $lbl }}">أجور السائقين</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }}">{{ $money($r['money']['discounts']) }}</div><div style="{{ $lbl }}">خصومات (كوبونات ونقاط)</div></div>
        <div style="{{ $tile }}"><div style="{{ $big }};color:{{ $r['money']['platform_net'] >= 0 ? '#16a34a' : '#dc2626' }}">{{ $money($r['money']['platform_net']) }}</div><div style="{{ $lbl }}">صافي المنصة</div></div>
    </div>
</x-filament::section>
@endif

@php
    $table = function (array $rows, array $cols) {
        return [$rows, $cols];
    };
    $th = 'text-align:right;padding:6px;font-size:12px;opacity:.7;border-bottom:1px solid rgba(127,127,127,.25)';
    $td = 'padding:6px;border-bottom:1px solid rgba(127,127,127,.12)';
@endphp

<x-filament::section heading="أقسام التطبيق">
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
        <tr><th style="{{ $th }}">القسم</th><th style="{{ $th }}">الطلبات</th><th style="{{ $th }}">مكتملة</th><th style="{{ $th }}">إلغاء</th><th style="{{ $th }}">متوسط التحضير</th><th style="{{ $th }}">متوسط الكلّي</th>@if($money)<th style="{{ $th }}">المبيعات</th><th style="{{ $th }}">متوسط الطلب</th>@endif</tr>
        @forelse ($r['sections'] as $s)
            <tr><td style="{{ $td }}"><b>{{ $s['name'] }}</b></td><td style="{{ $td }}">{{ $s['orders'] }}</td><td style="{{ $td }}">{{ $s['delivered'] }}</td><td style="{{ $td }}">{{ $fmt($s['cancel_rate'], '%') }}</td><td style="{{ $td }}">{{ $mn($s['avg_prep_minutes']) }}</td><td style="{{ $td }}">{{ $mn($s['avg_total_minutes']) }}</td>@if($money)<td style="{{ $td }}">{{ $money($s['sales']) }}</td><td style="{{ $td }}">{{ $money($s['avg_order_value']) }}</td>@endif</tr>
        @empty <tr><td style="{{ $td }}" colspan="8">ما فيش طلبات في الفترة</td></tr> @endforelse
    </table></div>
</x-filament::section>

<x-filament::section heading="المتاجر (الأكثر طلبات)" collapsible>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
        <tr><th style="{{ $th }}">المتجر</th><th style="{{ $th }}">الطلبات</th><th style="{{ $th }}">مكتملة</th><th style="{{ $th }}">إلغاء</th><th style="{{ $th }}">التحضير</th><th style="{{ $th }}">التقييم</th>@if($money)<th style="{{ $th }}">المبيعات</th>@endif</tr>
        @forelse ($r['stores'] as $s)
            <tr><td style="{{ $td }}">{{ $s['name'] }}</td><td style="{{ $td }}">{{ $s['orders'] }}</td><td style="{{ $td }}">{{ $s['delivered'] }}</td><td style="{{ $td }}">{{ $fmt($s['cancel_rate'], '%') }}</td><td style="{{ $td }}">{{ $mn($s['avg_prep_minutes']) }}</td><td style="{{ $td }}">{{ $s['rating'] ? '⭐ '.$s['rating'] : '—' }}</td>@if($money)<td style="{{ $td }}">{{ $money($s['sales']) }}</td>@endif</tr>
        @empty <tr><td style="{{ $td }}" colspan="7">—</td></tr> @endforelse
    </table></div>
</x-filament::section>

<x-filament::section heading="السائقين (الأكثر توصيل)" collapsible>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
        <tr><th style="{{ $th }}">السائق</th><th style="{{ $th }}">مكتملة</th><th style="{{ $th }}">فشل</th><th style="{{ $th }}">انتظار عند المتجر</th><th style="{{ $th }}">وقت الطريق</th><th style="{{ $th }}">التقييم</th>@if($money)<th style="{{ $th }}">الأجور</th>@endif</tr>
        @forelse ($r['drivers'] as $d)
            <tr><td style="{{ $td }}">{{ $d['name'] }}</td><td style="{{ $td }}">{{ $d['delivered'] }}</td><td style="{{ $td }}">{{ $d['failed'] }}</td><td style="{{ $td }}">{{ $mn($d['avg_pickup_wait_minutes']) }}</td><td style="{{ $td }}">{{ $mn($d['avg_road_minutes']) }}</td><td style="{{ $td }}">{{ $d['rating'] ? '⭐ '.$d['rating'] : '—' }}</td>@if($money)<td style="{{ $td }}">{{ $money($d['earnings']) }}</td>@endif</tr>
        @empty <tr><td style="{{ $td }}" colspan="7">—</td></tr> @endforelse
    </table></div>
</x-filament::section>

@if (count($r['referrals']['top']))
<x-filament::section heading="أكثر الزبائن دعوةً للأصدقاء" collapsible collapsed>
    <table style="width:100%;border-collapse:collapse">
        <tr><th style="{{ $th }}">الزبون</th><th style="{{ $th }}">دعا</th><th style="{{ $th }}">كمّلو أول طلب</th></tr>
        @foreach ($r['referrals']['top'] as $t)
            <tr><td style="{{ $td }}">{{ $t['name'] }}</td><td style="{{ $td }}">{{ $t['invited'] }}</td><td style="{{ $td }}">{{ $t['rewarded'] }}</td></tr>
        @endforeach
    </table>
</x-filament::section>
@endif
</x-filament-panels::page>
