<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supertrend_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');                 // e.g. C-BTC-95000-291026
            $t->string('side', 2);                // CE | PE
            $t->string('type');                   // flip_green | flip_red | cycle_red_green_red
            $t->decimal('strike', 14, 2)->nullable();
            $t->decimal('price', 14, 4);          // option premium at the flip candle close
            $t->decimal('line', 14, 4);           // supertrend value
            $t->unsignedBigInteger('candle_time'); // unix seconds of the flipping candle
            $t->string('message');
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();

            // one alert per symbol + candle + type, so re-scans never duplicate
            $t->unique(['symbol', 'candle_time', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supertrend_alerts');
    }
};
