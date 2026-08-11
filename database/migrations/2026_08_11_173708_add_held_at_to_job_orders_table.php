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
        // Same aging-alert pattern as ready_for_pickup_at/the Unclaimed
        // Pickups list — an on_hold job is explicitly excluded from the
        // overdue_jobs KPI (correctly, since the owner paused it on
        // purpose), but that also meant it had zero visibility anywhere
        // once held — no aging signal at all, easy to genuinely forget.
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('held_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('held_at');
        });
    }
};
