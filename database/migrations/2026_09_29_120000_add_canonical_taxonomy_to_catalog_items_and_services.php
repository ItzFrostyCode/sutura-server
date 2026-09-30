<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive columns for the canonical Category → Subcategory → Garment
     * Structure → Garment Type (and Service Category → Service Type)
     * taxonomy — see App\Support\CanonicalTaxonomy. Plain string() columns,
     * same portable pattern already established on department/garment_type
     * (see 2026_09_27_210000_add_department_to_catalog_items_and_services.php
     * and 2026_07_09_171131_convert_enum_columns_to_strings_for_role_and_status.php's
     * own reasoning) — no real DB enum, validated app-side via Rule::in().
     */
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('subcategory', 40)->nullable()->after('department');
            $table->string('garment_structure', 20)->nullable()->after('subcategory');
        });

        // NOTE: `service_type` (singular) already exists on this table — a
        // legacy pre-array column from before service_types (JSON array,
        // still real/fillable, superseded operationally but never dropped;
        // see 2026_07_06_135552_add_service_type_and_min_order_qty_to_services_table.php
        // and 2026_07_08_074254_add_multi_select_category_and_type_to_services_table.php).
        // The new canonical taxonomy leaf column is named `service_leaf_type`
        // to avoid colliding with it — this migration would otherwise fail
        // outright with a duplicate-column error.
        Schema::table('services', function (Blueprint $table) {
            $table->string('service_category', 40)->nullable()->after('department');
            $table->string('service_leaf_type', 60)->nullable()->after('service_category');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['subcategory', 'garment_structure']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['service_category', 'service_leaf_type']);
        });
    }
};
