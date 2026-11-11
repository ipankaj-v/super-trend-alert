<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Delta Exchange India public market-data endpoints.
 * Base URL: https://api.india.delta.exchange/v2  (no auth needed for these).
 *
 * NOTE: field names below (strike_price, contract_type, spot_price, mark_price,
 * OHLC keys) follow Delta's usual payloads but were not fully verifiable from the
 * public swagger excerpt. Run `php artisan supertrend:inspect` once to confirm.
 */
class DeltaClient
{
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
     * Candles ascending by time. $count candles ending now.
     * Returns [['time','open','high','low','close'], ...]
     */
    public function candles(string $symbol, string $resolution = '15m', int $count = 120): array
    {
        $secs = ['1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600][$resolution] ?? 900;
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
}
