<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only migration (no column/type changes — status/stage columns are
 * plain strings) that renames existing rows to match the new 3-Phase
 * Tailoring Tracker pipeline introduced alongside this migration:
 *   - job_orders.status: 'fitting' -> 'ready_for_fitting', 'finishing' -> 'qc_ironing'
 *   - job_order_staff.stage: 'finishing' -> 'qc_ironing'; 'fitting' rows are
 *     deleted outright — Fitting is no longer a backroom staff stage.
 *   - appointments.appointment_type: 'bulk_custom' -> 'consultation' — Bulk/
 *     Custom Order was removed as an appointment type (it's a Job Order
 *     concept, handled via the Team Roster feature instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('job_orders')->where('status', 'fitting')->update(['status' => 'ready_for_fitting']);
        DB::table('job_orders')->where('status', 'finishing')->update(['status' => 'qc_ironing']);

        DB::table('job_order_staff')->where('stage', 'finishing')->update(['stage' => 'qc_ironing']);
        DB::table('job_order_staff')->where('stage', 'fitting')->delete();

        DB::table('appointments')->where('appointment_type', 'bulk_custom')->update(['appointment_type' => 'consultation']);
    }

    public function down(): void
    {
        DB::table('job_orders')->where('status', 'ready_for_fitting')->update(['status' => 'fitting']);
        DB::table('job_orders')->where('status', 'qc_ironing')->update(['status' => 'finishing']);

        DB::table('job_order_staff')->where('stage', 'qc_ironing')->update(['stage' => 'finishing']);

        // 'bulk_custom' appointment rows and deleted 'fitting' stage pivot
        // rows are not recoverable on rollback — this is a one-way data
        // cleanup for those two cases.
    }
};
