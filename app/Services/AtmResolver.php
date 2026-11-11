<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Resolves which CE and PE symbols to watch for the nearest daily expiry.
 * Delta India daily BTC options settle at 17:30 IST.
 * Strike choice comes from config/supertrend.php (atm +/- offset, or fixed).
 */
class AtmResolver
{
    public function __construct(private DeltaClient $delta) {}

    /**
     * @return array{expiry:string, spot:float, atm:float, ce_strike:float, pe_strike:float, call:string, put:string, mode:string}
     */
    public function resolve(): array
    {
        $now = Carbon::now('Asia/Kolkata');
        // After the 17:30 IST settlement the "today" expiry is gone, so roll to tomorrow.
        $expiryDate = $now->copy()->setTime(17, 30)->isPast() ? $now->copy()->addDay() : $now->copy();
        $expiry = $expiryDate->format('d-m-Y');

        $spot = $this->delta->btcSpot();
        $tickers = collect($this->delta->optionTickers($expiry));

        if ($tickers->isEmpty()) {
            throw new RuntimeException("No BTC option tickers returned for expiry {$expiry}.");
        }

        $strikesOf = fn (string $type): Collection => $tickers
            ->filter(fn ($t) => ($t['contract_type'] ?? '') === $type)
            ->map(fn ($t) => (float) ($t['strike_price'] ?? 0))
            ->filter()->unique()->values();

        $callStrikes = $strikesOf('call_options');
        $putStrikes = $strikesOf('put_options');

        $atm = $callStrikes->merge($putStrikes)->unique()
            ->sortBy(fn ($s) => abs($s - $spot))->first();

        if ($atm === null) {
            throw new RuntimeException("No strikes found for expiry {$expiry}.");
        }

        $mode = config('supertrend.strike_mode', 'atm');

        $choose = function (Collection $strikes, int $offset, $fixed, string $label) use ($mode, $atm): float {
            if ($mode === 'fixed') {
                if ($fixed === null || $fixed === '') {
                    throw new RuntimeException("Strike mode is 'fixed' but no fixed {$label} strike is set in .env.");
                }
                $target = (float) $fixed;
                if (! $strikes->contains($target)) {
                    $near = $strikes->sortBy(fn ($s) => abs($s - $target))->take(3)->implode(', ');
                    throw new RuntimeException("Fixed {$label} strike {$target} is not listed for this expiry. Nearest: {$near}");
                }

                return $target;
            }

            // atm mode: nearest listed strike to ATM + offset
            $target = $atm + $offset;

            return (float) $strikes->sortBy(fn ($s) => abs($s - $target))->first();
        };

        $ceStrike = $choose(
            $callStrikes,
            (int) config('supertrend.ce_offset', 0),
            config('supertrend.fixed_ce_strike') ?: config('supertrend.fixed_strike'),
            'CE'
        );
        $peStrike = $choose(
            $putStrikes,
            (int) config('supertrend.pe_offset', 0),
            config('supertrend.fixed_pe_strike') ?: config('supertrend.fixed_strike'),
            'PE'
        );

        $symbolFor = fn (string $type, float $strike) => $tickers->first(
            fn ($t) => ($t['contract_type'] ?? '') === $type
                && (float) ($t['strike_price'] ?? 0) === $strike
        )['symbol'] ?? null;

        $call = $symbolFor('call_options', $ceStrike);
        $put = $symbolFor('put_options', $peStrike);

        if (! $call || ! $put) {
            throw new RuntimeException("CE {$ceStrike} / PE {$peStrike} symbol missing for expiry {$expiry}.");
        }

        return [
            'expiry' => $expiry, 'spot' => $spot, 'atm' => (float) $atm,
            'ce_strike' => $ceStrike, 'pe_strike' => $peStrike,
            'call' => $call, 'put' => $put, 'mode' => $mode,
        ];
    }
}