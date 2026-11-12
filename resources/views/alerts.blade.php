<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>BTC Supertrend Alerts</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 1100px; margin: 1.5rem auto; padding: 0 1rem; background: #0f1115; color: #e8e8e8; }
        h2 { margin: 0 0 .75rem; }
        h3 { margin: 0 0 .6rem; font-size: 1.05rem; }
        section { background: #151821; border: 1px solid #232733; border-radius: 8px; padding: .9rem 1rem; margin: 1rem 0; }
        button { padding: .5rem .9rem; border: 0; border-radius: 6px; cursor: pointer; font-size: .95rem; color: #fff; background: #2f6feb; }
        button.ghost { background: #2a2f3a; }
        button.good { background: #238636; }
        button:disabled { opacity: .5; cursor: default; }
        #enable.on { background: #238636; }
        small, .muted { color: #9aa0a6; }
        .scroll { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; font-size: .92rem; }
        th, td { padding: .4rem .55rem; text-align: right; border-bottom: 1px solid #232733; white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; }
        select, input { background: #0f1115; color: #e8e8e8; border: 1px solid #333a48; border-radius: 6px; padding: .4rem .5rem; font-size: .95rem; }
        input[type=number] { width: 8rem; }
        .pos { color: #3fb950; } .neg { color: #f85149; }
        .badge { display: inline-block; padding: .05rem .45rem; border-radius: 999px; font-size: .8rem; }
        .badge.green { background: #13361d; color: #3fb950; } .badge.red { background: #3d1517; color: #f85149; }
        .badge.sl_hit { background: #3d1517; color: #f85149; } .badge.closed, .badge.expired { background: #262a33; color: #9aa0a6; }

        /* option chain (same columns as Delta: Delta, Bid, Mark, Ask, OI) */
        .toolbar { display: flex; flex-wrap: wrap; gap: .6rem 1rem; align-items: center; margin-bottom: .7rem; }
        .toolbar label { color: #9aa0a6; font-size: .85rem; margin-right: .3rem; }
        .spotpill { margin-left: auto; font-weight: 700; color: #f0b429; }
        #chain { font-size: .88rem; font-variant-numeric: tabular-nums; }
        #chain th { text-align: center; color: #9aa0a6; font-weight: 500; font-size: .78rem; border-bottom: 1px solid #2a2f3a; }
        #chain thead tr.group th { font-size: .85rem; font-weight: 700; letter-spacing: .04em; border-bottom: 0; }
        #chain th.calls { color: #3fb950; } #chain th.puts { color: #f85149; }
        #chain td { text-align: right; padding: .38rem .55rem; border-bottom: 1px solid #1c202b; }
        #chain td.strike { text-align: center; font-weight: 700; background: #1b1f2a; border-left: 1px solid #2a2f3a; border-right: 1px solid #2a2f3a; min-width: 4.5rem; }
        #chain td.opt.itm { background: #1a1e29; }
        #chain td.opt[data-symbol] { cursor: pointer; }
        #chain tr:hover td.opt[data-symbol] { background: #202637; }
        #chain td.opt.sel { background: #17325f !important; }
        #chain td.opt.trk { box-shadow: inset 0 -2px 0 #d29922; }
        #chain td.bid { color: #3fb950; } #chain td.ask { color: #f85149; } #chain td.mark { font-weight: 700; }
        #chain td.dim { color: #9aa0a6; }
        #chain tr.atm td.strike { background: #243257; color: #fff; }
        #chain tr.spotrow td { padding: 0; border: 0; height: 0; }
        #chain tr.spotrow div { position: relative; border-top: 1px solid #f0b429; margin: 0; height: 0; }
        #chain tr.spotrow span { position: absolute; left: 50%; top: -.65rem; transform: translateX(-50%); background: #f0b429; color: #111; font-size: .72rem; font-weight: 700; padding: .05rem .5rem; border-radius: 999px; }
        #chain tr.spotrow td { line-height: 0; }

        #panel { display: none; border-color: #2f6feb; }
        #panel .row { display: flex; flex-wrap: wrap; gap: .8rem 1.2rem; align-items: end; margin: .6rem 0; }
        #panel label { display: block; font-size: .8rem; color: #9aa0a6; margin-bottom: .2rem; }
        #msg { margin: .5rem 0 0; font-size: .92rem; }
        #msg.ok { color: #3fb950; } #msg.err { color: #f85149; }

        /* alerts */
        ul { list-style: none; padding: 0; margin: 0; }
        li { padding: .65rem .9rem; margin: .5rem 0; border-radius: 6px; background: #1a1d24; border-left: 5px solid #888; }
        li.flip_green { border-color: #2ea043; }
        li.flip_red { border-color: #f85149; }
        li.cycle_red_green_red { border-color: #d29922; background: #2a2412; }
        li.sl_hit { border-color: #f85149; background: #2d1315; }
        li .trade { margin-top: .4rem; padding: .25rem .6rem; font-size: .85rem; }
    </style>
</head>
<body>
    <h2>BTC CE / PE — Supertrend alerts</h2>
    <p><button id="enable">🔇 Click to enable sound</button> <small id="status"></small></p>

    <section id="trades">
        <h3>My trades — SL: Supertrend flips RED → GREEN</h3>
        <div class="scroll">
            <table>
                <thead><tr>
                    <th>Contract</th><th>Feed</th><th>Entry</th><th>Now</th><th>Supertrend</th>
                    <th title="Current Supertrend line. For a short, SL triggers when a candle closes above it.">SL line</th>
                    <th>P/L (short)</th><th></th>
                </tr></thead>
                <tbody id="trades-body"><tr><td colspan="8" class="muted">Loading…</td></tr></tbody>
            </table>
        </div>
        <div id="history" class="muted" style="margin-top:.6rem"></div>
    </section>

    <section id="panel">
        <h3>Add trade &amp; SL</h3>
        <div><b id="sel-label"></b> <small id="sel-sym"></small></div>
        <div class="row">
            <div><label for="entry">Entry price (your sell price)</label><input id="entry" type="number" step="any" min="0"></div>
            <div><label for="feed">Price feed</label>
                <select id="feed"><option value="mark">Mark price</option><option value="traded">Traded price</option></select></div>
            <div><label for="tf">Timeframe</label><select id="tf"></select></div>
            <div><button id="track" class="good">Start tracking SL</button> <button id="cancel" class="ghost">Cancel</button></div>
        </div>
        <small>Entry is pre-filled with the bid (the price you sell at); change it to your actual fill. SL rule: alert when the Supertrend flips <b>RED → GREEN</b> on a <b>closed</b> candle after you entered. This only tracks and alerts; it does not place or close orders.</small>
        <div id="msg"></div>
    </section>

    <section id="chain-box">
        <h3>Option chain <small id="chain-status"></small></h3>
        <div class="toolbar">
            <span><label for="expiry">Expiry</label><select id="expiry"></select></span>
            <span><label for="win">Strikes</label>
                <select id="win">
                    <option value="6">±6</option><option value="12" selected>±12</option>
                    <option value="20">±20</option><option value="40">All</option>
                </select></span>
            <span class="spotpill" id="spotpill"></span>
        </div>
        <div class="scroll">
            <table id="chain">
                <thead>
                    <tr class="group"><th colspan="5" class="calls">CALLS (CE)</th><th></th><th colspan="5" class="puts">PUTS (PE)</th></tr>
                    <tr>
                        <th>OI</th><th>Delta</th><th>Bid</th><th>Mark</th><th>Ask</th>
                        <th>Strike</th>
                        <th>Bid</th><th>Mark</th><th>Ask</th><th>Delta</th><th>OI</th>
                    </tr>
                </thead>
                <tbody id="chain-body"><tr><td colspan="11" class="muted" style="text-align:center">Loading…</td></tr></tbody>
            </table>
        </div>
        <small>Click any CE or PE price to select that contract. Prices refresh every 5 seconds; Delta's own page is live, so expect small differences between the two at any single moment.</small>
    </section>

    <section>
        <h3>Alerts</h3>
        <ul id="list"></ul>
    </section>

<script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const DEFAULT_RES = @json(config('supertrend.resolution') ?: '15m');
    const INITIAL = @json($alerts->values());
    const RES_LIST = ['1m', '3m', '5m', '10m', '15m', '30m', '1h'];
    const URLS = {
        feed: @json(route('alerts.feed')),
        chain: @json(route('chain')),
        positions: @json(route('positions.index')),
        store: @json(route('positions.store')),
        destroy: @json(url('/positions')),
    };

    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const isNum = n => !(n === null || n === undefined || n === '' || isNaN(n));
    const fmt = n => isNum(n) ? Number(n).toLocaleString('en-IN', { maximumFractionDigits: 1 }) : '-';
    const f1 = n => isNum(n) ? Number(n).toLocaleString('en-IN', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '-';
    const f2 = n => isNum(n) ? Number(n).toFixed(2) : '-';
    const money = n => !isNum(n) ? '-' : n >= 1e6 ? '$' + (n / 1e6).toFixed(2) + 'M' : n >= 1e3 ? '$' + (n / 1e3).toFixed(2) + 'K' : '$' + Math.round(n);
    const istTime = iso => new Date(iso).toLocaleTimeString('en-GB', { timeZone: 'Asia/Kolkata' });

    async function api(url, opts = {}) {
        const r = await fetch(url, {
            ...opts,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, ...(opts.headers || {}) },
        });
        let body = null;
        try { body = await r.json(); } catch (e) { /* empty body */ }
        if (!r.ok) {
            const first = body && body.errors ? Object.values(body.errors).flat()[0] : null;
            throw new Error((body && body.message && !first ? body.message : first) || ('HTTP ' + r.status));
        }
        return body;
    }

    /* ---------- sound ---------- */
    let audioCtx = null;
    $('enable').onclick = () => {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        $('enable').textContent = '🔊 Sound on';
        $('enable').classList.add('on');
        beep([880], 0.15);
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
        flip_green: () => beep([660, 880, 1100]),
        flip_red: () => beep([1100, 880, 660]),
        cycle_red_green_red: () => beep([1200, 600], 0.2, 3),   // GREEN -> RED flip
        sl_hit: () => beep([1600, 700], 0.18, 5),               // your SL was hit
    };

    /* ---------- trade panel ---------- */
    let selected = null; // {symbol, side, strike, price, source?, resolution?}

    $('tf').innerHTML = RES_LIST.map(r => `<option value="${r}">${r}</option>`).join('');

    function setMsg(text, kind) { $('msg').textContent = text || ''; $('msg').className = kind || ''; }

    function selectContract(c) {
        selected = c;
        $('panel').style.display = 'block';
        $('sel-label').textContent = `${c.side} ${fmt(c.strike)}`;
        $('sel-sym').textContent = c.symbol;
        $('entry').value = isNum(c.price) ? Math.round(c.price * 100) / 100 : '';
        $('feed').value = c.source || 'mark';
        $('tf').value = RES_LIST.includes(c.resolution) ? c.resolution : (RES_LIST.includes(DEFAULT_RES) ? DEFAULT_RES : '15m');
        setMsg('');
        renderChain();
        $('panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    $('cancel').onclick = () => { selected = null; $('panel').style.display = 'none'; renderChain(); };

    $('track').onclick = async () => {
        if (!selected) return;
        const entry = parseFloat($('entry').value);
        if (!(entry > 0)) { setMsg('Enter your entry price.', 'err'); return; }
        $('track').disabled = true;
        try {
            await api(URLS.store, { method: 'POST', body: JSON.stringify({
                symbol: selected.symbol, entry_price: entry, source: $('feed').value, resolution: $('tf').value,
            }) });
            setMsg(`Tracking ${selected.side} ${fmt(selected.strike)}. You will be alerted when its Supertrend flips RED → GREEN.`, 'ok');
            selected = null;
            $('panel').style.display = 'block';
            await loadPositions();
            renderChain();
        } catch (e) {
            setMsg(e.message, 'err');
        } finally {
            $('track').disabled = false;
        }
    };

    /* ---------- my trades ---------- */
    let active = [];

    function renderPositions(d) {
        active = d.active;
        $('trades-body').innerHTML = d.active.length ? d.active.map(p => {
            const pnl = p.last_price != null ? p.entry_price - p.last_price : null;
            const warn = p.last_dir === 'green'
                ? ' <span title="Already GREEN: an SL alert needs it to turn RED first, then GREEN again" class="neg">⚠</span>' : '';
            return `<tr>
                <td>${esc(p.side)} ${fmt(p.strike)} <small>${esc(p.expiry)}</small></td>
                <td>${esc(p.source.toUpperCase())} ${esc(p.resolution)}</td>
                <td>${fmt(p.entry_price)}</td>
                <td>${fmt(p.last_price)}</td>
                <td>${p.last_dir ? `<span class="badge ${p.last_dir}">${p.last_dir.toUpperCase()}</span>${warn}` : '<span class="muted">checking…</span>'}</td>
                <td>${fmt(p.last_line)}</td>
                <td class="${pnl == null ? '' : pnl >= 0 ? 'pos' : 'neg'}">${pnl == null ? '-' : (pnl >= 0 ? '+' : '') + fmt(pnl)}</td>
                <td><button class="ghost stop" data-id="${p.id}">Stop</button></td>
            </tr>`;
        }).join('') : '<tr><td colspan="8" class="muted">No trades tracked. Select a contract from the option chain below.</td></tr>';

        $('history').innerHTML = d.recent.length ? '<b>Recent:</b> ' + d.recent.map(p => {
            const pnl = p.exit_price != null ? p.entry_price - p.exit_price : null;
            const label = { sl_hit: 'SL hit', closed: 'stopped', expired: 'expired' }[p.status] || p.status;
            return `<span style="margin-right:1rem">${esc(p.side)} ${fmt(p.strike)} <span class="badge ${esc(p.status)}">${label}</span>` +
                (pnl != null ? ` <span class="${pnl >= 0 ? 'pos' : 'neg'}">${pnl >= 0 ? '+' : ''}${fmt(pnl)}</span>` : '') + '</span>';
        }).join('') : '';
    }

    $('trades-body').addEventListener('click', async e => {
        const b = e.target.closest('.stop');
        if (!b) return;
        if (!confirm('Stop tracking this trade? You will no longer get its SL alert.')) return;
        try { await api(`${URLS.destroy}/${b.dataset.id}`, { method: 'DELETE' }); await loadPositions(); renderChain(); }
        catch (err) { setMsg(err.message, 'err'); }
    });

    async function loadPositions() {
        try { renderPositions(await api(URLS.positions)); } catch (e) { /* keep last view */ }
    }

    /* ---------- option chain ---------- */
    let chain = null, chainExpiry = null, chainBusy = false;

    // one table cell of one option (kind: oi | delta | bid | mark | ask); whole half-row is clickable
    function optCell(o, kind, side, strike, itm) {
        const live = o && o.symbol;
        const cls = ['opt', kind, itm ? 'itm' : '',
            live && selected && selected.symbol === o.symbol ? 'sel' : '',
            live && active.some(p => p.symbol === o.symbol) ? 'trk' : '',
            kind === 'oi' || kind === 'delta' ? 'dim' : ''].filter(Boolean).join(' ');
        const attrs = live ? ` data-symbol="${esc(o.symbol)}" data-side="${side}" data-strike="${strike}" data-price="${isNum(o.bid) ? o.bid : (isNum(o.mark) ? o.mark : '')}"` : '';
        const v = !live ? '-' : kind === 'oi' ? money(o.oi) : kind === 'delta' ? f2(o.delta) : f1(o[kind]);
        return `<td class="${cls}"${attrs}>${v}</td>`;
    }

    function renderChain() {
        if (!chain) return;
        $('expiry').innerHTML = chain.expiries.map(x => `<option value="${x}" ${x === chain.expiry ? 'selected' : ''}>${x}</option>`).join('');
        $('spotpill').textContent = `BTC ${fmt(chain.spot)}`;

        const rows = chain.rows;
        let html = '';
        rows.forEach((r, i) => {
            const ceItm = r.strike < chain.spot, peItm = r.strike > chain.spot;
            html += `<tr class="${r.strike === chain.atm ? 'atm' : ''}">` +
                ['oi', 'delta', 'bid', 'mark', 'ask'].map(k => optCell(r.ce, k, 'CE', r.strike, ceItm)).join('') +
                `<td class="strike">${fmt(r.strike)}</td>` +
                ['bid', 'mark', 'ask', 'delta', 'oi'].map(k => optCell(r.pe, k, 'PE', r.strike, peItm)).join('') +
                '</tr>';
            const next = rows[i + 1];
            if (next && r.strike <= chain.spot && chain.spot < next.strike) {
                html += `<tr class="spotrow"><td colspan="11"><div><span>BTC ${fmt(chain.spot)}</span></div></td></tr>`;
            }
        });
        $('chain-body').innerHTML = html || '<tr><td colspan="11" class="muted" style="text-align:center">No option contracts listed for this expiry.</td></tr>';
    }

    $('chain-body').addEventListener('click', e => {
        const td = e.target.closest('td.opt[data-symbol]');
        if (!td) return;
        selectContract({
            symbol: td.dataset.symbol, side: td.dataset.side, strike: Number(td.dataset.strike),
            price: td.dataset.price === '' ? null : Number(td.dataset.price),
        });
    });

    $('expiry').onchange = () => { chainExpiry = $('expiry').value; loadChain(); };
    $('win').onchange = () => loadChain();

    async function loadChain() {
        if (chainBusy) return;
        chainBusy = true;
        try {
            const q = new URLSearchParams({ window: $('win').value });
            if (chainExpiry) q.set('expiry', chainExpiry);
            chain = await api(URLS.chain + '?' + q.toString());
            chainExpiry = chain.expiry;
            renderChain();
            $('chain-status').textContent = 'updated ' + new Date(chain.fetched_at * 1000).toLocaleTimeString('en-GB');
        } catch (e) {
            $('chain-status').textContent = e.message;
        } finally {
            chainBusy = false;
        }
    }

    /* ---------- alerts ---------- */
    let lastId = INITIAL.reduce((m, a) => Math.max(m, a.id), 0);

    function alertHtml(a, justNow) {
        const sent = justNow ? 'just now' : istTime(a.created_at);
        const trade = a.type === 'cycle_red_green_red'
            ? `<br><button class="trade ghost" data-id="${a.id}">Trade this → set SL</button>` : '';
        return `${esc(a.message)}<br><small>${esc(a.candle_label)} · alert sent ${esc(sent)}</small>${trade}`;
    }

    const alertsById = {};
    function addAlert(a, justNow) {
        alertsById[a.id] = a;
        const li = document.createElement('li');
        li.className = a.type;
        li.innerHTML = alertHtml(a, justNow);
        return li;
    }

    INITIAL.forEach(a => $('list').appendChild(addAlert(a, false)));

    $('list').addEventListener('click', e => {
        const b = e.target.closest('.trade');
        if (!b) return;
        const a = alertsById[b.dataset.id];
        if (!a) return;
        selectContract({
            symbol: a.symbol, side: a.side, strike: Number(a.strike), price: Number(a.price),
            source: a.source, resolution: a.resolution,
        });
    });

    async function poll() {
        try {
            const items = await api(URLS.feed + '?after=' + lastId);
            for (const a of items) {
                lastId = Math.max(lastId, a.id);
                $('list').prepend(addAlert(a, true));
                (sounds[a.type] || sounds.flip_green)();
                if ('Notification' in window && Notification.permission === 'granted') new Notification('BTC Supertrend', { body: a.message });
                if (a.type === 'sl_hit') loadPositions();
            }
            $('status').textContent = 'checked ' + new Date().toLocaleTimeString('en-GB');
        } catch (e) {
            $('status').textContent = 'connection issue, retrying…';
        }
    }

    if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    loadPositions(); loadChain();
    setInterval(poll, 10000);
    setInterval(loadPositions, 10000);
    setInterval(loadChain, 5000);
</script>
</body>
</html>