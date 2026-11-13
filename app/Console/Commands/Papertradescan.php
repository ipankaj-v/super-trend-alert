<?php

namespace App\Console\Commands;

use App\Models\PaperTrade;
use App\Models\SupertrendAlert;
use App\Services\DeltaClient;
use App\Services\PaperRules;
use App\Services\Supertrend;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PaperTradeScan extends Command
{
    protected $signature = 'supertrend:paper {--source=mark : mark or traded alerts to trade} {--wait=1 : candles allowed to close below the flip candle low} {--period=10} {--factor=3}';
    protected $description = 'Paper trading: after a GREEN->RED alert, sell if the next candle closes below the flip candle low; trail the stop with Supertrend; store in DB';

    private const SETTLE = 3;

    private int $now = 0;

    public function handle(DeltaClient $delta): int
    {
        $source = $this->option('source') === 'traded' ? 'traded' : 'mark';
        $wait = max(1, (int) $this->option('wait'));
        $period = (int) $this->option('period');
        $factor = (float) $this->option('factor');

        try {
            $delta->syncClock();
        } catch (\Throwable $e) {
            $this->warn('clock sync failed: ' . $e->getMessage());
        }

        // same timing as the scanner: let the candle that just closed settle first
        $into = DeltaClient::serverNow() % 60;
        if ($into < self::SETTLE) {
            sleep(self::SETTLE - $into);
        }
        $this->now = DeltaClient::serverNow();

        $this->intake($source, $wait);

        // open trades first: a stop must never wait behind new signals
        foreach (PaperTrade::where('status', 'open')->get() as $t) {
            $this->guard($t, fn () => $this->manage($delta, $t, $period, $factor));
        }
        foreach (PaperTrade::where('status', 'pending')->get() as $t) {
            $this->guard($t, fn () => $this->evaluate($delta, $t, $period, $factor, $wait));
        }

        return self::SUCCESS;
    }

    private function guard(PaperTrade $t, \Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->warn("{$t->symbol}: {$e->getMessage()}");
        }
    }

    /** New GREEN->RED alerts become pending paper trades. Old alerts (before this was switched on) are ignored. */
    private function intake(string $source, int $wait): void
    {
        $known = PaperTrade::pluck('alert_id')->all();

        $alerts = SupertrendAlert::where('type', 'cycle_red_green_red')
            ->where('source', $source)
            ->where('created_at', '>=', now()->subHours(3))
            ->when($known, fn ($q) => $q->whereNotIn('id', $known))
            ->get();

        foreach ($alerts as $a) {
            $secs = DeltaClient::seconds($a->resolution ?: '15m');
            if ((int) $a->candle_time + $secs * ($wait + 3) < $this->now) {
                continue; // too old to confirm
            }

            PaperTrade::create([
                'alert_id' => $a->id, 'symbol' => $a->symbol, 'side' => $a->side, 'strike' => $a->strike,
                'expiry' => $this->expiryOf($a->symbol), 'source' => $source, 'resolution' => $a->resolution ?: '15m',
                'flip_time' => (int) $a->candle_time, 'status' => 'pending',
            ]);
        }
    }

    private function feed(PaperTrade $t): string
    {
        return ($t->source === 'mark' ? 'MARK:' : '') . $t->symbol;
    }

    /** Closed candles only (the still-forming one is dropped) and their Supertrend. */
    private function closed(DeltaClient $delta, PaperTrade $t, int $count, int $period, float $factor): array
    {
        $secs = DeltaClient::seconds($t->resolution);
        $c = $delta->candles($this->feed($t), $t->resolution, min(1900, $count));
        if ($c && end($c)['time'] + $secs > $this->now - self::SETTLE) {
            array_pop($c);
        }

        return [$c, Supertrend::compute($c, $period, $factor), $secs];
    }

    private function evaluate(DeltaClient $delta, PaperTrade $t, int $period, float $factor, int $wait): void
    {
        $secs = DeltaClient::seconds($t->resolution);
        [$c, $st] = $this->closed($delta, $t, 150, $period, $factor);

        $r = $st ? PaperRules::entry($c, $st, $period, $t->flip_time, $secs, $wait) : ['action' => 'missing'];

        if ($r['action'] === 'missing') {
            if ($this->now > $t->flip_time + $secs * ($wait + 4)) {
                $t->update(['status' => 'skipped', 'skip_reason' => 'no candle data']);
            }

            return;
        }

        if ($r['action'] === 'wait') {
            $t->update(['flip_low' => $r['low']]);

            return;
        }

        if ($r['action'] === 'skip' || $r['risk'] <= 0) {
            $reason = $r['reason'] ?? 'stop above entry';
            $t->update(['status' => 'skipped', 'flip_low' => $r['low'] ?? null, 'skip_reason' => $reason]);
            $this->line("PAPER SKIP {$this->label($t)} | {$reason}");

            return;
        }

        $t->update([
            'status' => 'open', 'flip_low' => $r['low'],
            'entry_price' => $r['entry'], 'entry_candle_time' => $r['candle_time'], 'entry_at' => $r['entry_at'],
            'sl_initial' => $r['sl'], 'risk' => $r['risk'], 'sl_current' => $r['sl'],
            'last_price' => $r['entry'], 'last_dir' => 'red', 'low_seen' => $r['entry'], 'high_seen' => $r['entry'],
            'last_checked_at' => now(),
        ]);

        $this->line(sprintf(
            'PAPER ENTRY [%s][%s] SELL %s @ %s | closed below flip low %s | SL (Supertrend) %s | risk %s pts | %s IST',
            $t->resolution, strtoupper($t->source), $this->label($t), round($r['entry'], 1), round($r['low'], 1),
            round($r['sl'], 1), round($r['risk'], 1), Carbon::createFromTimestamp($r['entry_at'], 'Asia/Kolkata')->format('d M H:i')
        ));
    }

    private function manage(DeltaClient $delta, PaperTrade $t, int $period, float $factor): void
    {
        $secs = DeltaClient::seconds($t->resolution);

        // Daily options settle 17:30 IST on the expiry date.
        $expiryAt = Carbon::createFromFormat('d-m-Y H:i', $t->expiry . ' 17:30', 'Asia/Kolkata');
        if (Carbon::now('Asia/Kolkata')->greaterThan($expiryAt)) {
            $this->close($t, $t->last_price ?? $t->entry_price, $expiryAt->timestamp, 'expiry');

            return;
        }

        $count = intdiv(max(0, $this->now - $t->entry_candle_time), $secs) + 60;
        [$c, $st] = $this->closed($delta, $t, $count, $period, $factor);
        if (! $st) {
            return;
        }

        $m = PaperRules::manage($c, $st, $period, $t->entry_candle_time, $secs);
        if (! $m['found']) {
            $this->warn("{$t->symbol}: entry candle not in the downloaded window, skipping this check.");

            return;
        }

        $update = ['last_checked_at' => now()];
        if ($m['last']) {
            $update += ['last_price' => $m['last']['price'], 'sl_current' => $m['last']['line'], 'last_dir' => $m['last']['dir']];
        }
        if (($m['low'] ?? null) !== null) {
            $update['low_seen'] = min($t->low_seen ?? $m['low'], $m['low']);
        }
        if (($m['high'] ?? null) !== null) {
            $update['high_seen'] = max($t->high_seen ?? $m['high'], $m['high']);
        }
        $t->fill($update);

        if ($m['exit']) {
            $this->close($t, $m['price'], $m['exit_at'], 'SL flip');

            return;
        }

        $t->save();
    }

    private function close(PaperTrade $t, float $exit, int $exitAt, string $reason): void
    {
        $pnl = $t->entry_price - $exit;
        $t->fill([
            'status' => 'closed', 'exit_price' => $exit, 'exit_at' => $exitAt, 'exit_reason' => $reason,
            'pnl' => $pnl, 'r_multiple' => $t->risk > 0 ? $pnl / $t->risk : null,
            'last_price' => $exit, 'last_checked_at' => now(),
        ])->save();

        $this->line(sprintf(
            'PAPER EXIT [%s][%s] %s | %s @ %s | entry %s | P/L %+.1f pts (%+.2f R) | %s IST',
            $t->resolution, strtoupper($t->source), $this->label($t), $reason, round($exit, 1), round($t->entry_price, 1), $pnl,
            $t->r_multiple ?? 0, Carbon::createFromTimestamp($exitAt, 'Asia/Kolkata')->format('d M H:i')
        ));
    }

    private function label(PaperTrade $t): string
    {
        return "BTC {$t->side} " . (int) $t->strike;
    }

    /** C-BTC-73400-121126 -> 12-11-2026 */
    private function expiryOf(string $symbol): string
    {
        $part = explode('-', $symbol)[3] ?? '';

        return strlen($part) === 6
            ? substr($part, 0, 2) . '-' . substr($part, 2, 2) . '-20' . substr($part, 4, 2)
            : Carbon::now('Asia/Kolkata')->format('d-m-Y');
    }
}