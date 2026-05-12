<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — order exports</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 42rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        h1 { font-size: 1.25rem; }
        section { margin-top: 1.5rem; }
        a.button { display: inline-block; margin: 0.35rem 0.35rem 0.35rem 0; padding: 0.5rem 0.85rem; background: #2563eb; color: #fff; text-decoration: none; border-radius: 6px; font-size: 0.9rem; }
        a.button.secondary { background: #475569; }
        label { display: block; margin-top: 0.75rem; font-weight: 600; font-size: 0.85rem; }
        input[type="date"] { margin-top: 0.25rem; padding: 0.4rem; }
        .muted { color: #64748b; font-size: 0.875rem; }
        .warn { background: #fef3c7; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <h1>Download orders (Excel)</h1>
    <p class="muted">Uses <code>ADMIN_EXPORT_TOKEN</code> from your server <code>.env</code>. Append <code>?token=YOUR_SECRET</code> to this page URL once, then use the buttons below.</p>

    @unless($hasToken)
        <div class="warn">Add <code>?token=…</code> to this page’s address bar (same value as <code>ADMIN_EXPORT_TOKEN</code>) to enable downloads.</div>
    @else
        <section>
            <h2 class="muted" style="font-size:1rem;margin:0 0 0.5rem 0">Presets</h2>
            <a class="button" href="{{ url('/admin/orders/export?token='.urlencode($token).'&period=day') }}">Today</a>
            <a class="button" href="{{ url('/admin/orders/export?token='.urlencode($token).'&period=week') }}">This week</a>
            <a class="button" href="{{ url('/admin/orders/export?token='.urlencode($token).'&period=month') }}">This month</a>
        </section>

        <section>
            <h2 class="muted" style="font-size:1rem;margin:0 0 0.5rem 0">Custom range</h2>
            <form id="range-form">
                <label for="from">From</label>
                <input type="date" id="from" name="from" required>
                <label for="to">To</label>
                <input type="date" id="to" name="to" required>
                <p style="margin-top:1rem">
                    <button type="submit" class="button" style="border:none;cursor:pointer">Download range</button>
                </p>
            </form>
        </section>

        <script>
            document.getElementById('range-form').addEventListener('submit', function (e) {
                e.preventDefault();
                const from = document.getElementById('from').value;
                const to = document.getElementById('to').value;
                const q = new URLSearchParams({ token: @json($token), period: 'range', from, to });
                window.location = @json(url('/admin/orders/export')) + '?' + q.toString();
            });
        </script>
    @endunless

    <p class="muted" style="margin-top:2rem">Direct API: <code>GET /admin/orders/export?token=…&amp;period=day|week|month|range</code> — for <code>range</code> add <code>&amp;from=Y-m-d&amp;to=Y-m-d</code>.</p>
</body>
</html>
