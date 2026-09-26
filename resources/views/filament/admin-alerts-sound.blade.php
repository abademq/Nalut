{{-- تنبيه فوري: صوت + إشعار المتصفح لما يوصل تنبيه جديد للإدارة --}}
@auth
@if (auth()->user()->hasRole(\App\Enums\UserRole::Admin))
<script>
(() => {
    if (window.__nalutAlerts) return;
    window.__nalutAlerts = true;

    let lastId = null;
    let ready = false;

    // الصوت من «أصوات الإشعارات» في لوحة التحكم — بدونه نغمة قصيرة
    const soundUrl = @json(\App\Support\Sounds::url('admin'));
    const audio = soundUrl ? new Audio(soundUrl) : null;

    function beep() {
        if (audio) {
            audio.currentTime = 0;
            audio.play().catch(() => {});
            return;
        }
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.25].forEach((t) => {
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.frequency.value = 880; o.type = 'sine';
                g.gain.setValueAtTime(0.25, ctx.currentTime + t);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.2);
                o.connect(g); g.connect(ctx.destination);
                o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.22);
            });
        } catch (e) {}
    }

    // المتصفح يطلب إذن الإشعارات بعد أول ضغطة في الصفحة
    document.addEventListener('click', () => {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }, { once: true });

    async function poll() {
        try {
            const r = await fetch('/admin-api/alerts', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) return;
            const d = await r.json();
            const id = d.latest ? d.latest.id : null;

            if (ready && id && id !== lastId) {
                beep();
                if ('Notification' in window && Notification.permission === 'granted') {
                    const n = new Notification(d.latest.title || 'تنبيه', { body: d.latest.body || '', tag: id });
                    n.onclick = () => { window.focus(); n.close(); };
                }
                // نحدّث الجرس فوراً بدل ما نستنو دورته
                if (window.Livewire) window.Livewire.dispatch('databaseNotificationsSent');
            }

            lastId = id;
            ready = true;
        } catch (e) {}
    }

    poll();
    setInterval(poll, 15000);
})();
</script>
@endif
@endauth
