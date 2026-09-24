<x-filament-panels::page>

    {{-- الإحصائيات تتحدّث مع الخريطة --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-6">
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500">متاح توّا</div>
            <div id="c-online" class="mt-1 text-2xl font-bold text-green-600">—</div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500">غير متاح</div>
            <div id="c-offline" class="mt-1 text-2xl font-bold text-gray-500">—</div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500">بانتظار الاعتماد</div>
            <div id="c-pending" class="mt-1 text-2xl font-bold text-blue-500">—</div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500">حسابات موقوفة</div>
            <div id="c-suspended" class="mt-1 text-2xl font-bold text-red-500">—</div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500">كاش عند السائقين</div>
            <div id="c-debt" class="mt-1 text-xl font-bold text-red-600">—</div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <div class="text-xs text-gray-500">مستحقات لهم</div>
            <div id="c-credit" class="mt-1 text-xl font-bold text-green-600">—</div>
        </div>
    </div>

    <div class="mt-4 rounded-xl bg-white p-3 shadow-sm dark:bg-gray-800">
        <div class="mb-2 flex items-center justify-between">
            <div class="text-sm text-gray-500">
                يظهر السائقون المتاحون اللي بعثوا موقعهم خلال آخر 15 دقيقة
            </div>
            <div id="last-update" class="text-xs text-gray-400">—</div>
        </div>

        <div id="drivers-map" style="height: 560px; border-radius: 12px; z-index: 0;"></div>

        <div id="empty-note" class="hidden p-6 text-center text-sm text-gray-500">
            ما فيش سائقين متاحين توّا
        </div>
    </div>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const map = L.map('drivers-map').setView([31.8686, 10.9817], 13);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            const markers = {};
            let firstFit = true;

            function icon(busy) {
                const color = busy ? '#2563eb' : '#16a34a';

                return L.divIcon({
                    className: '',
                    html: `<div style="background:${color};width:28px;height:28px;
                        border-radius:50%;border:3px solid #fff;
                        box-shadow:0 1px 6px rgba(0,0,0,.45)"></div>`,
                    iconSize: [28, 28],
                    iconAnchor: [14, 14],
                });
            }

            function money(v) {
                return Number(v).toFixed(2) + ' د.ل';
            }

            async function refresh() {
                try {
                    const res = await fetch('{{ url('admin-api/drivers-map') }}', {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (!res.ok) return;

                    const data = await res.json();

                    document.getElementById('c-online').textContent    = data.counts.online;
                    document.getElementById('c-offline').textContent   = data.counts.offline;
                    document.getElementById('c-pending').textContent   = data.counts.pending;
                    document.getElementById('c-suspended').textContent = data.counts.suspended;
                    document.getElementById('c-debt').textContent      = money(data.debt);
                    document.getElementById('c-credit').textContent    = money(data.credit);
                    document.getElementById('last-update').textContent = 'آخر تحديث: ' + data.at;

                    document.getElementById('empty-note').className =
                        data.drivers.length ? 'hidden' : 'p-6 text-center text-sm text-gray-500';

                    const seen = {};
                    const bounds = [];

                    data.drivers.forEach(function (d) {
                        seen[d.id] = true;
                        bounds.push([d.lat, d.lng]);

                        const zones = d.zones.length ? d.zones.join('، ') : 'كل المناطق';

                        const popup = `
                            <div style="direction:rtl;text-align:right;min-width:190px;line-height:1.7">
                                <b style="font-size:15px">${d.name}</b><br>
                                <a href="tel:${d.phone}" style="color:#2563eb">${d.phone}</a><br>
                                طلبات ماشية: <b>${d.orders}</b> من ${d.capacity}<br>
                                الحساب: <b style="color:${d.balance < 0 ? '#dc2626' : '#16a34a'}">
                                    ${d.balance < 0 ? 'عليه ' : 'له '}${money(Math.abs(d.balance))}
                                </b><br>
                                المناطق: ${zones}<br>
                                <span style="color:#777">${d.since}</span>
                            </div>`;

                        if (markers[d.id]) {
                            markers[d.id].setLatLng([d.lat, d.lng]);
                            markers[d.id].setIcon(icon(d.orders > 0));
                            markers[d.id].setPopupContent(popup);
                        } else {
                            markers[d.id] = L.marker([d.lat, d.lng], { icon: icon(d.orders > 0) })
                                .addTo(map)
                                .bindPopup(popup);
                        }
                    });

                    Object.keys(markers).forEach(function (id) {
                        if (!seen[id]) {
                            map.removeLayer(markers[id]);
                            delete markers[id];
                        }
                    });

                    if (firstFit && bounds.length) {
                        map.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
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
