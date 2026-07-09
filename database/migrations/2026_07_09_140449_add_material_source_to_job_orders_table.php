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
        Schema::table('job_orders', function (Blueprint $table) {
            // Defaults to shop-supplied since that's the common case for
            // made-to-order tailoring; walk-ins bringing their own fabric/an
            // existing garment are the exception that needs an explicit flag.
            $table->string('material_source')->default('shop_supplied')->after('reference_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('material_source');
        });
    }
};
