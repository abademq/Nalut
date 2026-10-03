<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>شاشة المراقبة — {{ \App\Filament\Pages\AppSettings::values()['name'] ?? 'ازانكس' }}</title>
    <style>
        :root { --bg:#0d1514; --card:#15211f; --line:#243331; --ink:#eef4f2; --muted:#8fa39e; --accent:#FF7900; --green:#22c55e; --red:#ef4444; --amber:#f59e0b; --blue:#38bdf8; }
        * { box-sizing: border-box; }
        html, body { margin:0; background:var(--bg); color:var(--ink); font-family: system-ui, "Segoe UI", Tahoma, sans-serif; }
        body { padding: 1.2vw; min-height: 100vh; font-size: clamp(13px, 0.95vw, 22px); }
        header { display:flex; align-items:center; gap:1vw; margin-bottom:1vw; }
        header h1 { margin:0; font-size:1.6em; color:var(--accent); }
        header .grow { flex:1; }
        .clock { font-size:1.9em; font-weight:800; direction:ltr; font-variant-numeric: tabular-nums; }
        .sub { color:var(--muted); font-size:.9em; }
        .dot { display:inline-block; width:.7em; height:.7em; border-radius:50%; background:var(--green); margin-left:.4em; vertical-align:middle; }
        .dot.off { background:var(--red); animation: blink 1s infinite; }
        @keyframes blink { 50% { opacity:.2 } }
        button.fs { background:var(--card); color:var(--ink); border:1px solid var(--line); border-radius:.6em; padding:.5em .9em; font:inherit; cursor:pointer; }
        .kpis { display:grid; grid-template-columns: repeat(8, 1fr); gap:.8vw; margin-bottom:.9vw; }
        .kpi { background:var(--card); border:1px solid var(--line); border-radius:1em; padding:.9em 1em; }
        .kpi .v { font-size:2.4em; font-weight:800; line-height:1.1; font-variant-numeric: tabular-nums; }
        .kpi .l { color:var(--muted); font-size:.9em; margin-top:.2em; }
        .kpi.good .v { color:var(--green); } .kpi.bad .v { color:var(--red); } .kpi.hot .v { color:var(--accent); }
        .grid { display:grid; grid-template-columns: 1.05fr 1.4fr 1.05fr; gap:.9vw; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:1em; padding:.9em 1em; margin-bottom:.9vw; }
        .card h2 { margin:0 0 .6em; font-size:1.1em; color:var(--muted); font-weight:700; display:flex; justify-content:space-between; }
        .pills { display:flex; flex-wrap:wrap; gap:.5em; }
        .pill { background:#1d2c2a; border-radius:.7em; padding:.45em .8em; }
        .pill b { font-size:1.3em; margin-right:.3em; }
        table { width:100%; border-collapse:collapse; }
        th { color:var(--muted); font-weight:600; text-align:right; font-size:.85em; padding:.3em .2em; border-bottom:1px solid var(--line); }
        td { padding:.45em .2em; border-bottom:1px solid #1c2927; font-variant-numeric: tabular-nums; }
        tr.late td { background: rgba(245,158,11,.10); }
        tr.issue td { background: rgba(239,68,68,.14); }
        .tag { font-size:.8em; padding:.15em .5em; border-radius:.5em; background:#1f3a35; white-space:nowrap; }
        .tag.p { background:#3a2a5c; }
        .age { font-weight:700; direction:ltr; display:inline-block; }
        .problem { display:flex; gap:.6em; align-items:flex-start; padding:.55em .6em; border-radius:.7em; margin-bottom:.45em; background:#1b2725; }
        .problem.danger { background: rgba(239,68,68,.16); border-right: .3em solid var(--red); }
        .problem.warning { background: rgba(245,158,11,.13); border-right: .3em solid var(--amber); }
        .problem.info { border-right: .3em solid var(--blue); }
        .problem b { display:block; }
        .problem span { color:var(--muted); font-size:.88em; }
        .ok { color:var(--green); text-align:center; padding:1em; font-size:1.1em; }
        .drivers { display:grid; grid-template-columns: repeat(3,1fr); gap:.5em; margin-bottom:.6em; }
        .drivers div { background:#1d2c2a; border-radius:.7em; padding:.5em; text-align:center; }
        .drivers b { font-size:1.8em; display:block; }
        .muted { color:var(--muted); }
        .stale { color:var(--amber); }
        @media (max-width: 1100px) { .kpis { grid-template-columns: repeat(4,1fr); } .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header>
    <h1>شاشة المراقبة</h1>
    <div class="sub"><span class="dot" id="dot"></span><span id="status">نحمّلو...</span></div>
    <div class="grow"></div>
    <div class="sub" id="date"></div>
    <div class="clock" id="clock">--:--</div>
    <button class="fs" onclick="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()">⛶ شاشة كاملة</button>
</header>

<section class="kpis" id="kpis"></section>

<section class="grid">
    <div>
        <div class="card"><h2>الطلبات الشغّالة حسب الحالة</h2><div class="pills" id="statuses"></div></div>
        <div class="card"><h2>أقسام التطبيق — اليوم</h2>
            <table><thead><tr><th>القسم</th><th>شغّالة</th><th>اليوم</th><th>متوسط التحضير</th><th>متوسط الكلّي</th></tr></thead><tbody id="sections"></tbody></table>
        </div>
    </div>
    <div>
        <div class="card"><h2><span>الطلبات الشغّالة</span><span id="activeCount"></span></h2>
            <table><thead><tr><th>#</th><th>المتجر</th><th>الحالة</th><th>السائق</th><th>منذ</th></tr></thead><tbody id="orders"></tbody></table>
        </div>
    </div>
    <div>
        <div class="card"><h2><span>تحتاج تدخّل</span><span id="problemCount"></span></h2><div id="problems"></div></div>
        <div class="card"><h2>السائقين</h2><div class="drivers" id="driverStats"></div>
            <table><tbody id="drivers"></tbody></table>
        </div>
    </div>
</section>

<script>
const DATA_URL = @json($dataUrl);
const EVERY = {{ (int) $seconds }} * 1000;
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const n = (v, suffix = '') => (v === null || v === undefined) ? '—' : `${v}${suffix}`;
const min = (v) => (v === null || v === undefined) ? '—' : `${Math.round(v)} د`;

function tick() {
    const d = new Date();
    $('clock').textContent = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
setInterval(tick, 1000); tick();

function render(m) {
    const k = m.kpis;
    $('date').textContent = m.date;
    const tiles = [
        ['طلبات اليوم', k.orders_today, ''],
        ['شغّالة توّا', k.active, 'hot'],
        ['مكتملة', k.delivered_today, 'good'],
        ['ملغية / فاشلة', `${k.cancelled_today} / ${k.failed_today}`, (k.cancelled_today + k.failed_today) ? 'bad' : ''],
        ['نسبة الإكمال', n(k.completion_rate, '%'), ''],
        ['مبيعات اليوم (د.ل)', Number(k.sales_today).toLocaleString('en'), ''],
        ['متوسط التوصيل', min(k.avg_delivery_minutes), ''],
        ['متوسط التحضير', min(k.avg_prep_minutes), ''],
    ];
    $('kpis').innerHTML = tiles.map(([l, v, c]) => `<div class="kpi ${c}"><div class="v">${esc(v)}</div><div class="l">${l}</div></div>`).join('');

    $('statuses').innerHTML = m.by_status.map((s) => `<div class="pill"><b>${s.count}</b>${esc(s.label)}</div>`).join('');

    $('sections').innerHTML = m.sections.length ? m.sections.map((s) =>
        `<tr><td>${esc(s.name)}</td><td>${s.active}</td><td>${s.today}</td><td>${min(s.avg_prep_minutes)}</td><td>${min(s.avg_total_minutes)}</td></tr>`).join('')
        : '<tr><td colspan="5" class="muted">ما فيش طلبات اليوم</td></tr>';

    $('activeCount').textContent = m.active_orders.length;
    $('orders').innerHTML = m.active_orders.length ? m.active_orders.map((o) =>
        `<tr class="${o.late === 'issue' ? 'issue' : (o.late ? 'late' : '')}"><td>${esc(o.code)}</td><td>${esc(o.store)}<div class="muted" style="font-size:.8em">${esc(o.section)}</div></td>
         <td><span class="tag ${o.pickup ? 'p' : ''}">${esc(o.status_label)}</span></td><td>${o.pickup ? '<span class="muted">استلام</span>' : esc(o.driver || '—')}</td>
         <td><span class="age">${o.age_minutes}′</span></td></tr>`).join('')
        : '<tr><td colspan="5" class="ok">ما فيش طلبات شغّالة</td></tr>';

    $('problemCount').textContent = m.problems.length || '';
    $('problems').innerHTML = m.problems.length ? m.problems.map((p) =>
        `<div class="problem ${p.level}"><div><b>${esc(p.title)}</b><span>${esc(p.detail)}</span></div></div>`).join('')
        : '<div class="ok">✓ كل شي تمام</div>';

    const d = m.drivers;
    $('driverStats').innerHTML = `<div><b>${d.online}</b>متاحين</div><div><b>${d.busy}</b>معاهم طلبات</div><div><b>${d.free}</b>فاضيين</div>`;
    $('drivers').innerHTML = (d.offline_with_orders ? `<tr><td colspan="3" class="stale">⚠ ${d.offline_with_orders} سائق غير متاح ومعاه طلب</td></tr>` : '')
        + (d.list.length ? d.list.map((x) => `<tr><td>${esc(x.name)}</td><td>${x.active_orders ? x.active_orders + ' طلب' : '<span class="muted">فاضي</span>'}</td>
           <td class="${x.location_minutes_ago !== null && x.location_minutes_ago > 5 ? 'stale' : 'muted'}">${x.location_minutes_ago === null ? '—' : (x.location_minutes_ago < 1 ? 'الموقع توّا' : 'الموقع قبل ' + x.location_minutes_ago + ' د')}</td></tr>`).join('')
           : '<tr><td class="muted">ما فيش سائقين متاحين</td></tr>');
}

let failing = 0;
async function load() {
    try {
        const r = await fetch(DATA_URL, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        if (!r.ok) throw new Error(r.status);
        const m = await r.json();
        render(m);
        failing = 0;
        $('dot').className = 'dot';
        $('status').textContent = `مباشر · آخر تحديث ${m.updated_at}`;
    } catch (e) {
        failing++;
        $('dot').className = 'dot off';
        $('status').textContent = e.message === '403' ? 'انتهت الصلاحية — افتح الرابط الجديد من اللوحة' : `انقطع الاتصال — نعاودو (${failing})`;
    }
}
load();
setInterval(load, EVERY);
</script>
</body>
</html>
