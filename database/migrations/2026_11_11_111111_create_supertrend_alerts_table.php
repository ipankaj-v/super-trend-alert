<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const OLD_UNIQUE = 'supertrend_alerts_symbol_candle_time_type_source_unique';
    // explicit short name: MySQL rejects identifiers longer than 64 characters
    private const NEW_UNIQUE = 'st_alerts_candle_uniq';

    /** Safe to re-run after a partial failure (MySQL does not roll back schema changes). */
    public function up(): void
    {
        // 1. drop the old unique index if it is still there
        try {
            Schema::table('supertrend_alerts', fn (Blueprint $t) => $t->dropUnique(self::OLD_UNIQUE));
        } catch (\Throwable $e) {
            // already dropped
        }

        // 2. add the resolution column if missing
        if (! Schema::hasColumn('supertrend_alerts', 'resolution')) {
            Schema::table('supertrend_alerts', function (Blueprint $t) {
                $t->string('resolution', 4)->default('15m')->after('source'); // 1m | 3m | 5m | 10m | 15m ...
            });
        }

        // 3. old rows: read the timeframe from the "[1m]" tag at the start of the message
        foreach (DB::table('supertrend_alerts')->get(['id', 'message']) as $row) {
            if (preg_match('/^\[(\w+)\]/', $row->message, $m)) {
                DB::table('supertrend_alerts')->where('id', $row->id)->update(['resolution' => $m[1]]);
            }
        }

        // 4. a 1m and a 15m candle can open at the same minute, so resolution is part of the key
        try {
            Schema::table('supertrend_alerts', fn (Blueprint $t) => $t->dropUnique(self::NEW_UNIQUE));
        } catch (\Throwable $e) {
            // not created yet
        }
        Schema::table('supertrend_alerts', function (Blueprint $t) {
            $t->unique(['symbol', 'candle_time', 'type', 'source', 'resolution'], self::NEW_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('supertrend_alerts', function (Blueprint $t) {
            $t->dropUnique(self::NEW_UNIQUE);
            $t->dropColumn('resolution');
            $t->unique(['symbol', 'candle_time', 'type', 'source'], self::OLD_UNIQUE);
        });
    }
};