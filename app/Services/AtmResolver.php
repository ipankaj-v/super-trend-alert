<?php

namespace App\Services;

use Carbon\Carbon;
use RuntimeException;

/**
 * Finds the ATM call and put symbols for the nearest daily expiry.
 * Delta India lists daily BTC options that settle at 17:30 IST.
 */
class AtmResolver
{
    public function __construct(private DeltaClient $delta) {}

    /** @return array{expiry:string, strike:float, spot:float, call:string, put:string} */
    public function resolve(): array
    {
        $now = Carbon::now('Asia/Kolkata');
        // After the 17:30 IST settlement the "today" expiry is gone, so roll to tomorrow.
        $expiryDate = $now->copy()->setTime(17, 30)->isPast() ? $now->copy()->addDay() : $now->copy();
        $expiry = $expiryDate->format('d-m-Y');

        $spot = $this->delta->btcSpot();
        $tickers = $this->delta->optionTickers($expiry);

        if (empty($tickers)) {
            throw new RuntimeException("No BTC option tickers returned for expiry {$expiry}.");
        }

        $strikes = collect($tickers)
            ->map(fn ($t) => (float) ($t['strike_price'] ?? 0))
            ->filter()->unique()->values();

        $atm = $strikes->sortBy(fn ($s) => abs($s - $spot))->first();

        $find = fn (string $type) => collect($tickers)->first(
            fn ($t) => (float) ($t['strike_price'] ?? 0) === $atm
                && ($t['contract_type'] ?? '') === $type
        )['symbol'] ?? null;

        $call = $find('call_options');
        $put = $find('put_options');

        if (! $call || ! $put) {
            throw new RuntimeException("ATM strike {$atm} found but CE/PE symbol missing.");
        }

        return ['expiry' => $expiry, 'strike' => $atm, 'spot' => $spot, 'call' => $call, 'put' => $put];
    }
}
