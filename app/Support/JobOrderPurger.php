<?php

namespace App\Support;

use App\Models\JobOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes an ARCHIVED (soft-deleted) job order for good, together with everything that
 * points at it. The caller has already checked access and that it has no payments or
 * refunds. Run inside a DB transaction.
 */
class JobOrderPurger
{
    /** Child tables whose rows only make sense with their order (all are ON DELETE CASCADE too). */
    private const CHILD_TABLES = [
        'order_quotation_versions',
        'job_order_assignee_events',
        'job_order_assignees',
        'order_corrections',
        'order_handovers',
        'order_materials',
        'job_order_staff',
    ];

    public static function hasPaymentRecords(JobOrder $jobOrder): bool
    {
        return $jobOrder->payments()->exists() || $jobOrder->refunds()->exists();
    }

    public static function purge(JobOrder $jobOrder): void
    {
        $id = $jobOrder->id;

        foreach (self::CHILD_TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('job_order_id', $id)->delete();
            }
        }

        // Rows that outlive the order just lose the link (both columns are nullable, ON DELETE SET NULL).
        DB::table('appointments')->where('job_order_id', $id)->update(['job_order_id' => null]);
        DB::table('job_orders')->where('parent_order_id', $id)->update(['parent_order_id' => null]);

        DB::table('job_orders')->where('follow_up_of_id', $id)->update(['follow_up_of_id' => null]);

        $jobOrder->forceDelete();
    }
}
