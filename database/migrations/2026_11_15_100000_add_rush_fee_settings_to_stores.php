<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's rush setting, so the online order shows the rush price instead of "set by the shop" (was a fixed 30% in
 * code; 30% stays the default so walk-in orders are unchanged):
 *  - rush_available: whether the shop takes rush orders
 *  - rush_fee_mode: percent (rush_fee_percent of the price) or per_piece (rush_fee_per_piece pesos for every piece)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('rush_available')->default(true);
            $table->string('rush_fee_mode', 10)->default('percent');
            $table->decimal('rush_fee_percent', 5, 2)->default(30);
            $table->decimal('rush_fee_per_piece', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['rush_available', 'rush_fee_mode', 'rush_fee_percent', 'rush_fee_per_piece']));
    }
};
