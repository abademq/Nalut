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
            <a class="btn primary" href="{{ $intent }}">افتح في التطبيق</a>
            <a class="btn secondary" href="{{ $play }}">حمّل التطبيق</a>
            <div class="app">{{ $app }}</div>
        </div>
    </div>
</body>
</html>
