<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an order was completed (handed over). The after-sale claim window (3 days) counts from here.
 * Kept in step with `status` by JobOrder::booted(), so every completion path stamps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('ready_for_pickup_at');
            $table->index(['store_id', 'status', 'completed_at']);
        });

        // Backfill: orders already completed have no recorded completion time — updated_at (the last time
        // the row changed) is the closest we have, same approximation the ready_for_pickup_at backfill used.
        DB::table('job_orders')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status', 'completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};
