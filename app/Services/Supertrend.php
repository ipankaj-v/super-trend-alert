<?php

namespace App\Services;

class Supertrend
{
    /**
     * @param  array $candles Ascending by time, each: ['time','open','high','low','close']
     * @return array Per-candle results from index $period onward:
     *               ['time' => int, 'close' => float, 'line' => float, 'dir' => 'green'|'red']
     */
    public static function compute(array $candles, int $period = 10, float $factor = 3.0): array
    {
        $n = count($candles);
        if ($n <= $period + 1) {
            return [];
        }

        // True range
        $tr = [];
        for ($i = 0; $i < $n; $i++) {
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            if ($i === 0) {
                $tr[$i] = $h - $l;
                continue;
            }
            $pc = (float) $candles[$i - 1]['close'];
            $tr[$i] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        // Wilder ATR (RMA), seeded with SMA of first $period TRs
        $atr = [];
        $atr[$period - 1] = array_sum(array_slice($tr, 0, $period)) / $period;
        for ($i = $period; $i < $n; $i++) {
            $atr[$i] = (($atr[$i - 1] * ($period - 1)) + $tr[$i]) / $period;
        }

        $out = [];
        $finalUpper = $finalLower = null;
        $dir = 'red'; // initial assumption; converges after a few candles

        for ($i = $period; $i < $n; $i++) {
            $hl2 = ($candles[$i]['high'] + $candles[$i]['low']) / 2;
            $basicUpper = $hl2 + $factor * $atr[$i];
            $basicLower = $hl2 - $factor * $atr[$i];
            $close = (float) $candles[$i]['close'];

            if ($finalUpper === null) {
                $finalUpper = $basicUpper;
                $finalLower = $basicLower;
            } else {
                $prevClose = (float) $candles[$i - 1]['close'];
                $finalUpper = ($basicUpper < $finalUpper || $prevClose > $finalUpper) ? $basicUpper : $finalUpper;
                $finalLower = ($basicLower > $finalLower || $prevClose < $finalLower) ? $basicLower : $finalLower;
            }

            if ($dir === 'red' && $close > $finalUpper) {
                $dir = 'green';
            } elseif ($dir === 'green' && $close < $finalLower) {
                $dir = 'red';
            }

            $out[] = [
                'time'  => (int) $candles[$i]['time'],
                'close' => $close,
                'line'  => $dir === 'green' ? $finalLower : $finalUpper,
                'dir'   => $dir,
            ];
        }

        return $out;
    }
}
