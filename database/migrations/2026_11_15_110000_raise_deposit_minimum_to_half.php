<?php

use App\Support\OfferingDataRepair;
use Illuminate\Database\Migrations\Migration;

/**
 * Shop rule (2026-11-15): a made-to-order job, walk-in or online, starts production only after the
 * customer pays at least 50%. Old settings that said "pay at pick-up" or a smaller deposit become a
 * 50% deposit, so the settings screens show what the system really asks. Repair services keep theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        OfferingDataRepair::depositFloor();
    }

    public function down(): void
    {
        // Data only: the old per-shop choices are not kept.
    }
};
