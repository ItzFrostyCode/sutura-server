<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            // Not positioned with ->after('fulfillment_type') — that column doesn't
            // exist yet at this point in migration history (added later by
            // 2026_07_01_000000_add_intake_channel_and_fulfillment_type_to_orders_tables),
            // and MySQL (unlike SQLite) errors if the referenced column is missing.
            $table->date('rental_start_date')->nullable();
            $table->date('rental_end_date')->nullable()->after('rental_start_date');
            $table->decimal('security_deposit_amount', 10, 2)->nullable()->after('rental_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn(['rental_start_date', 'rental_end_date', 'security_deposit_amount']);
        });
    }
};
