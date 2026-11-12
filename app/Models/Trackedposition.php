<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A trade you entered and want the Supertrend-flip stop loss watched for.
 * SL rule (fixed): Supertrend flips RED -> GREEN on a CLOSED candle after entry.
 * status: active | sl_hit | closed (stopped by you) | expired
 */
class TrackedPosition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'strike' => 'float',
        'entry_price' => 'float',
        'last_price' => 'float',
        'last_line' => 'float',
        'exit_price' => 'float',
        'entry_at' => 'integer',
        'exit_at' => 'integer',
        'last_checked_at' => 'datetime',
    ];
}