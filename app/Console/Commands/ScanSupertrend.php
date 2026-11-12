<?php

namespace App\Console\Commands;

use App\Models\SupertrendAlert;
use App\Models\TrackedPosition;
use App\Services\AtmResolver;
use App\Services\DeltaClient;
use App\Services\Supertrend;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ScanSupertrend extends Command
{
    protected $signature = 'supertrend:scan {--resolution=15m : 1m, 3m, 5m, 10m, 15m, 30m, 1h} {--period=10} {--factor=3} {--source=both : mark, traded or both}';
    protected $description = 'Alert when BTC CE/PE Supertrend flips GREEN to RED on a closed candle (mark and/or traded price)';

    private int $candleSecs = 900;
    private string $res = '15m';

    public function handle(AtmResolver $atm, DeltaClient $delta): int
    {
        $res = $this->option('resolution');
        $period = (int) $this->option('period');
        $factor = (float) $this->option('factor');

        if (! isset(DeltaClient::NATIVE[$res]) && ! isset(DeltaClient::DERIVED[$res])) {
            $this->error("Unsupported resolution '{$res}'.");

            return self::FAILURE;
        }

        $this->candleSecs = DeltaClient::seconds($res);
        $this->res = $res;

        $sources = match ($this->option('source')) {
            'mark'   => ['mark' => 'MARK:'],
            'traded' => ['traded' => ''],
            default  => ['mark' => 'MARK:', 'traded' => ''],
        };

        // Tracked trades first: an SL must never be blocked by a problem picking the ATM strike.
        $this->checkPositions($delta, $period, $factor);

        $pick = $atm->resolve();
        $this->info("[{$res}] Expiry {$pick['expiry']} | spot {$pick['spot']} | ATM {$pick['atm']} | mode {$pick['mode']} | CE {$pick['ce_strike']} | PE {$pick['pe_strike']} | alert on " . config('supertrend.alert_on', 'red'));

        $targets = [
            'CE' => [$pick['call'], $pick['ce_strike']],
            'PE' => [$pick['put'], $pick['pe_strike']],
        ];

        foreach ($targets as $side => [$symbol, $strike]) {
            foreach ($sources as $source => $prefix) {
                $this->scanOne($delta, $prefix . $symbol, $symbol, $side, $source, $strike, $res, $period, $factor);
            }
        }

        return self::SUCCESS;
    }

    /** Trades you are tracking from the dashboard: SL = Supertrend flips RED -> GREEN. */
    private function checkPositions(DeltaClient $delta, int $period, float $factor): void
    {
        foreach (TrackedPosition::where('status', 'active')->get() as $p) {
            try {
                $this->checkPosition($delta, $p, $period, $factor);
            } catch (\Throwable $e) {
                $this->warn("{$p->symbol}: {$e->getMessage()}");
            }
        }
    }

    private function checkPosition(DeltaClient $delta, TrackedPosition $p, int $period, float $factor): void
    {
        // Daily options settle 17:30 IST on the expiry date.
        $expiryAt = Carbon::createFromFormat('d-m-Y H:i', $p->expiry . ' 17:30', 'Asia/Kolkata');
        if (Carbon::now('Asia/Kolkata')->greaterThan($expiryAt)) {
            $p->update(['status' => 'expired']);

            return;
        }

        $secs = DeltaClient::seconds($p->resolution);
        $feed = ($p->source === 'mark' ? 'MARK:' : '') . $p->symbol;

        $candles = $delta->candles($feed, $p->resolution, 150);
        if ($candles && end($candles)['time'] + $secs > time()) {
            array_pop($candles); // still-forming candle
        }

        $st = Supertrend::compute($candles, $period, $factor);
        if (count($st) < 2) {
            $this->warn("{$feed}: not enough closed candles, skipping.");

            return;
        }

        $last = end($st);
        $p->fill([
            'last_dir' => $last['dir'],
            'last_price' => $last['close'],
            'last_line' => $last['line'],
            'last_checked_at' => now(),
        ]);

        // First RED -> GREEN flip whose candle CLOSED after you entered the trade.
        for ($i = 1; $i < count($st); $i++) {
            $flippedToGreen = $st[$i]['dir'] === 'green' && $st[$i - 1]['dir'] === 'red';
            if (! $flippedToGreen || $st[$i]['time'] + $secs <= $p->entry_at) {
                continue;
            }

            $exit = $st[$i]['close'];
            $entry = (float) $p->entry_price;
            $strike = (float) $p->strike;
            $message = sprintf(
                '[%s][%s] SL HIT BTC %s %s Supertrend flipped RED→GREEN @ %s | entry %s | P/L (short) %+.1f pts',
                $p->resolution, strtoupper($p->source), $p->side, $strike, round($exit, 1), round($entry, 1), $entry - $exit
            );

            $alert = SupertrendAlert::firstOrCreate(
                [
                    'symbol' => $p->symbol, 'candle_time' => $st[$i]['time'], 'type' => 'sl_hit',
                    'source' => $p->source, 'resolution' => $p->resolution,
                ],
                ['side' => $p->side, 'strike' => $strike, 'price' => $exit, 'line' => $st[$i]['line'], 'message' => $message]
            );

            $p->fill(['status' => 'sl_hit', 'exit_price' => $exit, 'exit_at' => $st[$i]['time'] + $secs]);
            $this->line("ALERT: {$alert->message} | {$alert->candle_label}");
            break;
        }

        $p->save();
    }

    private function scanOne(DeltaClient $delta, string $feed, string $symbol, string $side, string $source, float $strike, string $res, int $period, float $factor): void
    {
        $candles = $delta->candles($feed, $res, 150);

        // Drop the still-forming candle: only act on CLOSED candles.
        if ($candles && end($candles)['time'] + $this->candleSecs > time()) {
            array_pop($candles);
        }

        $st = Supertrend::compute($candles, $period, $factor);
        if (count($st) < 3) {
            $this->warn("{$feed}: not enough closed candles (" . count($candles) . '), skipping.');

            return;
        }

        $flips = [];
        for ($i = 1; $i < count($st); $i++) {
            if ($st[$i]['dir'] !== $st[$i - 1]['dir']) {
                $flips[] = $st[$i] + ['to' => $st[$i]['dir']];
            }
        }

        // red = only GREEN->RED flips (default) | green = only RED->GREEN | both
        $alertOn = config('supertrend.alert_on', 'red');

        // Only the two most recent flips can be fresh.
        for ($k = max(0, count($flips) - 2); $k < count($flips); $k++) {
            $flip = $flips[$k];
            $prev = $flips[$k - 1] ?? null;

            if ($flip['to'] === 'red') {
                if (in_array($alertOn, ['red', 'both'], true)) {
                    // entry = close of the GREEN flip that this RED flip is reversing
                    $entry = ($prev !== null && $prev['to'] === 'green') ? $prev['close'] : null;

                    // type name kept for the dashboard colour/sound: GREEN->RED flip
                    $this->raise($symbol, $side, $source, $strike, 'cycle_red_green_red', $flip, $entry);
                }
            } elseif (in_array($alertOn, ['green', 'both'], true)) {
                $this->raise($symbol, $side, $source, $strike, 'flip_green', $flip);
            }
        }
    }

    private function raise(string $symbol, string $side, string $source, float $strike, string $type, array $c, ?float $entry = null): void
    {
        // $c['time'] is the candle OPEN time; the flip is only confirmed when that candle CLOSES.
        $closeTime = $c['time'] + $this->candleSecs;

        // Alert only on a just-closed candle (small grace for scheduler delay). Older flips,
        // e.g. on a strike we just switched to, are history and must not alert.
        if (time() - $closeTime > (int) config('supertrend.grace_seconds', 180)) {
            return;
        }

        $tag = strtoupper($source);
        $price = round($c['close'], 1);
        $head = "[{$this->res}][{$tag}] BTC {$side} {$strike}";

        $message = match ($type) {
            'flip_green' => "{$head} Supertrend turned GREEN @ {$price} | SL " . round($c['line'], 1) . ' (Supertrend line)',
            default => "{$head} Supertrend flipped GREEN→RED @ {$price}"
                . ($entry !== null
                    ? ' | green-flip entry ' . round($entry, 1) . ' | P/L ' . sprintf('%+.1f', $c['close'] - $entry) . ' pts'
                    : ''),
        };

        $alert = SupertrendAlert::firstOrCreate(
            [
                'symbol' => $symbol, 'candle_time' => $c['time'], 'type' => $type,
                'source' => $source, 'resolution' => $this->res,
            ],
            [
                'side' => $side,
                'strike' => $strike,
                'price' => $c['close'],
                'line' => $c['line'],
                'message' => $message,
            ]
        );

        if ($alert->wasRecentlyCreated) {
            $this->line("ALERT: {$alert->message} | {$alert->candle_label}");
        }
    }
}