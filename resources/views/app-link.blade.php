<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ $app }}</title>
    <meta name="description" content="{{ $subtitle }}">
    {{-- معاينة الرابط في واتساب وفيسبوك --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $app }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $subtitle }}">
    <meta property="og:url" content="{{ $url }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
    @endif
    <style>
        :root { --brand: #D84315; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background: #f7f7f9; color: #222;
               min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .card { background: #fff; border-radius: 20px; max-width: 420px; width: 100%; overflow: hidden;
                box-shadow: 0 8px 30px rgba(0,0,0,.08); text-align: center; }
        .cover { width: 100%; aspect-ratio: 16/9; object-fit: cover; background: #eee; display: block; }
        .body { padding: 22px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #666; margin: 0 0 20px; line-height: 1.7; }
        a.btn { display: block; padding: 14px; border-radius: 12px; text-decoration: none; font-weight: bold; margin-top: 10px; }
        .primary { background: var(--brand); color: #fff; }
        .secondary { background: #fff; color: var(--brand); border: 2px solid var(--brand); }
        .app { font-size: 13px; color: #999; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        @if ($image)
            <img class="cover" src="{{ $image }}" alt="">
        @endif
        <div class="body">
            <h1>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
            @if (! empty($referral_code))
                {{-- دعوة صديق: التحميل أول (الكود يمشي مع Google Play وحده) --}}
                <a class="btn primary" href="{{ $play }}">حمّل التطبيق من Google Play</a>
                @if (! empty($app_store))
                    <a class="btn secondary" href="{{ $app_store }}">حمّل من App Store (آيفون)</a>
                @endif
                <div style="margin-top:14px;padding:12px;border:2px dashed #FF7900;border-radius:12px;text-align:center">
                    <div style="font-size:13px;color:#666">كود الدعوة</div>
                    <div style="font-size:28px;font-weight:800;letter-spacing:4px;direction:ltr">{{ $referral_code }}</div>
                    <div style="font-size:12px;color:#666;margin-top:4px">لو ما تحطّش وحده (آيفون مثلاً): افتح التطبيق ← حسابي ← «ادعُ صديقك» واكتبه.</div>
                </div>
                <a class="btn secondary" href="{{ $intent }}">عندي التطبيق — افتحه</a>
            @else
                <a class="btn primary" href="{{ $intent }}">افتح في التطبيق</a>
                <a class="btn secondary" href="{{ $play }}">حمّل التطبيق</a>
            @endif
            @if(! empty($web) && empty($referral_code))
                <a class="btn secondary" href="{{ $web }}">اطلب من الموقع (آيفون أو كمبيوتر)</a>
            @endif
            <div class="app">{{ $app }}</div>
        </div>
    </div>
</body>
</html>
