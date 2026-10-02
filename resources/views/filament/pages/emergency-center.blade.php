<x-filament-panels::page>
    @php($anyOn = collect($switches)->contains('on', true))

    {{-- الحالة العامة --}}
    <div style="padding:16px 18px;border-radius:14px;border:1px solid {{ $anyOn ? '#fca5a5' : '#86efac' }};background:{{ $anyOn ? 'rgba(239,68,68,.08)' : 'rgba(34,197,94,.08)' }}">
        <div style="font-weight:800;font-size:16px">
            {{ $anyOn ? '🔴 فيه مفاتيح طوارئ شغّالة — جزء من المنصة موقوف' : '🟢 كل شي يخدم عادي' }}
        </div>
        @if ($updatedAt)
            <div style="font-size:13px;opacity:.75;margin-top:4px">
                آخر تغيير: {{ \Illuminate\Support\Carbon::parse($updatedAt)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                — {{ $updatedBy }}
            </div>
        @endif
        <div style="font-size:13px;opacity:.85;margin-top:8px;line-height:1.9">
            القاعدة: أقفل <b>أصغر جزء</b> يوقف الضرر. كل ضغطة تتسجّل في سجل النشاط، وتوصل تنبيه لكل الإدارة.
            لو اللوحة نفسها مش آمنة، نفس المفاتيح تشتغل من السيرفر: <code dir="ltr">php artisan emergency status</code>
        </div>
    </div>

    {{-- المفاتيح --}}
    <x-filament::section heading="المفاتيح" description="كل مفتاح يوقف حاجة وحدة بس. الإيقاف يصير في السيرفر، فيشمل حتى النسخ القديمة من التطبيقات.">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
            @foreach ($switches as $s)
                <div style="border-radius:12px;padding:14px;border:1px solid {{ $s['on'] ? '#ef4444' : 'rgba(127,127,127,.25)' }};background:{{ $s['on'] ? 'rgba(239,68,68,.07)' : 'transparent' }};display:flex;flex-direction:column;gap:8px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <b>{{ $s['name'] }}</b>
                        <span style="font-size:12px;font-weight:700;padding:2px 10px;border-radius:999px;background:{{ $s['on'] ? '#ef4444' : 'rgba(34,197,94,.15)' }};color:{{ $s['on'] ? '#fff' : '#15803d' }}">
                            {{ $s['on'] ? 'موقوف' : 'عادي' }}
                        </span>
                    </div>
                    <div style="font-size:13px;opacity:.8;line-height:1.8;flex:1">{{ $s['help'] }}</div>
                    <x-filament::button
                        size="sm"
                        :color="$s['on'] ? 'success' : 'danger'"
                        wire:click="toggle('{{ $s['key'] }}')"
                        wire:confirm="{{ $s['on'] ? 'نرجّعو «'.$s['name'].'» للعادي؟' : 'نشغّلو «'.$s['name'].'» توّا؟' }}"
                    >
                        {{ $s['on'] ? 'رجّع للعادي' : 'شغّل الإيقاف' }}
                    </x-filament::button>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- الرسالة --}}
    <x-filament::section heading="رسالة الطوارئ" description="النص اللي يطلع للمستخدمين في شاشة «متوقف مؤقتاً». اكتبه بهدوء وبدون تفاصيل تقنية عن الثغرة.">
        <form wire:submit="saveMessage" style="display:flex;flex-direction:column;gap:10px">
            <x-filament::input.wrapper>
                <x-filament::input type="text" wire:model="message" maxlength="300" placeholder="{{ \App\Support\Emergency::DEFAULT_MESSAGE }}" />
            </x-filament::input.wrapper>
            @error('message') <div style="color:#dc2626;font-size:13px">{{ $message }}</div> @enderror
            <div><x-filament::button type="submit" size="sm">حفظ الرسالة</x-filament::button></div>
        </form>
    </x-filament::section>

    {{-- التحديث الإجباري --}}
    <x-filament::section heading="تحديث إجباري" description="لو الثغرة في التطبيق نفسه: انشر نسخة مصلّحة في المتاجر، وبعدها حط رقم بنائها هنا (الرقم اللي بعد + في version بملف pubspec.yaml، مثلاً 1.4.0+25 → 25). أي نسخة أقدم تطلب من المستخدم يحدّث وما تخدمش. 0 = بدون.">
        <form wire:submit="saveBuilds" style="display:flex;flex-direction:column;gap:12px">
            @foreach ($apps as $app => $label)
                <div style="display:grid;grid-template-columns:110px 120px 1fr 1fr;gap:8px;align-items:center">
                    <b>{{ $label }}</b>
                    <x-filament::input.wrapper>
                        <x-filament::input type="number" min="0" wire:model="builds.{{ $app }}.min" placeholder="أقل بناء" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper>
                        <x-filament::input type="url" dir="ltr" wire:model="builds.{{ $app }}.android" placeholder="رابط Google Play" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper>
                        <x-filament::input type="url" dir="ltr" wire:model="builds.{{ $app }}.ios" placeholder="رابط App Store" />
                    </x-filament::input.wrapper>
                </div>
            @endforeach
            @if ($errors->has('builds.*'))
                <div style="color:#dc2626;font-size:13px">تأكد من الأرقام والروابط.</div>
            @endif
            <div><x-filament::button type="submit" size="sm">حفظ</x-filament::button></div>
        </form>
    </x-filament::section>

    {{-- السجل --}}
    <x-filament::section heading="آخر عمليات الطوارئ" collapsible>
        @forelse ($log as $row)
            <div style="display:flex;gap:12px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.15);font-size:13px">
                <span dir="ltr" style="opacity:.7;white-space:nowrap">{{ $row->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                <span style="flex:1">{{ $row->description }}</span>
                <span style="opacity:.7">{{ $row->user?->name ?? 'السيرفر' }}</span>
            </div>
        @empty
            <div style="opacity:.7;font-size:13px">ما فيش عمليات طوارئ لين توّا.</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
