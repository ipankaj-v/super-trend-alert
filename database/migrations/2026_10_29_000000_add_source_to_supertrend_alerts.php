<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supertrend_alerts', function (Blueprint $t) {
            $t->dropUnique(['symbol', 'candle_time', 'type']);
            $t->string('source', 6)->default('mark')->after('side'); // mark | traded
            $t->unique(['symbol', 'candle_time', 'type', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('supertrend_alerts', function (Blueprint $t) {
            $t->dropUnique(['symbol', 'candle_time', 'type', 'source']);
            $t->dropColumn('source');
            $t->unique(['symbol', 'candle_time', 'type']);
        });
    }
};