<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same design, several identical pieces for ONE person (e.g. 2 identical
 * barongs) — distinct from a bulk order's team_roster, which is for
 * DIFFERENT people with different sizes/measurements. quantity multiplies
 * a single measurement profile's output; it is meaningless for a bulk
 * order (JobOrder::isBulkOrder()) and the frontend hides it there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('measurement_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
