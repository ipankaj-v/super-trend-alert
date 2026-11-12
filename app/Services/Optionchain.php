<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Builds the BTC option chain (CE | strike | PE) for one daily expiry from Delta tickers,
 * with the same columns as Delta's own chain: Delta, Bid, Mark, Ask, OI.
 */
class OptionChain
{
    public function __construct(private DeltaClient $delta) {}

    /** Nearest live daily expiry (DD-MM-YYYY). Daily BTC options settle 17:30 IST. */
    public static function defaultExpiry(): string
    {
        $now = Carbon::now('Asia/Kolkata');
        $d = $now->copy()->setTime(17, 30)->isPast() ? $now->copy()->addDay() : $now->copy();

        return $d->format('d-m-Y');
    }

    /** The next $days daily expiries, starting from the default one. */
    public static function expiries(int $days = 7): array
    {
        $start = Carbon::createFromFormat('d-m-Y', self::defaultExpiry(), 'Asia/Kolkata');

        return array_map(fn ($i) => $start->copy()->addDays($i)->format('d-m-Y'), range(0, $days - 1));
    }

    private static function num(mixed $v): ?float
    {
        return ($v === null || $v === '' || ! is_numeric($v)) ? null : (float) $v;
    }

    /**
     * @return array{expiry:string, expiries:array, spot:float, atm:?float, total_strikes:int, fetched_at:int, rows:array}
     */
    public function build(string $expiry, int $window = 12): array
    {
        $spot = $this->delta->btcSpot();
        $tickers = $this->delta->optionTickers($expiry);

        $byStrike = [];
        foreach ($tickers as $t) {
            $strike = (float) ($t['strike_price'] ?? 0);
            $key = match ($t['contract_type'] ?? '') {
                'call_options' => 'ce',
                'put_options' => 'pe',
                default => null,
            };
            if (! $strike || ! $key || empty($t['symbol'])) {
                continue;
            }

            $quotes = is_array($t['quotes'] ?? null) ? $t['quotes'] : [];
            $greeks = is_array($t['greeks'] ?? null) ? $t['greeks'] : [];

            $byStrike[(string) $strike][$key] = [
                'symbol' => $t['symbol'],
                'mark'   => self::num($t['mark_price'] ?? null),
                'bid'    => self::num($quotes['best_bid'] ?? null),
                'ask'    => self::num($quotes['best_ask'] ?? null),
                'delta'  => self::num($greeks['delta'] ?? null),
                'oi'     => self::num($t['oi_value_usd'] ?? null), // open interest in USD, as on Delta
            ];
        }

        $strikes = array_map('floatval', array_keys($byStrike));
        sort($strikes);

        $atm = null;
        foreach ($strikes as $s) {
            if ($atm === null || abs($s - $spot) < abs($atm - $spot)) {
                $atm = $s;
            }
        }

        $shown = $strikes;
        if ($atm !== null) {
            $i = array_search($atm, $strikes, true);
            $shown = array_slice($strikes, max(0, $i - $window), $window * 2 + 1);
        }

        $rows = array_map(fn ($s) => [
            'strike' => $s,
            'ce' => $byStrike[(string) $s]['ce'] ?? null,
            'pe' => $byStrike[(string) $s]['pe'] ?? null,
        ], $shown);

        return [
            'expiry' => $expiry,
            'expiries' => self::expiries(),
            'spot' => $spot,
            'atm' => $atm,
            'total_strikes' => count($strikes),
            'fetched_at' => time(),
            'rows' => $rows,
        ];
    }
}