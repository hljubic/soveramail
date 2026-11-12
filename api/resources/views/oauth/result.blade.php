<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sovera Mail · {{ $ok ? 'Account connected' : 'Sign-in failed' }}</title>
  <style>
    :root { color-scheme: light dark; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f1115; color: #e6e7ea; }
    .card { max-width: 440px; padding: 36px 40px; border-radius: 16px; background: #171a21; border: 1px solid #262a33; text-align: center; }
    .icon { width: 56px; height: 56px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 18px; font-size: 26px; background: {{ $ok ? '#123d2a' : '#4a1d1d' }}; }
    h1 { font-size: 20px; margin: 0 0 8px; }
    p { margin: 0 0 18px; color: #aab0bd; }
    a.btn { display: inline-block; padding: 10px 18px; border-radius: 10px; background: #3b82f6; color: white; text-decoration: none; font-weight: 600; }
    small { display: block; margin-top: 14px; color: #6b7280; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">{{ $ok ? '✓' : '!' }}</div>
    <h1>{{ $ok ? 'Account connected' : 'Sign-in failed' }}</h1>
    <p>{{ $message }}</p>
    <a class="btn" href="{{ $returnUrl }}">Return to Sovera Mail</a>
    <small>You can close this tab. The Sovera Mail tab picks up the new account by itself.</small>
  </div>
  <script>
    // The sign-in tab was opened by Sovera Mail: close it so the user is back in the app.
    setTimeout(function () { window.close(); }, 1500);
  </script>
</body>
</html>
