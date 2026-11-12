<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_positions', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');                               // e.g. C-BTC-73800-111126
            $t->string('side', 2);                              // CE | PE
            $t->decimal('strike', 14, 2);
            $t->string('expiry', 10);                           // dd-mm-YYYY
            $t->string('source', 6)->default('mark');           // mark | traded
            $t->string('resolution', 4)->default('15m');
            $t->decimal('entry_price', 14, 4);
            $t->unsignedBigInteger('entry_at');                 // unix seconds
            $t->string('status', 10)->default('active');        // active | sl_hit | closed | expired
            $t->string('last_dir', 5)->nullable();             // current Supertrend colour
            $t->decimal('last_price', 14, 4)->nullable();      // last closed-candle close
            $t->decimal('last_line', 14, 4)->nullable();       // current Supertrend line (SL level)
            $t->timestamp('last_checked_at')->nullable();
            $t->decimal('exit_price', 14, 4)->nullable();
            $t->unsignedBigInteger('exit_at')->nullable();
            $t->timestamps();

            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_positions');
    }
};