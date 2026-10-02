<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>متوقف مؤقتاً</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: system-ui, Tahoma, sans-serif; background: #f6f6f6; color: #222; padding: 16px; box-sizing: border-box; }
        .card { max-width: 440px; background: #fff; border-radius: 16px; padding: 32px 24px; text-align: center;
                box-shadow: 0 4px 20px rgba(0,0,0,.06); }
        .icon { font-size: 48px; }
        h1 { font-size: 22px; margin: 12px 0 8px; }
        p { line-height: 1.8; color: #555; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🛠️</div>
        <h1>متوقف مؤقتاً</h1>
        <p>{{ $message }}</p>
    </div>
</body>
</html>
