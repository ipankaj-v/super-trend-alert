<?php

namespace App\Services;

/**
 * The paper-trade rules, kept free of database code so they can be tested on their own.
 *
 *   entry : after a GREEN->RED alert, the NEXT candle must CLOSE below the flip candle's low.
 *   exit  : trail the stop with the Supertrend line; exit when a candle closes with the Supertrend GREEN.
 *
 * $c = closed candles ascending ['time','open','high','low','close'];
 * $st = Supertrend::compute($c) (its first entry belongs to candle index $p).
 */
class PaperRules
{
    /**
     * @return array action = missing | wait | skip | enter
     */
    public static function entry(array $c, array $st, int $p, int $flipTime, int $secs, int $wait): array
    {
        $f = null;
        foreach ($c as $i => $x) {
            if ((int) $x['time'] === $flipTime) {
                $f = $i;
                break;
            }
        }
        if ($f === null || $f < $p) {
            return ['action' => 'missing'];
        }

        $low = $c[$f]['low'];

        for ($k = 1; $k <= $wait; $k++) {
            $x = $f + $k;
            if (! isset($c[$x])) {
                return ['action' => 'wait', 'low' => $low]; // that candle has not closed yet
            }
            if ($c[$x]['time'] !== $c[$x - 1]['time'] + $secs) {
                return ['action' => 'skip', 'low' => $low, 'reason' => 'candle gap'];
            }
            if ($st[$x - $p]['dir'] !== 'red') {
                return ['action' => 'skip', 'low' => $low, 'reason' => 'Supertrend turned back up'];
            }
            if ($c[$x]['close'] < $low) {
                $sl = $st[$x - $p]['line'];

                return [
                    'action' => 'enter', 'low' => $low,
                    'entry' => $c[$x]['close'], 'candle_time' => $c[$x]['time'], 'entry_at' => $c[$x]['time'] + $secs,
                    'sl' => $sl, 'risk' => $sl - $c[$x]['close'],
                ];
            }
        }

        return ['action' => 'skip', 'low' => $low, 'reason' => $wait > 1
            ? "no close below flip low within {$wait} candles" : 'next candle did not close below flip low'];
    }

    /**
     * Follow an open short: returns the latest state and, if the Supertrend flipped GREEN, the exit.
     *
     * @return array found, exit(bool), price, exit_at, last{price,line,dir}, low, high
     */
    public static function manage(array $c, array $st, int $p, int $entryCandleTime, int $secs): array
    {
        $e = null;
        foreach ($c as $i => $x) {
            if ((int) $x['time'] === $entryCandleTime) {
                $e = $i;
                break;
            }
        }
        if ($e === null) {
            return ['found' => false, 'exit' => false];
        }

        $lo = INF;
        $hi = -INF;
        $last = null;

        for ($m = $e + 1; $m < count($c); $m++) {
            if ($m < $p) {
                continue;
            }
            $s = $st[$m - $p];
            $lo = min($lo, $c[$m]['low']);
            $hi = max($hi, $c[$m]['high']);
            $last = ['price' => $c[$m]['close'], 'line' => $s['line'], 'dir' => $s['dir']];

            if ($s['dir'] === 'green') {
                return [
                    'found' => true, 'exit' => true, 'price' => $c[$m]['close'], 'exit_at' => $c[$m]['time'] + $secs,
                    'last' => $last, 'low' => $lo, 'high' => $hi,
                ];
            }
        }

        return ['found' => true, 'exit' => false, 'last' => $last, 'low' => $lo === INF ? null : $lo, 'high' => $hi === -INF ? null : $hi];
    }
}