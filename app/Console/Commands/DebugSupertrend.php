<?php

namespace App\Console\Commands;

use App\Services\AtmResolver;
use App\Services\DeltaClient;
use App\Services\Supertrend;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DebugSupertrend extends Command
{
    protected $signature = 'supertrend:debug {symbol?} {--resolution=1m} {--rows=25} {--mark : use MARK: price candles}';
    protected $description = 'Print recent candles with Supertrend direction to compare with TradingView';

    public function handle(AtmResolver $atm, DeltaClient $delta): int
    {
        $res = $this->option('resolution');
        $secs = ['1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900][$res] ?? 60;

        $symbol = $this->argument('symbol') ?: $atm->resolve()['call'];
        $fetch = ($this->option('mark') ? 'MARK:' : '') . $symbol;
        $this->info("Symbol: {$fetch} | {$res}");

        $candles = $delta->candles($fetch, $res, 150);

        // gap check: missing candles make ATR/Supertrend differ from the chart
        $gaps = 0;
        for ($i = 1; $i < count($candles); $i++) {
            if ($candles[$i]['time'] - $candles[$i - 1]['time'] > $secs) {
                $gaps++;
            }
        }
        $this->warn('Candles: ' . count($candles) . " | gaps in series: {$gaps}");

        $st = Supertrend::compute($candles, 10, 3.0);
        $byTime = [];
        foreach ($st as $s) {
            $byTime[$s['time']] = $s;
        }

        $rows = [];
        $prev = null;
        foreach (array_slice($candles, -(int) $this->option('rows')) as $c) {
            $s = $byTime[$c['time']] ?? null;
            $flip = ($s && $prev && $s['dir'] !== $prev) ? '<< FLIP' : '';
            $prev = $s['dir'] ?? $prev;
            $rows[] = [
                Carbon::createFromTimestamp($c['time'], 'Asia/Kolkata')->format('d H:i'),
                $c['open'], $c['high'], $c['low'], $c['close'],
                $s ? round($s['line'], 2) : '-',
                $s['dir'] ?? '-',
                $flip,
            ];
        }

        $this->table(['IST (open)', 'O', 'H', 'L', 'C', 'ST line', 'dir', ''], $rows);

        return self::SUCCESS;
    }
}