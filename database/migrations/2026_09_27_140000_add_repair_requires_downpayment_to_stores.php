<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many shops charge repairs at pickup rather than taking 50% up front, so the
 * "No DP, No Cut" gate on the repair pipeline is a per-shop choice, off by
 * default. Custom tailoring and bulk orders always keep the 50% gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('repair_requires_downpayment')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('repair_requires_downpayment');
        });
    }
};
