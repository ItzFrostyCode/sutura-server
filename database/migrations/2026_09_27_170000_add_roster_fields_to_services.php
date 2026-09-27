<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra per-person roster columns a shop owner defines on a bulk-typed
 * Service (e.g. "Jersey Number" for a jersey printing service, "Position"
 * for an SSC/office uniform service) — distinct from custom_fields, which
 * is asked ONCE per booking/order, not once per roster row. Same shape as
 * ServiceField (id, label, type, required, options?).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('roster_fields')->nullable()->after('custom_fields');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('roster_fields');
        });
    }
};
