<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BTC Supertrend Alerts</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; background: #0f1115; color: #e8e8e8; }
        button { padding: .6rem 1rem; border: 0; border-radius: 6px; cursor: pointer; font-size: 1rem; }
        #enable { background: #2f6feb; color: #fff; }
        #enable.on { background: #238636; }
        ul { list-style: none; padding: 0; }
        li { padding: .7rem .9rem; margin: .5rem 0; border-radius: 6px; background: #1a1d24; border-left: 5px solid #888; }
        li.flip_green { border-color: #2ea043; }
        li.flip_red { border-color: #f85149; }
        li.cycle_red_green_red { border-color: #d29922; background: #2a2412; }
        small { color: #9aa0a6; }
    </style>
</head>
<body>
    <h2>BTC CE / PE — Supertrend alerts</h2>
    <p><button id="enable">🔇 Click to enable sound</button> <small id="status"></small></p>
    <ul id="list">
        @foreach ($alerts as $a)
            <li class="{{ $a->type }}" data-id="{{ $a->id }}">
                {{ $a->message }}<br>
                <small>{{ $a->candle_label }} · alert sent {{ $a->created_at->timezone('Asia/Kolkata')->format('H:i:s') }}</small>
            </li>
        @endforeach
    </ul>

<script>
    let audioCtx = null;
    let lastId = {{ $alerts->max('id') ?? 0 }};
    const btn = document.getElementById('enable');

    // Browsers block audio until a user gesture, so one click is required per page load.
    btn.onclick = () => {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        btn.textContent = '🔊 Sound on';
        btn.classList.add('on');
        beep([880], 0.15); // confirmation
    };

    function beep(freqs, dur = 0.25, repeat = 1) {
        if (!audioCtx) return;
        let t = audioCtx.currentTime;
        for (let r = 0; r < repeat; r++) {
            freqs.forEach(f => {
                const o = audioCtx.createOscillator(), g = audioCtx.createGain();
                o.frequency.value = f; o.type = 'square';
                g.gain.setValueAtTime(0.15, t);
                g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
                o.connect(g); g.connect(audioCtx.destination);
                o.start(t); o.stop(t + dur);
                t += dur + 0.05;
            });
        }
    }

    const sounds = {
        flip_green: () => beep([660, 880, 1100]),             // rising = bullish
        flip_red: () => beep([1100, 880, 660]),               // falling = bearish
        cycle_red_green_red: () => beep([1200, 600], 0.2, 3), // urgent: GREEN -> RED flip
    };

    function esc(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    async function poll() {
        try {
            const r = await fetch('{{ route('alerts.feed') }}?after=' + lastId, { headers: { Accept: 'application/json' } });
            const items = await r.json();
            const ul = document.getElementById('list');
            for (const a of items) {
                lastId = Math.max(lastId, a.id);
                const li = document.createElement('li');
                li.className = a.type;
                li.innerHTML = esc(a.message) + '<br><small>' + esc(a.candle_label) + ' · alert sent just now</small>';
                ul.prepend(li);
                (sounds[a.type] || sounds.flip_green)();
                if (Notification.permission === 'granted') new Notification('BTC Supertrend', { body: a.message });
            }
            document.getElementById('status').textContent = 'checked ' + new Date().toLocaleTimeString();
        } catch (e) {
            document.getElementById('status').textContent = 'connection issue, retrying…';
        }
    }

    if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    setInterval(poll, 10000);
</script>
</body>
</html>