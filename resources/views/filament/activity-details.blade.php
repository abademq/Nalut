@php
    /** @var \App\Models\ActivityLog $log */
    $p = $log->properties ?? [];
    $show = function ($v) {
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($v === null) return '—';
        if ($v === true) return 'نعم';
        if ($v === false) return 'لا';
        return (string) $v;
    };
    $fields = [
        'status' => 'الحالة', 'name' => 'الاسم', 'price' => 'السعر', 'discount_price' => 'سعر التخفيض',
        'is_available' => 'متوفر', 'is_active' => 'مفعّل', 'is_open' => 'مفتوح', 'stock_quantity' => 'الكمية',
        'quantity' => 'الكمية', 'total' => 'الإجمالي', 'subtotal' => 'المجموع', 'delivery_fee' => 'التوصيل',
        'driver_id' => 'السائق', 'customer_id' => 'الزبون', 'store_id' => 'المتجر', 'amount' => 'المبلغ',
        'balance_after' => 'الرصيد بعدها', 'role' => 'الدور', 'roles' => 'الأدوار', 'value' => 'القيمة',
        'is_online' => 'متاح', 'is_approved' => 'معتمد', 'notes' => 'ملاحظات', 'phone' => 'الهاتف',
        'prep_time_minutes' => 'مدة التحضير', 'cancel_reason' => 'سبب الإلغاء', 'type' => 'النوع',
    ];
@endphp

<div style="display:flex;flex-direction:column;gap:16px;font-size:14px" dir="rtl">
    <div style="display:grid;grid-template-columns:120px 1fr;gap:6px 12px">
        <span style="opacity:.6">الوقت</span><span>{{ $log->created_at?->setTimezone(config('app.local_timezone', 'Africa/Tripoli'))->format('d/m/Y H:i:s') }}</span>
        <span style="opacity:.6">من</span><span>{{ \App\Models\ActivityLog::APPS[$log->app] ?? $log->app }}</span>
        <span style="opacity:.6">الحساب</span><span>{{ $log->user ? $log->user->name.' — '.$log->user->phone.' ('.$log->user->rolesLabel().')' : '—' }}</span>
        @if ($log->order)
            <span style="opacity:.6">الطلب</span><span>{{ $log->order->code }}</span>
        @endif
        @if ($log->status)
            <span style="opacity:.6">النتيجة</span><span style="color:{{ $log->status >= 400 ? '#dc2626' : '#16a34a' }}">{{ $log->status >= 400 ? 'فشل' : 'تم' }} ({{ $log->status }})</span>
        @endif
        <span style="opacity:.6">كود العملية</span><span style="font-family:monospace;direction:ltr;text-align:right">{{ $log->action }}</span>
        @if ($log->ip)
            <span style="opacity:.6">IP</span><span style="font-family:monospace">{{ $log->ip }}</span>
        @endif
        @if ($log->device)
            <span style="opacity:.6">الجهاز</span><span style="font-size:12px;direction:ltr;text-align:right">{{ $log->device }}</span>
        @endif
    </div>

    @if (! empty($p['error']))
        <div style="padding:10px;border-radius:8px;background:#fee2e2;color:#991b1b">{{ $p['error'] }}</div>
    @endif
    @if (! empty($p['errors']))
        <div style="padding:10px;border-radius:8px;background:#fee2e2;color:#991b1b">
            @foreach ($p['errors'] as $field => $msgs)
                <div>{{ $field }}: {{ implode('، ', (array) $msgs) }}</div>
            @endforeach
        </div>
    @endif

    @if (! empty($p['changes']))
        <div>
            <div style="font-weight:700;margin-bottom:6px">التغييرات</div>
            @php
                // سطر تعديل من اللوحة: changes = {field: [old, new]} — ومن التطبيق: قائمة جداول
                $groups = isset($p['changes'][0]['model'])
                    ? $p['changes']
                    : [['model' => '', 'id' => null, 'event' => str_replace('model.', '', $log->action), 'changes' => $p['changes'], '_self' => true]];
            @endphp
            @foreach ($groups as $g)
                <div style="border:1px solid rgba(127,127,127,.25);border-radius:8px;margin-bottom:8px;overflow:hidden">
                    @unless (! empty($g['_self']))
                        <div style="padding:6px 10px;background:rgba(127,127,127,.08);font-weight:600">
                            {{ ['created' => 'إضافة', 'updated' => 'تعديل', 'deleted' => 'حذف', 'restored' => 'استرجاع'][$g['event']] ?? $g['event'] }}
                            {{ $g['model'] }} @if ($g['id']) #{{ $g['id'] }} @endif
                        </div>
                    @endunless
                    @if (! empty($g['changes']))
                        <table style="width:100%;border-collapse:collapse;font-size:13px">
                            <tr style="opacity:.6"><td style="padding:4px 10px">الحقل</td><td style="padding:4px 10px">قبل</td><td style="padding:4px 10px">بعد</td></tr>
                            @foreach ($g['changes'] as $field => $pair)
                                <tr style="border-top:1px solid rgba(127,127,127,.15)">
                                    <td style="padding:4px 10px;white-space:nowrap">{{ $fields[$field] ?? $field }}</td>
                                    <td style="padding:4px 10px;color:#b91c1c;word-break:break-word">{{ $show(is_array($pair) && array_is_list($pair) && count($pair) === 2 ? $pair[0] : null) }}</td>
                                    <td style="padding:4px 10px;color:#15803d;word-break:break-word">{{ $show(is_array($pair) && array_is_list($pair) && count($pair) === 2 ? $pair[1] : $pair) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @foreach (['request' => 'البيانات اللي انبعتت', 'data' => 'تفاصيل الحدث'] as $key => $title)
        @if (! empty($p[$key]))
            <div>
                <div style="font-weight:700;margin-bottom:6px">{{ $title }}</div>
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    @foreach ($p[$key] as $k => $v)
                        <tr style="border-top:1px solid rgba(127,127,127,.15)">
                            <td style="padding:4px 10px;opacity:.7;white-space:nowrap;vertical-align:top">{{ $fields[$k] ?? $k }}</td>
                            <td style="padding:4px 10px;word-break:break-word">{{ $show($v) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endforeach

    @if (! empty($p['files']))
        <div><span style="font-weight:700">ملفات:</span> {{ implode('، ', $p['files']) }}</div>
    @endif
</div>
