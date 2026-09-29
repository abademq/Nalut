<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $doc->title }} — {{ $app }}</title>
<style>
  :root { --text:#1f2937; --muted:#6b7280; --bg:#f7f7f8; --card:#fff; --brand:#D84315; }
  @media (prefers-color-scheme: dark) { :root { --text:#e5e7eb; --muted:#9ca3af; --bg:#111318; --card:#1a1d24; } }
  body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; background:var(--bg); color:var(--text); line-height:1.8; }
  main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
  article { background:var(--card); border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
  h1 { font-size: 24px; margin: 0 0 4px; } h2 { font-size: 18px; margin: 24px 0 6px; color: var(--brand); }
  .meta, .lead { color: var(--muted); } ul { padding-inline-start: 20px; }
  nav { margin-top: 16px; font-size: 14px; } nav a { color: var(--brand); margin-inline-end: 12px; }
</style>
</head>
<body>
<main>
  <article>
    <h1>{{ $doc->title }}</h1>
    <div class="meta">{{ $app }} — النسخة {{ $doc->version }} — آخر تحديث {{ $doc->updated_at?->format('Y/m/d') }}</div>
    {!! $doc->html() !!}
  </article>
  <nav>
    @foreach (\App\Models\LegalDocument::DOCUMENTS as $k => $t)
      <a href="{{ url('/legal/'.str_replace('_', '-', $k)) }}">{{ $t }}</a>
    @endforeach
  </nav>
</main>
</body>
</html>
