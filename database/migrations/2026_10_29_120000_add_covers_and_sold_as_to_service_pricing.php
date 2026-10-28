<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each pricing option of a service says what it covers and what one ordered unit is.
 *
 *   covers   top | bottom | both   which part(s) of the person the option is for (decides the sizing asked)
 *   sold_as  garment | set        what ONE ordered unit is; a set is one top and one bottom (two physical garments at handover)
 *
 * Both are nullable on purpose and have no default: null means "not configured", so every existing option, and every
 * order made from one, keeps behaving exactly as before (no coverage implied, one unit = one garment). Nothing is backfilled.
 * The two are independent of each other; the API only rejects the one combination that makes no sense (a set that is not top + bottom).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_pricing', function (Blueprint $table) {
            $table->string('covers', 8)->nullable()->after('amount');
            $table->string('sold_as', 8)->nullable()->after('covers');
        });
    }

    public function down(): void
    {
        Schema::table('service_pricing', function (Blueprint $table) {
            $table->dropColumn(['covers', 'sold_as']);
        });
    }
};
