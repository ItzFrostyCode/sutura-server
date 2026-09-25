<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data-only migration — no schema change. Before this session,
     * customerRepairRequest() stored the customer's original repair
     * description in the generic `notes` column (docs/REPAIR-WORKFLOW.md §3).
     * That call now writes it to custom_order_data.repair_note instead, to
     * keep it separate from staff-appended operational notes. This backfills
     * any pre-existing repair job orders so old and new rows share the same
     * contract — `notes` itself is left untouched (non-destructive; nothing
     * is deleted, only additively populated in custom_order_data).
     */
    public function up(): void
    {
        DB::table('job_orders')
            ->where('garment_category', 'alteration_repair')
            ->whereNotNull('notes')
            ->orderBy('id')
            ->chunkById(200, function ($jobOrders) {
                foreach ($jobOrders as $jobOrder) {
                    $customOrderData = $jobOrder->custom_order_data
                        ? json_decode($jobOrder->custom_order_data, true)
                        : [];

                    if (! is_array($customOrderData) || array_key_exists('repair_note', $customOrderData)) {
                        continue;
                    }

                    $customOrderData['repair_note'] = $jobOrder->notes;

                    DB::table('job_orders')
                        ->where('id', $jobOrder->id)
                        ->update(['custom_order_data' => json_encode($customOrderData)]);
                }
            });
    }

    /**
     * Not reversible by design — removing repair_note on rollback risks
     * deleting the one copy of a real customer request if `up()` ever ran
     * against a row whose `notes` had since been edited by staff. Rolling
     * back the controller behavior change alone (in code) is sufficient;
     * this backfilled data is safe to leave in place either way.
     */
    public function down(): void
    {
        // Intentionally a no-op — see class docblock.
    }
};
