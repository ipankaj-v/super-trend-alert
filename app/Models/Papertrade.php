<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A simulated SELL created from a GREEN->RED alert (no real order is ever placed).
 * status: pending (waiting for the next candle) | open | closed | skipped
 */
class PaperTrade extends Model
{
    protected $guarded = [];

    protected $casts = [
        'strike' => 'float', 'flip_low' => 'float', 'entry_price' => 'float', 'sl_initial' => 'float', 'risk' => 'float',
        'sl_current' => 'float', 'last_price' => 'float', 'low_seen' => 'float', 'high_seen' => 'float',
        'exit_price' => 'float', 'pnl' => 'float', 'r_multiple' => 'float',
        'flip_time' => 'integer', 'entry_candle_time' => 'integer', 'entry_at' => 'integer', 'exit_at' => 'integer',
        'last_checked_at' => 'datetime',
    ];
}