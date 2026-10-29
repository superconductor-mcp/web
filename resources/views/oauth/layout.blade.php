<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · {{ config('app.name', 'Venusian') }}</title>
    <style>
        :root { color-scheme: light dark; --fg: #1b1b1f; --muted: #5d5d66; --bg: #f6f6f8; --card: #fff; --line: #dcdce2; --accent: #4b3fd1; --danger: #b42318; }
        @media (prefers-color-scheme: dark) { :root { --fg: #ececf1; --muted: #a0a0ab; --bg: #121216; --card: #1c1c22; --line: #2e2e37; --accent: #8f86ff; --danger: #f97066; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
        h1 { font-size: 1.25rem; margin: 0 0 12px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: .9rem; }
        ul { margin: 0 0 16px; padding-left: 20px; }
        code { font-size: .85rem; word-break: break-all; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input[type=email], input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: transparent; color: inherit; font: inherit; }
        .error { color: var(--danger); font-size: .9rem; margin-top: 6px; }
        .actions { display: flex; gap: 12px; margin-top: 20px; }
        button { flex: 1; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); background: transparent; color: inherit; font: inherit; font-weight: 600; cursor: pointer; }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button:focus-visible, input:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
