<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $name }} — اطلب أونلاين</title>
<meta name="description" content="اطلب من مطاعم ومتاجر {{ $name }} من المتصفح — بدون تطبيق.">
<meta name="theme-color" content="#075C52">
<link rel="icon" type="image/svg+xml" href="/brand/azanx-mark.svg">
<link rel="manifest" href="{{ $base }}/manifest.webmanifest">
<link rel="icon" type="image/png" href="/weborder/icon-192.png">
<link rel="apple-touch-icon" href="/weborder/icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $name }}">
<meta property="og:title" content="{{ $name }}">
<meta property="og:description" content="اطلب من مطاعم ومتاجر {{ $name }}">
<meta property="og:image" content="{{ $logo ?: url('/weborder/icon-512.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/weborder/app.css?v={{ $v }}">
</head>
<body>
@if(! $enabled)
    <div class="closed-site">
        <img src="/brand/azanx-logo.svg" alt="ازانكس" class="closed-logo" style="height:56px;width:auto">
        <h1>{{ $name }}</h1>
        <p>الطلب من الموقع موقوف مؤقتاً. تقدر تطلب من التطبيق.</p>
    </div>
@else
    <div id="app">
        <header class="topbar" id="topbar"></header>
        <div id="notice"></div>
        <main id="view" class="view"><div class="loading"><span class="spinner"></span></div></main>
        <nav class="bottomnav" id="bottomnav"></nav>
    </div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <noscript><p style="padding:24px;text-align:center">الموقع يحتاج JavaScript.</p></noscript>
    <script>window.APP_CONFIG = @json($config);</script>
    <script src="/weborder/blurhash.js?v={{ $v }}" defer></script>
    <script src="/weborder/app.js?v={{ $v }}" defer></script>
@endif
</body>
</html>
