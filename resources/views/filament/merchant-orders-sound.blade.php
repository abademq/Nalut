{{-- لوحة المتجر: صوت + إشعار المتصفح لما يوصل طلب جديد يستنى القبول --}}
@auth
@if (\App\Support\Merchant::canUse(auth()->user()) && \App\Support\Merchant::ordersEnabled())
<script>
(() => {
    if (window.__merchantOrders) return;
    window.__merchantOrders = true;

    let lastId = null;
    let ready = false;
    const soundUrl = @json(\App\Support\Sounds::url('store'));
    const audio = soundUrl ? new Audio(soundUrl) : null;

    function beep() {
        if (audio) { audio.currentTime = 0; audio.play().catch(() => {}); return; }
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.3, 0.6].forEach((t) => {
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.frequency.value = 990; o.type = 'sine';
                g.gain.setValueAtTime(0.3, ctx.currentTime + t);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.25);
                o.connect(g); g.connect(ctx.destination);
                o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.27);
            });
        } catch (e) {}
    }

    // المتصفح (خاصة الآيفون) ما يخليش الصوت يشتغل إلا بعد أول ضغطة في الصفحة
    document.addEventListener('click', () => {
        if (audio) { audio.muted = true; audio.play().then(() => { audio.pause(); audio.muted = false; }).catch(() => {}); }
        if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }, { once: true });

    async function poll() {
        try {
            const r = await fetch('/merchant-api/pending', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) return;
            const d = await r.json();
            const id = d.latest ? d.latest.id : null;
            if (ready && id && id !== lastId) {
                beep();
                if ('Notification' in window && Notification.permission === 'granted') {
                    const n = new Notification('طلب جديد #' + d.latest.code, { body: 'افتح اللوحة واقبل الطلب', tag: 'o' + id });
                    n.onclick = () => { window.focus(); location.href = @json(url('/merchant/orders')) + '/' + id; n.close(); };
                }
            }
            lastId = id;
            ready = true;
            document.title = (d.count ? '(' + d.count + ') ' : '') + document.title.replace(/^\(\d+\)\s*/, '');
        } catch (e) {}
    }

    poll();
    setInterval(poll, 12000);
})();
</script>
@endif
@endauth
