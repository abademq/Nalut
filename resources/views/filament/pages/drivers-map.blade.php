<x-filament-panels::page>

    {{-- الإحصائيات تتحدّث مع الخريطة --}}
    <div class="om-grid">
        <div class="om-card">
            <div class="om-label">سائقين متاحين توّا</div>
            <div id="c-online" class="om-val" style="color:#16a34a">—</div>
        </div>

        <div class="om-card">
            <div class="om-label">سائقين غير متاحين</div>
            <div id="c-offline" class="om-val" style="color:#6b7280">—</div>
        </div>

        <div class="om-card">
            <div class="om-label">متاجر مفتوحة</div>
            <div id="c-stores-open" class="om-val" style="color:#f97316">—</div>
        </div>

        <div class="om-card">
            <div class="om-label">متاجر مسكّرة</div>
            <div id="c-stores-closed" class="om-val" style="color:#6b7280">—</div>
        </div>

        <div class="om-card">
            <div class="om-label">سائقين بانتظار الاعتماد</div>
            <div id="c-pending" class="om-val" style="color:#3b82f6">—</div>
        </div>

        <div class="om-card">
            <div class="om-label">حسابات موقوفة</div>
            <div id="c-suspended" class="om-val" style="color:#ef4444">—</div>
        </div>

        <div class="om-card">
            <div class="om-label">كاش عند السائقين</div>
            <div id="c-debt" class="om-val" style="color:#dc2626;font-size:19px">—</div>
        </div>

        <div class="om-card">
            <div class="om-label">مستحقات لهم</div>
            <div id="c-credit" class="om-val" style="color:#16a34a;font-size:19px">—</div>
        </div>
    </div>

    <div class="om-card" style="margin-top:16px;padding:12px">
        {{-- إظهار/إخفاء الطبقات + مفتاح الألوان --}}
        <div class="om-bar">
            <label>
                <input type="checkbox" data-layer="online" checked>
                <span class="om-dot" style="border-radius:50%;background:#16a34a"></span> متاح
                <span class="om-dot" style="border-radius:50%;background:#2563eb"></span> عنده طلبات
            </label>
            <label>
                <input type="checkbox" data-layer="offline" checked>
                <span class="om-dot" style="border-radius:50%;background:#9ca3af"></span>
                آخر موقع للغير متاحين
                <span id="last-seen-note" class="om-muted"></span>
            </label>
            <label>
                <input type="checkbox" data-layer="stores" checked>
                <span class="om-dot" style="border-radius:3px;background:#f97316"></span> متجر مفتوح
                <span class="om-dot" style="border-radius:3px;background:#6b7280"></span> مسكّر
            </label>
            <label>
                <input type="checkbox" data-layer="zones" checked>
                <span class="om-dot" style="border-radius:50%;border:2px solid;border-color:#7c3aed;background:rgba(124,58,237,.15)"></span>
                مناطق التوصيل
            </label>

            <button type="button" id="fit-all" class="om-btn">
                عرض الكل
            </button>
            <div id="last-update" class="om-muted">—</div>
        </div>

        <div id="drivers-map" style="height: 620px; border-radius: 12px; z-index: 0;"></div>

        <div class="om-muted" style="margin-top:8px">
            المتاح يتحدّث كل 15 ثانية. السائق الرمادي طفّى أو وقف تطبيقه — يطلع في آخر مكان بعث منه موقعه.
            المناطق المسكّرة تطلع بخط متقطّع.
        </div>
    </div>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        .om-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
        .om-card { background: #fff; border-radius: 12px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); border: 1px solid rgba(0,0,0,.05); }
        .dark .om-card { background: rgb(31 41 55); border-color: rgba(255,255,255,.08); }
        .om-label { font-size: 12px; color: #6b7280; }
        .om-val { margin-top: 4px; font-size: 24px; font-weight: 700; line-height: 1.2; }
        .om-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 20px; margin-bottom: 12px; font-size: 14px; }
        .om-bar label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
        .om-dot { display: inline-block; width: 12px; height: 12px; }
        .om-muted { font-size: 12px; color: #9ca3af; }
        .om-btn { margin-inline-start: auto; border: 1px solid #d1d5db; border-radius: 8px; padding: 4px 12px; font-size: 12px; color: #4b5563; background: transparent; }
        .om-btn:hover { background: rgba(0,0,0,.04); }
        .dark .om-btn { border-color: #4b5563; color: #d1d5db; }
        .zone-label { background: rgba(255,255,255,.85); border: 0; box-shadow: none; font-weight: 700; color: #5b21b6; padding: 1px 6px; }
        .zone-label::before { display: none; }
        .map-pop { direction: rtl; text-align: right; min-width: 190px; line-height: 1.7; }
        .map-pop a { color: #2563eb; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const map = L.map('drivers-map').setView([31.8686, 10.9817], 13);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            // الطبقات: المناطق تحت، بعدها المتاجر، والسائقين فوق الكل
            const layers = {
                zones:   L.layerGroup().addTo(map),
                stores:  L.layerGroup().addTo(map),
                offline: L.layerGroup().addTo(map),
                online:  L.layerGroup().addTo(map),
            };

            document.querySelectorAll('[data-layer]').forEach(function (box) {
                box.addEventListener('change', function () {
                    const layer = layers[box.dataset.layer];
                    box.checked ? layer.addTo(map) : map.removeLayer(layer);
                });
            });

            // أسماء المتاجر يكتبوها أصحابها — نهرّبوها قبل ما تدخل HTML
            function esc(v) {
                return String(v ?? '').replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            function money(v) {
                return Number(v).toFixed(2) + ' د.ل';
            }

            function driverIcon(d) {
                const color = d.state !== 'online' ? '#9ca3af' : (d.orders > 0 ? '#2563eb' : '#16a34a');
                const size = d.state === 'online' ? 28 : 22;

                return L.divIcon({
                    className: '',
                    html: `<div style="background:${color};width:${size}px;height:${size}px;
                        border-radius:50%;border:3px solid #fff;
                        box-shadow:0 1px 6px rgba(0,0,0,.45)"></div>`,
                    iconSize: [size, size],
                    iconAnchor: [size / 2, size / 2],
                });
            }

            function storeIcon(s) {
                const color = s.open ? '#f97316' : '#6b7280';

                return L.divIcon({
                    className: '',
                    html: `<div style="background:${color};width:26px;height:26px;border-radius:7px;
                        border:2px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.4);
                        display:flex;align-items:center;justify-content:center">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.5-5h15L21 9"/>
                            <path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/></svg></div>`,
                    iconSize: [26, 26],
                    iconAnchor: [13, 13],
                });
            }

            function driverPopup(d) {
                const zones = d.zones.length ? d.zones.map(esc).join('، ') : 'كل المناطق';
                const state = { online: 'متاح', stale: 'متاح بس موقعه وقف', offline: 'غير متاح' }[d.state];

                return `<div class="map-pop">
                    <b style="font-size:15px">${esc(d.name)}</b> <span style="color:#777">(${state})</span><br>
                    <a href="tel:${esc(d.phone)}">${esc(d.phone)}</a><br>
                    طلبات ماشية: <b>${d.orders}</b> من ${d.capacity}<br>
                    الحساب: <b style="color:${d.balance < 0 ? '#dc2626' : '#16a34a'}">
                        ${d.balance < 0 ? 'عليه ' : 'له '}${money(Math.abs(d.balance))}</b><br>
                    المناطق: ${zones}<br>
                    <span style="color:#777">${d.state === 'online' ? 'آخر تحديث' : 'آخر موقع'}: ${esc(d.since)}</span>
                    ${d.url ? `<br><a href="${esc(d.url)}">فتح ملف السائق ←</a>` : ''}
                </div>`;
            }

            function storePopup(s) {
                return `<div class="map-pop">
                    <b style="font-size:15px">${esc(s.name)}</b><br>
                    <span style="color:${s.open ? '#16a34a' : '#6b7280'}">${esc(s.status)}</span><br>
                    ${s.phone ? `<a href="tel:${esc(s.phone)}">${esc(s.phone)}</a><br>` : ''}
                    المنطقة: ${esc(s.zone || '—')}<br>
                    طلبات ماشية: <b>${s.orders}</b>
                    ${s.url ? `<br><a href="${esc(s.url)}">فتح صفحة المتجر ←</a>` : ''}
                </div>`;
            }

            function zonePopup(z) {
                return `<div class="map-pop">
                    <b style="font-size:15px">${esc(z.name)}</b>${z.active ? '' : ' <span style="color:#dc2626">(موقوفة)</span>'}<br>
                    نصف القطر: ${z.radius_km} كم<br>
                    رسوم التوصيل: ${money(z.base_fee)}${z.per_km > 0 ? ' + ' + money(z.per_km) + ' لكل كم' : ''}<br>
                    أقل طلب: ${money(z.min_order)}
                    ${z.url ? `<br><a href="${esc(z.url)}">تعديل المنطقة ←</a>` : ''}
                </div>`;
            }

            // نحدّثو العلامات في مكانها (بدل ما نمسحوها) باش النافذة المفتوحة ما تتسكّرش كل 15 ثانية
            const markers = {};

            function sync(key, items, make, update) {
                const seen = {};
                markers[key] = markers[key] || {};

                items.forEach(function (item) {
                    const id = item._layer + ':' + item.id;
                    seen[id] = true;

                    if (markers[key][id]) {
                        update(markers[key][id], item);
                    } else {
                        markers[key][id] = make(item).addTo(layers[item._layer]);
                    }
                });

                Object.keys(markers[key]).forEach(function (id) {
                    if (!seen[id]) {
                        markers[key][id].remove();
                        delete markers[key][id];
                    }
                });
            }

            let firstFit = true;
            let allBounds = [];

            function fitAll() {
                if (allBounds.length) {
                    map.fitBounds(allBounds, { padding: [40, 40], maxZoom: 15 });
                }
            }

            document.getElementById('fit-all').addEventListener('click', fitAll);

            async function refresh() {
                try {
                    const res = await fetch('{{ url('admin-api/drivers-map') }}', {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (!res.ok) return;

                    const data = await res.json();

                    const set = (id, v) => document.getElementById(id).textContent = v;
                    set('c-online', data.counts.online);
                    set('c-offline', data.counts.offline);
                    set('c-stores-open', data.counts.stores_open);
                    set('c-stores-closed', data.counts.stores_closed);
                    set('c-pending', data.counts.pending);
                    set('c-suspended', data.counts.suspended);
                    set('c-debt', money(data.debt));
                    set('c-credit', money(data.credit));
                    set('last-update', 'آخر تحديث: ' + data.at);
                    set('last-seen-note', data.last_seen_hours > 0
                        ? '(آخر ' + data.last_seen_hours + ' ساعة)' : '(مطفية من الإعدادات)');

                    // المناطق
                    sync('zones', data.zones.map(z => ({ ...z, _layer: 'zones' })), function (z) {
                        return L.circle([z.lat, z.lng], {
                            radius: z.radius_km * 1000,
                            color: z.active ? '#7c3aed' : '#9ca3af',
                            weight: 2,
                            dashArray: z.active ? null : '6 6',
                            fillColor: z.active ? '#7c3aed' : '#9ca3af',
                            fillOpacity: z.active ? 0.08 : 0.04,
                        })
                            .bindPopup(zonePopup(z))
                            .bindTooltip(esc(z.name), { permanent: true, direction: 'center', className: 'zone-label' });
                    }, function (c, z) {
                        c.setLatLng([z.lat, z.lng]).setRadius(z.radius_km * 1000).setPopupContent(zonePopup(z));
                    });

                    // المتاجر
                    sync('stores', data.stores.map(s => ({ ...s, _layer: 'stores' })), function (s) {
                        return L.marker([s.lat, s.lng], { icon: storeIcon(s), title: s.name }).bindPopup(storePopup(s));
                    }, function (m, s) {
                        m.setLatLng([s.lat, s.lng]).setIcon(storeIcon(s)).setPopupContent(storePopup(s));
                    });

                    // السائقين: المتاح في طبقة، وآخر موقع للباقين في طبقة ثانية
                    sync('drivers', data.drivers.map(d => ({ ...d, _layer: d.state === 'online' ? 'online' : 'offline' })), function (d) {
                        return L.marker([d.lat, d.lng], { icon: driverIcon(d), title: d.name, zIndexOffset: d.state === 'online' ? 1000 : 0 })
                            .bindPopup(driverPopup(d));
                    }, function (m, d) {
                        m.setLatLng([d.lat, d.lng]).setIcon(driverIcon(d)).setPopupContent(driverPopup(d));
                    });

                    allBounds = []
                        .concat(data.drivers.map(d => [d.lat, d.lng]))
                        .concat(data.stores.map(s => [s.lat, s.lng]))
                        .concat(data.zones.map(z => [z.lat, z.lng]));

                    if (firstFit && allBounds.length) {
                        fitAll();
                        firstFit = false;
                    }
                } catch (e) {
                    console.error(e);
                }
            }

            refresh();
            setInterval(refresh, 15000);
        });
    </script>
</x-filament-panels::page>
