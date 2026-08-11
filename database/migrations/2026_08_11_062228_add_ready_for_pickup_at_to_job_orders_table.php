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
            $table->timestamp('ready_for_pickup_at')->nullable()->after('status');
        });

        // Backfill: any job order already sitting at ready_for_pickup has no
        // recorded entry time yet — approximate it with updated_at (the last
        // time its row changed) rather than leaving existing rows unable to
        // ever show up in the new "unclaimed pickups" aging list at all.
        \Illuminate\Support\Facades\DB::table('job_orders')
            ->where('status', 'ready_for_pickup')
            ->whereNull('ready_for_pickup_at')
            ->update(['ready_for_pickup_at' => \Illuminate\Support\Facades\DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('ready_for_pickup_at');
        });
    }
};
