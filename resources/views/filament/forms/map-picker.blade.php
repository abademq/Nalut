@php
    // مسار الحقول الشقيقة: "data.map_picker_lat" → "data"
    $base = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
    $cfg = [
        'latPath'    => $base.'.'.$latField,
        'lngPath'    => $base.'.'.$lngField,
        'radiusPath' => $radiusField ? $base.'.'.$radiusField : null,
        'zones'      => $zones,
        // مركز نالوت — لو ما فيش موقع محفوظ
        'fallback'   => [31.8686, 10.9817],
    ];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            cfg: @js($cfg),
            map: null, marker: null, circle: null,

            loadLeaflet() {
                if (window.L) return Promise.resolve();
                if (window.__nalutLeaflet) return window.__nalutLeaflet;
                window.__nalutLeaflet = new Promise((resolve) => {
                    const css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(css);
                    const js = document.createElement('script');
                    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    js.onload = () => resolve();
                    document.head.appendChild(js);
                });
                return window.__nalutLeaflet;
            },

            num(v) { const n = parseFloat(v); return isNaN(n) ? null : n; },

            current() {
                const lat = this.num(this.$wire.$get(this.cfg.latPath));
                const lng = this.num(this.$wire.$get(this.cfg.lngPath));
                return (lat !== null && lng !== null) ? [lat, lng] : null;
            },

            radiusKm() {
                return this.cfg.radiusPath ? (this.num(this.$wire.$get(this.cfg.radiusPath)) || 0) : 0;
            },

            async init() {
                await this.loadLeaflet();
                const start = this.current();
                const L = window.L;

                this.map = L.map(this.$refs.map).setView(start || this.cfg.fallback, start ? 15 : 12);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19, attribution: '© OpenStreetMap',
                }).addTo(this.map);

                // المناطق الموجودة كمرجع
                (this.cfg.zones || []).forEach((z) => {
                    L.circle([z.lat, z.lng], {
                        radius: z.radius * 1000, color: z.active ? '#6b7280' : '#d1d5db',
                        weight: 1, dashArray: '4 4', fillOpacity: 0.05,
                    }).addTo(this.map).bindTooltip(z.name);
                });

                this.marker = L.marker(start || this.cfg.fallback, { draggable: true });
                if (start) this.marker.addTo(this.map);

                if (this.cfg.radiusPath) {
                    this.circle = L.circle(start || this.cfg.fallback, {
                        radius: this.radiusKm() * 1000, color: '#D84315', weight: 2, fillOpacity: 0.12,
                    });
                    if (start) this.circle.addTo(this.map);
                    this.$wire.$watch(this.cfg.radiusPath, () => {
                        this.circle.setRadius(this.radiusKm() * 1000);
                        if (this.map.hasLayer(this.circle)) this.map.fitBounds(this.circle.getBounds(), { padding: [20, 20] });
                    });
                    if (start && this.radiusKm() > 0) this.map.fitBounds(this.circle.getBounds(), { padding: [20, 20] });
                }

                this.map.on('click', (e) => this.set(e.latlng.lat, e.latlng.lng));
                this.marker.on('dragend', () => {
                    const p = this.marker.getLatLng();
                    this.set(p.lat, p.lng);
                });

                // لو كتب الإحداثيات بيده، الدبوس يتحرك
                const sync = () => {
                    const p = this.current();
                    if (!p) return;
                    this.place(p);
                };
                this.$wire.$watch(this.cfg.latPath, sync);
                this.$wire.$watch(this.cfg.lngPath, sync);

                // الخريطة داخل أقسام تنفتح/تتسكّر — نعاودو نحسبو حجمها
                setTimeout(() => this.map.invalidateSize(), 300);
            },

            place(p) {
                this.marker.setLatLng(p);
                if (!this.map.hasLayer(this.marker)) this.marker.addTo(this.map);
                if (this.circle) {
                    this.circle.setLatLng(p);
                    if (!this.map.hasLayer(this.circle)) this.circle.addTo(this.map);
                }
            },

            set(lat, lng) {
                lat = Math.round(lat * 1e7) / 1e7;
                lng = Math.round(lng * 1e7) / 1e7;
                this.place([lat, lng]);
                this.$wire.$set(this.cfg.latPath, lat, false);
                this.$wire.$set(this.cfg.lngPath, lng, false);
            },

            locateMe() {
                if (!navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition((pos) => {
                    this.set(pos.coords.latitude, pos.coords.longitude);
                    this.map.setView([pos.coords.latitude, pos.coords.longitude], 16);
                });
            },
        }"
    >
        <div class="relative">
            <div x-ref="map" style="height: 380px; border-radius: 12px; z-index: 0;"></div>
            <button type="button" x-on:click="locateMe()"
                style="position:absolute; top:10px; left:10px; z-index:500; background:#fff; border-radius:8px; padding:6px 10px; font-size:12px; box-shadow:0 1px 4px rgba(0,0,0,.25);">
                📍 موقعي
            </button>
        </div>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            اضغط على الخريطة أو اسحب الدبوس لتحديد المكان. الإحداثيات تتعبّى لحالها.
        </p>
    </div>
</x-dynamic-component>
