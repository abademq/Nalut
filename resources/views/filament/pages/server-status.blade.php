@php
    $v = $s['verdict'];
    $colors = ['ok' => '#16a34a', 'warn' => '#d97706', 'high' => '#dc2626'];
    $c = $colors[$v['level']];
    $t = $s['traffic'];
    $bar = function ($pct) {
        $pct = max(0, min(100, (float) $pct));
        $col = $pct >= 90 ? '#dc2626' : ($pct >= 75 ? '#d97706' : '#16a34a');
        return '<div style="height:8px;border-radius:4px;background:rgba(127,127,127,.2);overflow:hidden;margin-top:6px"><div style="height:100%;width:'.$pct.'%;background:'.$col.'"></div></div>';
    };
    $na = '<span style="opacity:.5">غير متاح</span>';
    $maxMin = max(1, max($t['per_minute'] ?: [0]));
    $appLabels = ['customer' => 'تطبيق الزبون', 'store' => 'تطبيق المتجر', 'driver' => 'تطبيق السائق', 'admin' => 'لوحة التحكم', 'system' => 'زوار/نظام'];
@endphp

<x-filament-panels::page>
<style>
    .ss-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
    .ss-card { border:1px solid rgba(127,127,127,.25); border-radius:12px; padding:14px; background:rgba(127,127,127,.04); }
    .ss-k { font-size:12px; opacity:.7; }
    .ss-v { font-size:22px; font-weight:700; margin-top:2px; }
    .ss-s { font-size:12px; opacity:.7; margin-top:2px; }
    .ss-t { width:100%; border-collapse:collapse; font-size:13px; }
    .ss-t th, .ss-t td { padding:6px 8px; border-bottom:1px solid rgba(127,127,127,.15); text-align:right; }
    .ss-t th { font-weight:600; opacity:.7; font-size:12px; }
    .ss-h { font-weight:700; margin:0 0 10px; font-size:15px; }
    .ss-btn { padding:4px 10px; border-radius:8px; border:1px solid rgba(127,127,127,.3); font-size:12px; }
    .ss-btn.on { background:{{ '#f59e0b' }}; color:#fff; border-color:#f59e0b; }
    .ss-ltr { direction:ltr; unicode-bidi:embed; font-family:monospace; font-size:12px; }
</style>

<div @if($live) wire:poll.10s @endif>

    {{-- الحكم العام --}}
    <div class="ss-card" style="border-color:{{ $c }};display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <div style="width:14px;height:14px;border-radius:50%;background:{{ $c }};box-shadow:0 0 0 4px {{ $c }}33"></div>
        <div style="flex:1;min-width:220px">
            <div style="font-size:20px;font-weight:800;color:{{ $c }}">{{ $v['label'] }}</div>
            <div class="ss-s">
                @forelse($v['reasons'] as $r) • {{ $r }}<br> @empty كل القراءات في الحدود الطبيعية. @endforelse
            </div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <span class="ss-s">آخر تحديث {{ $s['at'] }}</span>
            @foreach([5 => '5 د', 15 => '15 د', 60 => 'ساعة', 180 => '3 ساعات'] as $m => $l)
                <button type="button" wire:click="setWindow({{ $m }})" class="ss-btn {{ $window === $m ? 'on' : '' }}">{{ $l }}</button>
            @endforeach
            <button type="button" wire:click="$toggle('live')" class="ss-btn">{{ $live ? '⏸ إيقاف التحديث' : '▶ تحديث تلقائي' }}</button>
        </div>
    </div>

    {{-- السيرفر --}}
    <div class="ss-grid" style="margin-top:12px">
        <div class="ss-card">
            <div class="ss-k">المعالج (توّا)</div>
            <div class="ss-v">{!! $s['cpu'] !== null ? $s['cpu'].'%' : $na !!}</div>
            @if($s['cpu'] !== null) {!! $bar($s['cpu']) !!} @endif
            <div class="ss-s">الحمل: {!! $s['load'] ? implode(' · ', $s['load']) : $na !!} ({{ $s['cores'] }} نواة)</div>
        </div>
        <div class="ss-card">
            <div class="ss-k">الذاكرة</div>
            @if($s['memory'])
                <div class="ss-v">{{ $s['memory']['percent'] }}%</div>
                {!! $bar($s['memory']['percent']) !!}
                <div class="ss-s">{{ number_format($s['memory']['used']) }} من {{ number_format($s['memory']['total']) }} MB
                    @if($s['memory']['swap_used'] > 50) · swap {{ $s['memory']['swap_used'] }} MB @endif</div>
            @else <div class="ss-v">{!! $na !!}</div> @endif
        </div>
        <div class="ss-card">
            <div class="ss-k">المساحة</div>
            @if($s['disk'])
                <div class="ss-v">{{ $s['disk']['percent'] }}%</div>
                {!! $bar($s['disk']['percent']) !!}
                <div class="ss-s">{{ $s['disk']['used'] }} من {{ $s['disk']['total'] }} GB</div>
            @else <div class="ss-v">{!! $na !!}</div> @endif
        </div>
        <div class="ss-card">
            <div class="ss-k">قاعدة البيانات</div>
            <div class="ss-v">{!! $s['database']['ping_ms'] !== null ? $s['database']['ping_ms'].' ms' : $na !!}</div>
            <div class="ss-s">
                {{ $s['database']['driver'] }}
                @if($s['database']['size_mb'] !== null) · {{ $s['database']['size_mb'] }} MB @endif
                @if($s['database']['connections'] !== null) · {{ $s['database']['connections'] }} اتصال @endif
            </div>
        </div>
        <div class="ss-card">
            <div class="ss-k">الطابور ({{ $s['queue']['connection'] }})</div>
            <div class="ss-v">{{ $s['queue']['pending'] ?? '—' }} <span style="font-size:13px;font-weight:400">مهمة تستنى</span></div>
            <div class="ss-s">
                فشلت: {{ $s['queue']['failed'] ?? '—' }} (آخر ساعة {{ $s['queue']['failed_last_hour'] ?? 0 }})
                @if($s['queue']['oldest_min']) · أقدم مهمة {{ $s['queue']['oldest_min'] }} د @endif
            </div>
        </div>
        <div class="ss-card">
            <div class="ss-k">المنصة توّا</div>
            <div class="ss-v">{{ $s['platform']['active_orders'] ?? '—' }} <span style="font-size:13px;font-weight:400">طلب شغّال</span></div>
            <div class="ss-s">{{ $s['platform']['orders_last_hour'] ?? '—' }} طلب آخر ساعة · {{ $s['platform']['online_drivers'] ?? '—' }} سائق متاح</div>
            <div class="ss-s">يعمل من {!! $s['uptime'] ?? $na !!} · PHP {{ $s['php'] }}</div>
        </div>
    </div>

    {{-- الحركة --}}
    <div class="ss-grid" style="margin-top:12px">
        <div class="ss-card"><div class="ss-k">طلبات آخر {{ $t['minutes'] }} دقيقة</div><div class="ss-v">{{ number_format($t['total']) }}</div><div class="ss-s">{{ $t['per_min'] }} في الدقيقة</div></div>
        <div class="ss-card"><div class="ss-k">سرعة الرد</div><div class="ss-v">{{ $t['avg_ms'] }} ms</div><div class="ss-s">95% من الطلبات تحت {{ $t['p95_ms'] }} ms</div></div>
        <div class="ss-card"><div class="ss-k">أخطاء السيرفر (5xx)</div><div class="ss-v" style="color:{{ $t['errors'] ? '#dc2626' : 'inherit' }}">{{ $t['errors'] }}</div><div class="ss-s">{{ $t['error_rate'] }}% · محظورة مؤقتاً (429): {{ $t['rate_limited'] }}</div></div>
        <div class="ss-card"><div class="ss-k">مستخدمين نشطين</div><div class="ss-v">{{ $t['active_users'] }}</div><div class="ss-s">
            @foreach($t['apps'] as $app => $n) {{ $appLabels[$app] ?? $app }}: {{ $n }}@if(! $loop->last) · @endif @endforeach
        </div></div>
    </div>

    <div class="ss-card" style="margin-top:12px">
        <p class="ss-h">الطلبات في الدقيقة</p>
        <div style="display:flex;align-items:flex-end;gap:2px;height:110px;direction:ltr">
            @foreach($t['per_minute'] as $min => $n)
                <div title="{{ $min }} — {{ $n }} طلب" style="flex:1;min-width:2px;background:#f59e0b;opacity:{{ $n ? 1 : .25 }};height:{{ max(2, round(100 * $n / $maxMin)) }}%;border-radius:2px 2px 0 0"></div>
            @endforeach
        </div>
        <div class="ss-s" style="display:flex;justify-content:space-between;direction:ltr"><span>{{ array_key_first($t['per_minute']) }}</span><span>أعلى دقيقة: {{ $maxMin }}</span><span>{{ array_key_last($t['per_minute']) }}</span></div>
    </div>

    <div class="ss-grid" style="margin-top:12px;grid-template-columns:repeat(auto-fit,minmax(380px,1fr))">
        <div class="ss-card">
            <p class="ss-h">من وين جاي الضغط؟ (أكثر العمليات)</p>
            <table class="ss-t">
                <tr><th>العملية</th><th>العدد</th><th>المتوسط</th><th>الأبطأ</th><th>أخطاء</th></tr>
                @forelse($t['routes'] as $r)
                    <tr><td class="ss-ltr">{{ $r['route'] }}</td><td>{{ $r['count'] }}</td><td>{{ $r['avg_ms'] }}ms</td><td>{{ $r['max_ms'] }}ms</td>
                        <td style="color:{{ $r['errors'] ? '#dc2626' : 'inherit' }}">{{ $r['errors'] }}</td></tr>
                @empty <tr><td colspan="5" style="opacity:.6">ما فيش طلبات في الفترة هذي.</td></tr> @endforelse
            </table>
        </div>
        <div class="ss-card">
            <p class="ss-h">أكثر العناوين (IP) طلباً</p>
            <table class="ss-t">
                <tr><th>IP</th><th>العدد</th><th>من</th><th>حسابات</th><th>429</th></tr>
                @forelse($t['ips'] as $r)
                    <tr><td class="ss-ltr">{{ $r['ip'] }}</td><td>{{ $r['count'] }}</td>
                        <td>{{ collect(explode('، ', $r['apps']))->map(fn ($a) => $appLabels[$a] ?? $a)->implode('، ') }}</td>
                        <td>{{ $r['users'] }}</td><td style="color:{{ $r['blocked'] ? '#dc2626' : 'inherit' }}">{{ $r['blocked'] }}</td></tr>
                @empty <tr><td colspan="5" style="opacity:.6">—</td></tr> @endforelse
            </table>
            <div class="ss-s" style="margin-top:6px">IP واحد بطلبات كثيرة جداً وبدون حسابات = غالباً سكربت أو محاولة إغراق.</div>
        </div>
    </div>

    <div class="ss-grid" style="margin-top:12px;grid-template-columns:repeat(auto-fit,minmax(380px,1fr))">
        <div class="ss-card">
            <p class="ss-h">أبطأ الطلبات (أكثر من {{ $t['slow_ms'] }}ms)</p>
            <table class="ss-t">
                <tr><th>الوقت</th><th>العملية</th><th>المدة</th><th>الحالة</th></tr>
                @forelse($t['slow'] as $r)
                    <tr><td>{{ date('H:i:s', $r['t']) }}</td><td class="ss-ltr">{{ $r['method'] }} {{ $r['route'] }}</td><td>{{ $r['ms'] }}ms</td><td>{{ $r['status'] }}</td></tr>
                @empty <tr><td colspan="4" style="opacity:.6">ما فيش طلبات بطيئة 👍</td></tr> @endforelse
            </table>
        </div>
        <div class="ss-card">
            <p class="ss-h">آخر الأخطاء في السجل</p>
            <table class="ss-t">
                @forelse($errors as $e)
                    <tr><td style="white-space:nowrap">{{ $e['at'] }}</td><td class="ss-ltr" style="word-break:break-word">{{ $e['message'] }}</td></tr>
                @empty <tr><td style="opacity:.6">ما فيش أخطاء مسجّلة.</td></tr> @endforelse
            </table>
        </div>
    </div>

    <div class="ss-grid" style="margin-top:12px;grid-template-columns:repeat(auto-fit,minmax(380px,1fr))">
        <div class="ss-card">
            <p class="ss-h">البرامج الأكثر استهلاكاً للذاكرة</p>
            <table class="ss-t">
                <tr><th>البرنامج</th><th>عدد العمليات</th><th>الذاكرة</th></tr>
                @forelse($processes as $p)
                    <tr><td class="ss-ltr">{{ $p['name'] }}</td><td>{{ $p['count'] }}</td><td>{{ number_format($p['mem']) }} MB</td></tr>
                @empty <tr><td colspan="3" style="opacity:.6">غير متاح</td></tr> @endforelse
            </table>
        </div>
        <div class="ss-card">
            <p class="ss-h">الحماية</p>
            @php $ac = $appcheck['stats']; $acTotal = array_sum($ac); @endphp
            <table class="ss-t">
                <tr><td>reCAPTCHA في دخول اللوحة</td><td>{!! $recaptcha ? '<b style="color:#16a34a">مفعّل</b>' : '<span style="opacity:.6">معطّل (المفاتيح مش في .env)</span>' !!}</td></tr>
                <tr><td>حماية التطبيقات (App Check)</td><td>{{ ['off' => 'معطّل', 'monitor' => 'مراقبة', 'enforce' => 'منع'][$appcheck['mode']] ?? $appcheck['mode'] }}</td></tr>
                @if($appcheck['mode'] !== 'off')
                <tr><td>طلبات حساسة اليوم</td><td>{{ $acTotal }}</td></tr>
                <tr><td>موثّقة (من التطبيق الأصلي)</td><td style="color:#16a34a">{{ $ac['ok'] }} @if($acTotal) ({{ round(100 * $ac['ok'] / $acTotal) }}%) @endif</td></tr>
                <tr><td>بدون رمز (نسخة قديمة أو سكربت)</td><td>{{ $ac['missing'] }}</td></tr>
                <tr><td>رمز مزوّر أو منتهي</td><td style="color:{{ $ac['invalid'] ? '#dc2626' : 'inherit' }}">{{ $ac['invalid'] }}</td></tr>
                @endif
            </table>
        </div>
        @if($tables)
        <div class="ss-card">
            <p class="ss-h">أكبر الجداول</p>
            <table class="ss-t">
                <tr><th>الجدول</th><th>تقريباً</th><th>الحجم</th></tr>
                @foreach($tables as $tb)
                    <tr><td class="ss-ltr">{{ $tb['table'] }}</td><td>{{ number_format($tb['rows']) }} سطر</td><td>{{ $tb['mb'] }} MB</td></tr>
                @endforeach
            </table>
        </div>
        @endif
    </div>
</div>
</x-filament-panels::page>
