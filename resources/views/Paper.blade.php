<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>BTC Paper Trades</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 1100px; margin: 1.5rem auto; padding: 0 1rem; background: #0f1115; color: #e8e8e8; }
        h2 { margin: 0 0 .4rem; } h3 { margin: 0 0 .6rem; font-size: 1.05rem; }
        a { color: #58a6ff; }
        section { background: #151821; border: 1px solid #232733; border-radius: 8px; padding: .9rem 1rem; margin: 1rem 0; }
        button { padding: .45rem .85rem; border: 0; border-radius: 6px; cursor: pointer; font-size: .9rem; color: #fff; background: #2f6feb; }
        button.ghost { background: #2a2f3a; } button.danger { background: #8e2a2a; }
        #enable.on { background: #238636; }
        small, .muted { color: #9aa0a6; }
        input { background: #0f1115; color: #e8e8e8; border: 1px solid #333a48; border-radius: 6px; padding: .35rem .5rem; width: 6rem; }
        .bar { display: flex; flex-wrap: wrap; gap: .6rem 1.2rem; align-items: center; margin: .5rem 0 0; }
        .bar label { color: #9aa0a6; font-size: .85rem; margin-right: .3rem; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .7rem; }
        .card { background: #1a1d24; border: 1px solid #232733; border-radius: 8px; padding: .7rem .8rem; }
        .card .k { color: #9aa0a6; font-size: .78rem; } .card .v { font-size: 1.35rem; font-weight: 700; margin-top: .15rem; font-variant-numeric: tabular-nums; }
        .card .s { color: #9aa0a6; font-size: .78rem; margin-top: .1rem; }
        .scroll { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; font-size: .9rem; font-variant-numeric: tabular-nums; }
        th, td { padding: .4rem .55rem; text-align: right; border-bottom: 1px solid #232733; white-space: nowrap; }
        th { color: #9aa0a6; font-weight: 500; font-size: .8rem; }
        th:first-child, td:first-child { text-align: left; }
        .pos { color: #3fb950; } .neg { color: #f85149; }
        .badge { display: inline-block; padding: .05rem .45rem; border-radius: 999px; font-size: .78rem; background: #262a33; color: #9aa0a6; }
        .badge.ce { background: #13361d; color: #3fb950; } .badge.pe { background: #3d1517; color: #f85149; }
        #curve { width: 100%; height: 150px; display: block; }
        .rule { color: #9aa0a6; font-size: .85rem; margin: .2rem 0 0; }
    </style>
</head>
<body>
    <h2>BTC paper trades <small>(simulated, no real orders) · <a href="{{ route('alerts.index') }}">alerts</a></small></h2>
    <p class="rule">Rule: GREEN→RED alert → if the <b>next candle closes below the flip candle's low</b>, SELL at that close · stop = Supertrend line, trailing · exit when a candle closes with Supertrend GREEN.</p>
    <div class="bar">
        <button id="enable">🔇 Click to enable sound</button>
        <span><label for="qty">Contracts</label><input id="qty" type="number" min="1" step="1" value="1"></span>
        <span><label for="csz">BTC per contract</label><input id="csz" type="number" min="0" step="any" value="0.001"></span>
        <small id="status"></small>
        <button id="reset" class="danger" style="margin-left:auto">Clear all paper trades</button>
    </div>

    <section>
        <div class="cards" id="cards"></div>
    </section>

    <section>
        <h3>Equity curve <small>(cumulative P/L in points)</small></h3>
        <svg id="curve" viewBox="0 0 1000 150" preserveAspectRatio="none"></svg>
    </section>

    <section>
        <h3>Open trades</h3>
        <div class="scroll"><table>
            <thead><tr><th>Contract</th><th>Feed</th><th>Entered (IST)</th><th>Entry</th><th>Now</th><th title="Trailing Supertrend line. A candle closing above it stops you out.">SL (Supertrend)</th><th>Risk</th><th>Open P/L</th><th>R</th><th>Best / worst</th></tr></thead>
            <tbody id="open"></tbody>
        </table></div>
        <div id="pending" class="muted" style="margin-top:.6rem"></div>
    </section>

    <section>
        <h3>Closed trades</h3>
        <div class="scroll"><table>
            <thead><tr><th>Contract</th><th>Feed</th><th>Entered (IST)</th><th>Entry</th><th>Exited (IST)</th><th>Exit</th><th>Why</th><th>P/L pts</th><th>R</th><th>USD</th></tr></thead>
            <tbody id="closed"></tbody>
        </table></div>
    </section>

    <section>
        <h3>By day</h3>
        <div class="scroll"><table>
            <thead><tr><th>Day</th><th>Trades</th><th>Win %</th><th>Total pts</th><th>Avg win / loss</th><th>Profit factor</th></tr></thead>
            <tbody id="days"></tbody>
        </table></div>
    </section>

<script>
    const URL_DATA = @json(route('paper.data'));
    const URL_RESET = @json(route('paper.reset'));
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const $ = id => document.getElementById(id);
    const isNum = n => !(n === null || n === undefined || n === '' || isNaN(n));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const f1 = n => isNum(n) ? Number(n).toLocaleString('en-IN', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '-';
    const f2 = n => isNum(n) ? Number(n).toFixed(2) : '-';
    const sgn = n => isNum(n) ? (n > 0 ? '+' : '') + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '-';
    const cls = n => isNum(n) ? (n > 0 ? 'pos' : n < 0 ? 'neg' : '') : '';
    const ist = ts => ts ? new Date(ts * 1000).toLocaleString('en-GB', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }) : '-';

    // remembered per browser: how many contracts you would trade, for the USD column
    try { $('qty').value = localStorage.getItem('pt_qty') || 1; $('csz').value = localStorage.getItem('pt_csz') || 0.001; } catch (e) { /* storage blocked */ }
    const usd = pts => isNum(pts) ? pts * Number($('qty').value || 0) * Number($('csz').value || 0) : null;
    const usdTxt = pts => isNum(pts) ? (usd(pts) >= 0 ? '+' : '-') + '$' + Math.abs(usd(pts)).toFixed(2) : '-';
    ['qty', 'csz'].forEach(id => $(id).addEventListener('input', () => {
        try { localStorage.setItem('pt_qty', $('qty').value); localStorage.setItem('pt_csz', $('csz').value); } catch (e) { /* ignore */ }
        if (last) render(last);
    }));

    /* ---------- sound ---------- */
    let audioCtx = null;
    $('enable').onclick = () => {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        $('enable').textContent = '🔊 Sound on'; $('enable').classList.add('on');
        beep([880], .15);
    };
    function beep(freqs, dur) {
        if (!audioCtx) return;
        freqs.forEach((f, i) => {
            const o = audioCtx.createOscillator(), g = audioCtx.createGain(), t = audioCtx.currentTime + i * (dur + .08);
            o.frequency.value = f; o.connect(g); g.connect(audioCtx.destination);
            g.gain.setValueAtTime(.25, t); g.gain.exponentialRampToValueAtTime(.001, t + dur);
            o.start(t); o.stop(t + dur);
        });
    }

    /* ---------- rendering ---------- */
    const badge = r => `<span class="badge ${r.side === 'CE' ? 'ce' : 'pe'}">${esc(r.label)}</span>`;
    const feed = r => `${esc(r.source)} · ${esc(r.resolution)}`;
    const card = (k, v, s, c = '') => `<div class="card"><div class="k">${k}</div><div class="v ${c}">${v}</div><div class="s">${s || '&nbsp;'}</div></div>`;

    function renderCards(d) {
        const s = d.stats, t = d.today;
        if (!s.n) {
            $('cards').innerHTML = card('Closed trades', '0', 'waiting for the first completed trade') + card('Open now', d.open.length, '') + card('Waiting for next candle', d.pending.length, '') + card('Skipped', d.skipped, 'next candle did not break the flip low');
            return;
        }
        $('cards').innerHTML = [
            card('Total P/L', sgn(s.total) + ' pts', usdTxt(s.total), cls(s.total)),
            card('Today P/L', sgn(t.total) + ' pts', t.n ? t.n + ' trades · ' + usdTxt(t.total) : 'no closed trades', cls(t.total)),
            card('Win rate', s.win_rate + '%', `${s.wins} won · ${s.losses} lost · ${s.n} total`),
            card('Reward : risk', s.rr === null ? '-' : s.rr + ' : 1', `avg win ${f1(s.avg_win)} / avg loss ${f1(s.avg_loss)}`),
            card('Break-even win rate', s.breakeven === null ? '-' : s.breakeven + '%', s.breakeven !== null && s.win_rate > s.breakeven ? 'you are above it' : 'you are below it', s.breakeven !== null ? (s.win_rate > s.breakeven ? 'pos' : 'neg') : ''),
            card('Profit factor', s.pf === null ? '-' : s.pf, 'gross profit / gross loss'),
            card('Avg R per trade', sgn(s.avg_r) + ' R', `avg ${sgn(s.avg)} pts`, cls(s.avg_r)),
            card('Max drawdown', f1(s.max_dd) + ' pts', `longest losing streak ${s.streak}`),
            card('Best / worst', `${sgn(s.best)} / ${sgn(s.worst)}`, 'points'),
            card('Open · waiting · skipped', `${d.open.length} · ${d.pending.length} · ${d.skipped}`, ''),
        ].join('');
    }

    function renderCurve(curve) {
        const svg = $('curve');
        if (curve.length < 2) { svg.innerHTML = '<text x="500" y="80" fill="#9aa0a6" text-anchor="middle" font-size="14">needs at least 2 closed trades</text>'; return; }
        const vals = [0, ...curve.map(p => p.v)];
        const lo = Math.min(...vals), hi = Math.max(...vals), span = (hi - lo) || 1;
        const x = i => 10 + i * (980 / (vals.length - 1)), y = v => 140 - ((v - lo) / span) * 130;
        const pts = vals.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
        const col = vals[vals.length - 1] >= 0 ? '#3fb950' : '#f85149';
        svg.innerHTML = `<line x1="0" x2="1000" y1="${y(0)}" y2="${y(0)}" stroke="#333a48" stroke-dasharray="4 4"/>
            <polyline points="${pts}" fill="none" stroke="${col}" stroke-width="2" vector-effect="non-scaling-stroke"/>
            <text x="12" y="14" fill="#9aa0a6" font-size="12">${f1(hi)}</text><text x="12" y="148" fill="#9aa0a6" font-size="12">${f1(lo)}</text>`;
    }

    function render(d) {
        renderCards(d);
        renderCurve(d.curve);

        $('open').innerHTML = d.open.length ? d.open.map(r => `<tr>
            <td>${badge(r)}</td><td>${feed(r)}</td><td>${ist(r.entry_at)}</td><td>${f1(r.entry)}</td><td>${f1(r.last)}</td>
            <td>${f1(r.sl)}</td><td>${f1(r.risk)}</td><td class="${cls(r.pnl)}">${sgn(r.pnl)} <small>(${usdTxt(r.pnl)})</small></td>
            <td class="${cls(r.r)}">${isNum(r.r) ? sgn(r.r) : '-'}</td><td><span class="pos">${f1(r.best)}</span> / <span class="neg">${f1(r.worst)}</span></td></tr>`).join('')
            : '<tr><td colspan="10" class="muted" style="text-align:left">No open trade.</td></tr>';

        $('pending').innerHTML = d.pending.length
            ? 'Waiting for the next candle to close: ' + d.pending.map(r => `${badge(r)} <small>flip candle ${ist(r.flip_time)}${isNum(r.flip_low) ? ' · low ' + f1(r.flip_low) : ''}</small>`).join(' &nbsp; ')
            : '';

        $('closed').innerHTML = d.closed.length ? d.closed.map(r => `<tr>
            <td>${badge(r)}</td><td>${feed(r)}</td><td>${ist(r.entry_at)}</td><td>${f1(r.entry)}</td><td>${ist(r.exit_at)}</td><td>${f1(r.exit)}</td>
            <td>${esc(r.reason)}</td><td class="${cls(r.pnl)}">${sgn(r.pnl)}</td><td class="${cls(r.r)}">${isNum(r.r) ? sgn(r.r) : '-'}</td><td class="${cls(r.pnl)}">${usdTxt(r.pnl)}</td></tr>`).join('')
            : '<tr><td colspan="10" class="muted" style="text-align:left">No closed trades yet.</td></tr>';

        $('days').innerHTML = d.by_day.length ? d.by_day.map(r => `<tr>
            <td>${esc(r.day)}</td><td>${r.n}</td><td>${r.win_rate}%</td><td class="${cls(r.total)}">${sgn(r.total)}</td>
            <td>${f1(r.avg_win)} / ${f1(r.avg_loss)}</td><td>${r.pf === null ? '-' : r.pf}</td></tr>`).join('')
            : '<tr><td colspan="6" class="muted" style="text-align:left">-</td></tr>';
    }

    /* ---------- refresh ---------- */
    let last = null, seenOpen = null, seenClosed = null;
    async function load() {
        try {
            const r = await fetch(URL_DATA, { headers: { Accept: 'application/json' } });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const d = await r.json();

            const openIds = new Set(d.open.map(x => x.id)), closedIds = new Set(d.closed.map(x => x.id));
            if (seenOpen !== null) { // not on the first load
                if ([...openIds].some(id => !seenOpen.has(id))) beep([660, 880], .18);          // new entry
                if ([...closedIds].some(id => !seenClosed.has(id))) beep([440, 330, 440, 330], .2); // trade closed
            }
            seenOpen = openIds; seenClosed = closedIds;

            last = d; render(d);
            $('status').textContent = 'updated ' + new Date().toLocaleTimeString('en-GB');
        } catch (e) {
            $('status').textContent = 'connection issue, retrying…';
        }
    }

    $('reset').onclick = async () => {
        if (!confirm('Delete ALL paper trades (open, closed and skipped)? This cannot be undone.')) return;
        await fetch(URL_RESET, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF } });
        seenOpen = seenClosed = null; load();
    };

    load();
    setInterval(load, 5000);
</script>
</body>
</html>