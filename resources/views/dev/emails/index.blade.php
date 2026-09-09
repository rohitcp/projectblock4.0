{{-- Local-only index of every transactional email. See EmailPreviewController for why. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email previews — Project Block</title>
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  <style>
    body { font-family: Inter, Arial, sans-serif; background:#f3f4f6; margin:0; padding:32px; color:#23272f; }
    h1 { font-size:20px; margin:0 0 4px; }
    p.sub { color:#6b7280; font-size:13px; margin:0 0 24px; }
    ul { list-style:none; padding:0; margin:0; max-width:520px; }
    li a { display:flex; justify-content:space-between; align-items:center; background:#fff;
           border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:8px;
           text-decoration:none; color:#23272f; font-size:14px; }
    li a:hover { border-color:#1b5f8a; }
    code { color:#9ca3af; font-size:12px; }
  </style>
</head>
<body>
  <h1>Transactional email previews</h1>
  <p class="sub">Every email rendered from the shared shell against sample data. Local only.</p>
  <ul>
    @foreach ($samples as $slug => $sample)
      <li><a href="{{ route('dev.emails.show', $slug) }}">{{ $sample['label'] }} <code>{{ $slug }}</code></a></li>
    @endforeach
  </ul>
</body>
</html>
