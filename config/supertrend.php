<?php

return [
    /*
     | Timeframe and Supertrend settings used by the scheduler (routes/console.php).
     | resolution: 1m, 3m, 5m, 10m, 15m, 30m, 1h   |   source: mark, traded or both
     */
    'resolution' => env('SUPERTREND_RESOLUTION', '15m'),
    'period'     => (int) env('SUPERTREND_PERIOD', 10),
    'factor'     => (float) env('SUPERTREND_FACTOR', 3),
    'source'     => env('SUPERTREND_SOURCE', 'both'),

    /*
     | Which flips raise an alert (always evaluated on CLOSED candles only):
     |   red   = only GREEN -> RED flips   (default)
     |   green = only RED -> GREEN flips
     |   both  = either
     */
    'alert_on' => env('SUPERTREND_ALERT_ON', 'red'),

    // An alert is only raised if the flip candle closed within this many seconds.
    // Keep it above 60 (scheduler runs every minute).
    'grace_seconds' => (int) env('SUPERTREND_GRACE_SECONDS', 180),

    /*
     | Which strike to watch.
     |   atm   = nearest strike to BTC spot, plus the CE/PE offsets below (in USD points)
     |   fixed = exact strike(s) you set below; stays on them until you change it
     */
    'strike_mode' => env('SUPERTREND_STRIKE_MODE', 'atm'),

    // atm mode: offset from ATM, e.g. CE +200 (OTM call), PE -200 (OTM put). 0 = pure ATM.
    'ce_offset' => (int) env('SUPERTREND_CE_OFFSET', 0),
    'pe_offset' => (int) env('SUPERTREND_PE_OFFSET', 0),

    // fixed mode: one strike for both, or set CE / PE separately (separate ones win).
    'fixed_strike'    => env('SUPERTREND_FIXED_STRIKE'),
    'fixed_ce_strike' => env('SUPERTREND_FIXED_CE_STRIKE'),
    'fixed_pe_strike' => env('SUPERTREND_FIXED_PE_STRIKE'),
];