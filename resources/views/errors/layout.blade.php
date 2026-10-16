<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f5f6fb; --card: #fff; --fg: #1f2440; --muted: #676879; --brand: #6161ff; --border: #e3e5ef; }
        @media (prefers-color-scheme: dark) { :root { --bg: #181b2e; --card: #23263d; --fg: #eef0fa; --muted: #a5a9c4; --border: #353955; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--fg); font-family: Figtree, ui-sans-serif, system-ui, sans-serif; }
        .card { max-width: 440px; width: 100%; background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 36px 32px; text-align: center; box-shadow: 0 10px 30px -15px rgba(16, 24, 64, .25); }
        .code { display: inline-block; font-weight: 800; font-size: 13px; letter-spacing: .08em; color: var(--brand); background: rgba(97, 97, 255, .1); border-radius: 999px; padding: 4px 12px; }
        h1 { margin: 16px 0 8px; font-size: 22px; }
        p { margin: 0 0 24px; color: var(--muted); line-height: 1.5; }
        a { display: inline-block; background: var(--brand); color: #fff; text-decoration: none; font-weight: 600; padding: 10px 18px; border-radius: 10px; }
    </style>
</head>
<body>
    <main class="card">
        <span class="code">ERROR @yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/dashboard') }}">Back to overview</a>
    </main>
</body>
</html>
