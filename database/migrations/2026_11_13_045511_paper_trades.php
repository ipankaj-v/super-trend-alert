<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('paper_trades')) {
            return;
        }

        Schema::create('paper_trades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('alert_id')->unique();       // the GREEN->RED alert that started it
            $t->string('symbol');                                // e.g. C-BTC-73400-121126
            $t->string('side', 2);                               // CE | PE
            $t->decimal('strike', 14, 2);
            $t->string('expiry', 10);                            // dd-mm-YYYY
            $t->string('source', 6)->default('mark');            // mark | traded
            $t->string('resolution', 4)->default('1m');
            $t->unsignedBigInteger('flip_time');                 // open time of the flip candle
            $t->decimal('flip_low', 14, 4)->nullable();
            $t->string('status', 8)->default('pending');         // pending | open | closed | skipped
            $t->string('skip_reason')->nullable();
            $t->decimal('entry_price', 14, 4)->nullable();       // sell price = close of the confirming candle
            $t->unsignedBigInteger('entry_candle_time')->nullable();
            $t->unsignedBigInteger('entry_at')->nullable();      // when that candle closed (unix)
            $t->decimal('sl_initial', 14, 4)->nullable();        // Supertrend line at entry
            $t->decimal('risk', 14, 4)->nullable();              // sl_initial - entry
            $t->decimal('sl_current', 14, 4)->nullable();        // trailing Supertrend line
            $t->decimal('last_price', 14, 4)->nullable();
            $t->string('last_dir', 5)->nullable();
            $t->decimal('low_seen', 14, 4)->nullable();          // lowest price since entry (best for a short)
            $t->decimal('high_seen', 14, 4)->nullable();         // highest price since entry (worst for a short)
            $t->timestamp('last_checked_at')->nullable();
            $t->decimal('exit_price', 14, 4)->nullable();
            $t->unsignedBigInteger('exit_at')->nullable();
            $t->string('exit_reason', 12)->nullable();           // SL flip | expiry
            $t->decimal('pnl', 14, 4)->nullable();               // points, entry - exit
            $t->decimal('r_multiple', 8, 3)->nullable();         // pnl / risk
            $t->timestamps();

            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trades');
    }
};