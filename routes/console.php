<?php

use Illuminate\Support\Facades\Schedule;

// Runs every minute; only CLOSED 15m candles are evaluated and alerts are de-duplicated in the DB.
// Schedule::command('supertrend:scan --resolution=15m --period=10 --factor=3')
//     ->everyMinute()
//     ->withoutOverlapping()
//     ->runInBackground();

// Schedule::command('supertrend:scan --resolution=1m --period=10 --factor=3')
//     ->everyMinute()
//     ->withoutOverlapping()
//     ->appendOutputTo(storage_path('logs/supertrend.log'));


// Runs every minute. Timeframe, period, factor and price source come from .env
// (SUPERTREND_RESOLUTION=1m|3m|5m|10m|15m ...), so changing timeframe needs no code edit.


// Runs every minute. Timeframe, period, factor and price source come from .env
// (SUPERTREND_RESOLUTION=1m|3m|5m|10m|15m ...), so changing timeframe needs no code edit.
// The ?: fallbacks keep the scan working even if config/supertrend.php or the .env value is missing/empty.
Schedule::command(sprintf(
    'supertrend:scan --resolution=%s --period=%d --factor=%s --source=%s',
    config('supertrend.resolution') ?: '15m',
    config('supertrend.period') ?: 10,
    config('supertrend.factor') ?: 3,
    config('supertrend.source') ?: 'both',
))
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/supertrend.log'));


    // ---- add to the bottom of routes/console.php ----
// Paper trading: runs every minute, right after the scan. Uses the same Supertrend settings.
Schedule::command(sprintf(
    'supertrend:paper --source=mark --wait=1 --period=%d --factor=%s',
    config('supertrend.period') ?: 10,
    config('supertrend.factor') ?: 3,
))
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/supertrend.log'));