<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Embroidery services (Garment Customization) stitch onto the customer's own clothes, so they now follow the
 * same short flow as repairs: queued, in repair, quality check, ready (Service::deriveOrderTypes). Existing
 * services get the type the code would now derive. Orders already made keep what they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('services')->where('service_category', 'garment_customization')
            ->update(['service_types' => json_encode(['alteration_repair'])]);
    }

    public function down(): void
    {
        DB::table('services')->where('service_category', 'garment_customization')
            ->update(['service_types' => json_encode(['custom_tailoring'])]);
    }
};
