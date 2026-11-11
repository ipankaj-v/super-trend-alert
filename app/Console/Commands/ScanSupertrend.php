<?php

namespace App\Console\Commands;

use App\Models\SupertrendAlert;
use App\Services\AtmResolver;
use App\Services\DeltaClient;
use App\Services\Supertrend;
use Illuminate\Console\Command;

class ScanSupertrend extends Command
{
    protected $signature = 'supertrend:scan {--resolution=15m} {--period=10} {--factor=3} {--source=both : mark, traded or both}';
    protected $description = 'Check BTC ATM CE/PE Supertrend flips on mark and/or traded price and store alerts';

    private int $candleSecs = 900;
    private string $res = '15m';

    public function handle(AtmResolver $atm, DeltaClient $delta): int
    {
        $res = $this->option('resolution');
        $period = (int) $this->option('period');
        $factor = (float) $this->option('factor');
        $candleSecs = ['1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600][$res] ?? 900;

        $this->candleSecs = $candleSecs;
        $this->res = $res;

        $sources = match ($this->option('source')) {
            'mark'   => ['mark' => 'MARK:'],
            'traded' => ['traded' => ''],
            default  => ['mark' => 'MARK:', 'traded' => ''],
        };

        $pick = $atm->resolve();
        $this->info("[{$res}] Expiry {$pick['expiry']} | spot {$pick['spot']} | ATM {$pick['strike']}");

        foreach (['CE' => $pick['call'], 'PE' => $pick['put']] as $side => $symbol) {
            foreach ($sources as $source => $prefix) {
                $this->scanOne($delta, $prefix . $symbol, $symbol, $side, $source, $pick['strike'], $res, $period, $factor);
            }
        }

        return self::SUCCESS;
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

        foreach (array_slice($flips, -2) as $flip) {
            $isLast = $flip === end($flips);
            $type = $flip['to'] === 'green' ? 'flip_green' : 'flip_red';
            $this->raise($symbol, $side, $source, $strike, $type, $flip);

            // red -> green -> red complete
            if ($isLast && $flip['to'] === 'red' && count($flips) >= 2 && $flips[count($flips) - 2]['to'] === 'green') {
                $this->raise($symbol, $side, $source, $strike, 'cycle_red_green_red', $flip);
            }
        }
    }

    private function raise(string $symbol, string $side, string $source, float $strike, string $type, array $c): void
    {
        // Ignore flips older than ~4 candles so a first run doesn't dump history.
        if (time() - $c['time'] > 4 * $this->candleSecs) {
            return;
        }

        $label = [
            'flip_green' => 'turned GREEN',
            'flip_red' => 'turned RED',
            'cycle_red_green_red' => 'completed RED→GREEN→RED',
        ][$type];

        $tag = strtoupper($source);

        $alert = SupertrendAlert::firstOrCreate(
            ['symbol' => $symbol, 'candle_time' => $c['time'], 'type' => $type, 'source' => $source],
            [
                'side' => $side,
                'strike' => $strike,
                'price' => $c['close'],
                'line' => $c['line'],
                'message' => "[{$this->res}][{$tag}] BTC ATM {$side} {$strike} Supertrend {$label} @ " . round($c['close'], 1),
            ]
        );

        if ($alert->wasRecentlyCreated) {
            $this->line("ALERT: {$alert->message}");
        }
    }
}