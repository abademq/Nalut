<x-filament-panels::page>
    @if ($tvUrl)
        <x-filament::section heading="رابط الشاشة الكبيرة" description="افتحه في متصفح التلفزيون أو الكمبيوتر المربوط بالشاشة — يخدم بدون تسجيل دخول ويتحدّث كل 5 ثواني. للعرض بس، وما يقدرش يغيّر حتى شي. لو تسرّب، اضغط «رابط شاشة جديد».">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <code dir="ltr" style="flex:1;min-width:240px;padding:8px 10px;border-radius:8px;background:rgba(127,127,127,.12);word-break:break-all;font-size:12px">{{ $tvUrl }}</code>
                <x-filament::button size="sm" color="gray" x-data x-on:click="navigator.clipboard.writeText(@js($tvUrl)); $tooltip('انسخ ✓')">نسخ</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <iframe src="{{ url('monitor') }}" title="شاشة المراقبة"
            style="width:100%;height:78vh;border:0;border-radius:14px;background:#0d1514"></iframe>
</x-filament-panels::page>
