<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Delta Exchange India public market-data endpoints.
 * Base URL: https://api.india.delta.exchange/v2  (no auth needed for these).
 */
class DeltaClient
{
    /** Resolutions the Delta candles API serves directly, in seconds. */
    public const NATIVE = [
        '1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800,
        '1h' => 3600, '2h' => 7200, '4h' => 14400, '6h' => 21600, '1d' => 86400,
    ];

    /** Resolutions we build ourselves: [base resolution, bucket seconds]. */
    public const DERIVED = [
        '10m' => ['5m', 600],
    ];

    /** Candle length in seconds for any supported resolution. */
    public static function seconds(string $resolution): int
    {
        return self::NATIVE[$resolution] ?? (self::DERIVED[$resolution][1] ?? 900);
    }

    private function http()
    {
        return Http::baseUrl(config('services.delta.base_url', 'https://api.india.delta.exchange/v2'))
            ->acceptJson()
            ->timeout(15)
            ->retry(3, 500);
    }

    /** All live BTC option tickers for one expiry (DD-MM-YYYY). */
    public function optionTickers(string $expiry): array
    {
        $res = $this->http()->get('/tickers', [
            'contract_types'           => 'call_options,put_options',
            'underlying_asset_symbols' => 'BTC',
            'expiry_date'              => $expiry,
        ])->throw()->json();

        return $res['result'] ?? [];
    }

    /** BTC spot/index price, taken from the perpetual ticker. */
    public function btcSpot(): float
    {
        $res = $this->http()->get('/tickers/BTCUSD')->throw()->json();
        $t = $res['result'] ?? [];
        $price = $t['spot_price'] ?? $t['mark_price'] ?? $t['close'] ?? null;

        if ($price === null) {
            throw new RuntimeException('Could not read BTC spot price from Delta ticker.');
        }

        return (float) $price;
    }

    /**
     * Candles ascending by time, $count candles ending now.
     * Returns [['time','open','high','low','close'], ...] where time = candle OPEN time (unix seconds).
     */
    public function candles(string $symbol, string $resolution = '15m', int $count = 120): array
    {
        if (isset(self::DERIVED[$resolution])) {
            [$base, $bucket] = self::DERIVED[$resolution];
            $parts = intdiv($bucket, self::NATIVE[$base]);

            return self::aggregate($this->fetch($symbol, $base, $count * $parts + $parts), $bucket, $parts);
        }

        return $this->fetch($symbol, $resolution, $count);
    }

    private function fetch(string $symbol, string $resolution, int $count): array
    {
        $secs = self::NATIVE[$resolution] ?? 900;
        $end = time();
        $start = $end - ($secs * $count);

        $res = $this->http()->get('/history/candles', [
            'symbol'     => $symbol,
            'resolution' => $resolution,
            'start'      => $start,
            'end'        => $end,
        ])->throw()->json();

        $rows = $res['result'] ?? [];
        usort($rows, fn ($a, $b) => $a['time'] <=> $b['time']);

        return array_map(fn ($r) => [
            'time'  => (int) $r['time'],
            'open'  => (float) $r['open'],
            'high'  => (float) $r['high'],
            'low'   => (float) $r['low'],
            'close' => (float) $r['close'],
        ], $rows);
    }

    /**
     * Merge small candles into clock-aligned buckets (e.g. 5m -> 10m: 12:00, 12:10, ...).
     * The oldest bucket is dropped if it is missing some of its parts.
     */
    public static function aggregate(array $candles, int $bucketSecs, int $parts): array
    {
        $out = [];
        foreach ($candles as $c) {
            $b = intdiv($c['time'], $bucketSecs) * $bucketSecs;
            if (! isset($out[$b])) {
                $out[$b] = ['time' => $b, 'open' => $c['open'], 'high' => $c['high'],
                            'low' => $c['low'], 'close' => $c['close'], 'n' => 1];
            } else {
                $out[$b]['high'] = max($out[$b]['high'], $c['high']);
                $out[$b]['low'] = min($out[$b]['low'], $c['low']);
                $out[$b]['close'] = $c['close'];
                $out[$b]['n']++;
            }
        }

        $out = array_values($out);
        if ($out && $out[0]['n'] < $parts) {
            array_shift($out);
        }

        return array_map(function ($c) {
            unset($c['n']);

            return $c;
        }, $out);
    }
}