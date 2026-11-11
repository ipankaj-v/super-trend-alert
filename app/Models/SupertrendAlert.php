<?php

namespace App\Models;

use App\Services\DeltaClient;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SupertrendAlert extends Model
{
    protected $guarded = [];

    protected $casts = ['acknowledged_at' => 'datetime'];

    // included in JSON so the dashboard feed can show them for new alerts
    protected $appends = ['closed_label', 'candle_label'];

    /** When the flip candle CLOSED (IST) = candle open time + timeframe length. */
    public function getClosedAtAttribute(): Carbon
    {
        $secs = DeltaClient::seconds($this->resolution ?: '15m');

        return Carbon::createFromTimestamp((int) $this->candle_time + $secs, 'Asia/Kolkata');
    }

    public function getClosedLabelAttribute(): string
    {
        return $this->closed_at->format('d M H:i') . ' IST';
    }

    /**
     * Same labelling as the Delta/TradingView chart (candles are named by their OPEN time):
     * "11 Nov · chart candle 10:35 · closed 10:36"
     */
    public function getCandleLabelAttribute(): string
    {
        $open = Carbon::createFromTimestamp((int) $this->candle_time, 'Asia/Kolkata');

        return $open->format('d M') . ' · chart candle ' . $open->format('H:i')
            . ' · closed ' . $this->closed_at->format('H:i') . ' IST';
    }
}