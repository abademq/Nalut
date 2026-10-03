<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>رمز التحقق — لوحة التحكم</title>
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;
               font-family: system-ui, Tahoma, sans-serif; background:#f4f6f5; color:#1c2b29; }
        .card { width:100%; max-width:400px; background:#fff; border-radius:16px; padding:28px 24px; box-shadow:0 6px 24px rgba(0,0,0,.07); }
        h1 { font-size:20px; margin:0 0 6px; color:#075C52; }
        p { margin:0 0 16px; color:#5b6b67; line-height:1.8; font-size:14px; }
        input[type=text] { width:100%; box-sizing:border-box; font-size:28px; letter-spacing:10px; text-align:center; padding:12px; border:1.5px solid #cfd8d5; border-radius:12px; direction:ltr; }
        input[type=text]:focus { outline:none; border-color:#075C52; }
        label.trust { display:flex; gap:8px; align-items:center; margin:14px 0; font-size:14px; }
        button { width:100%; padding:13px; border:0; border-radius:12px; background:#FF7900; color:#fff; font-size:16px; font-weight:700; cursor:pointer; font-family:inherit; }
        .err { background:#fdecec; color:#b42318; padding:10px 12px; border-radius:10px; margin-bottom:14px; font-size:14px; }
        .links { display:flex; justify-content:space-between; margin-top:16px; font-size:13px; }
        .links button { width:auto; background:none; color:#075C52; padding:0; font-size:13px; font-weight:600; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔐 رمز التحقق</h1>
    <p>بعتنالك رمز على رقمك {{ $phone }}{{ $channel === 'whatsapp' ? ' في واتساب' : ($channel === 'sms' ? ' برسالة SMS' : '') }}. اكتبه باش تكمّل للوحة التحكم.</p>
    @if ($error)
        <div class="err">{{ $error }}</div>
    @endif
    <form method="POST" action="{{ route('admin.2fa.verify') }}">
        @csrf
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" autofocus required placeholder="••••">
        @if ($trustDays > 0)
            <label class="trust"><input type="checkbox" name="trust" value="1"> هذا جهازي — ما تطلبش الرمز هني لمدة {{ $trustDays }} يوم</label>
        @endif
        <button type="submit">تأكيد</button>
    </form>
    <div class="links">
        <form method="POST" action="{{ route('admin.2fa.resend') }}">@csrf<button type="submit">ابعت رمز جديد</button></form>
        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">@csrf<button type="submit">خروج</button></form>
    </div>
</div>
</body>
</html>
