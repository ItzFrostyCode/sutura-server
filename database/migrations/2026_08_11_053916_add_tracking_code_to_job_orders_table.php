<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            // No login required to check on an order — a real pain point
            // named in the interview research ("sa na po ba?" messages from
            // customers who don't want to create an account just to check
            // status). Globally unique (not scoped per shop) since the
            // public lookup endpoint has no other way to know which shop's
            // job_orders table to search. Nullable: backfilled below for
            // existing rows, but a schema-level default isn't possible for
            // a per-row random value.
            $table->string('tracking_code', 20)->nullable()->unique()->after('order_number');
        });

        // Backfill every existing job order so demo/live data is trackable
        // immediately, not just orders created after this migration runs.
        // Raw DB facade, not the Eloquent model — migrations must stay
        // decoupled from application code that can change shape later.
        $existingIds = DB::table('job_orders')->whereNull('tracking_code')->pluck('id');
        foreach ($existingIds as $id) {
            do {
                $code = strtoupper(Str::random(8));
            } while (DB::table('job_orders')->where('tracking_code', $code)->exists());

            DB::table('job_orders')->where('id', $id)->update(['tracking_code' => $code]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('tracking_code');
        });
    }
};
