<x-filament-panels::page>
    @php($c = $this->data ?? [])
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        {{-- معاينة تقريبية لشاشة في التطبيق --}}
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
            <div dir="rtl" style="width:300px;border-radius:28px;overflow:hidden;border:8px solid #222;background:{{ $c['background'] ?? '#FAF9F7' }};font-family:inherit">
                <div style="padding:14px 16px;color:{{ $c['primary'] ?? '#075C52' }};font-weight:800;font-size:18px">ازانكس</div>
                <div style="margin:0 12px 10px;padding:10px 12px;border-radius:14px;background:{{ $c['input'] ?? '#F1F0EC' }};color:{{ $c['muted'] ?? '#8A948F' }};font-size:13px">ابحث عن مطعم أو صنف</div>
                <div style="display:flex;gap:6px;margin:0 12px 10px">
                    <span style="padding:6px 12px;border-radius:10px;background:{{ $c['primary_soft'] ?? '#E3EFEC' }};color:{{ $c['primary_dark'] ?? '#043933' }};font-size:12px;font-weight:700">مطاعم</span>
                    <span style="padding:6px 12px;border-radius:10px;background:{{ $c['card'] ?? '#FFFFFF' }};color:{{ $c['muted'] ?? '#8A948F' }};font-size:12px">حلويات</span>
                </div>
                <div style="margin:0 12px 10px;padding:12px;border-radius:16px;background:{{ $c['card'] ?? '#FFFFFF' }}">
                    <div style="color:{{ $c['ink'] ?? '#1C2B29' }};font-weight:700">شاورما دجاج</div>
                    <div style="color:{{ $c['muted'] ?? '#8A948F' }};font-size:12px">خبز صاج، ثوم، مخلل</div>
                    <div style="color:{{ $c['accent'] ?? '#FF7900' }};font-weight:800;margin-top:6px">12.00 د.ل</div>
                </div>
                <div style="margin:0 12px 12px;padding:12px;border-radius:14px;text-align:center;background:{{ $c['accent'] ?? '#FF7900' }};color:{{ $c['button_text'] ?? '#FFFFFF' }};font-weight:800">تأكيد الطلب</div>
                <div style="display:flex;justify-content:space-around;padding:10px 0;background:{{ $c['card'] ?? '#FFFFFF' }};font-size:11px">
                    <span style="color:{{ $c['primary'] ?? '#075C52' }};font-weight:700">الرئيسية</span>
                    <span style="color:{{ $c['muted'] ?? '#8A948F' }}">طلباتي</span>
                    <span style="color:{{ $c['muted'] ?? '#8A948F' }}">حسابي</span>
                </div>
            </div>
            <p style="max-width:360px;font-size:13px;opacity:.8;line-height:1.9">
                المعاينة تقريبية. التطبيق ياخذ الألوان الجديدة لما يتفتح أو يرجع من الخلفية.
                اختار ألوان فيها تباين واضح بين النص والخلفية (خصوصاً لون نص الأزرار فوق لون الأزرار).
            </p>
        </div>

        <x-filament::button type="submit">حفظ</x-filament::button>
    </form>
</x-filament-panels::page>
