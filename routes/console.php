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

    Schedule::command('supertrend:scan --resolution=15m --period=10 --factor=3 --source=mark')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/supertrend.log'));