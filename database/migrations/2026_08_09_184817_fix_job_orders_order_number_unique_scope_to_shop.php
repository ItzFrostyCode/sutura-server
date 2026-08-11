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
        // order_number was globally unique, but generateOrderNumber() resets
        // the sequence per shop per year (JO-2026-0001, ...) — the first job
        // order of the year for any second shop collides with the first
        // shop's. Replace the global unique index with a composite one so
        // each shop's own numbering can start at 0001 without colliding.
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropUnique('job_orders_order_number_unique');
            $table->unique(['shop_id', 'order_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'order_number']);
            $table->unique('order_number');
        });
    }
};
