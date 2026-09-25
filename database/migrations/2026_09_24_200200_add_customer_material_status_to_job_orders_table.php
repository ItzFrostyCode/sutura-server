<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the factual condition of a customer-supplied material on
     * receipt or after an incident — meaningful only when
     * material_source = 'customer_supplied' (JobOrder::MATERIAL_SOURCES).
     * A plain string, not a DB enum, matching how every other status-shaped
     * column on this table was already converted (see
     * 2026_07_09_171131_convert_enum_columns_to_strings_for_role_and_status.php)
     * so a future value never needs its own migration. The system only
     * records the fact (safe/damaged/lost/returned) — never a liability or
     * compensation decision. See docs/EMERGENCY-WORKFLOW.md §7,
     * docs/PRACTICAL-SITUATIONS.md #13.
     */
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('customer_material_status', 20)->nullable()->after('material_source');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('customer_material_status');
        });
    }
};
