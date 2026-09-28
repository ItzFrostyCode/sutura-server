<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Early-Arrival Accommodation workflow (thesis feedback, 2026-09-28) —
     * see Appointment::ARRIVAL_STATUSES/EARLY_ARRIVAL_DECISIONS. scheduled_at
     * itself is never overwritten when a customer arrives early or late —
     * these columns record what ACTUALLY happened alongside it, so
     * "Scheduled Time ≠ Actual Arrival" stays true in the data, not just in
     * the UI copy. arrival_status is derived by staff action (Mark Arrival),
     * not automatically inferred — checked_in_at (already on this table)
     * still records the raw timestamp; arrival_status is the staff's
     * classification of it against scheduled_at.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('arrival_status', 20)->nullable()->after('checked_in_at');
            $table->string('early_arrival_decision', 20)->nullable()->after('arrival_status');
            $table->timestamp('actual_service_start_at')->nullable()->after('early_arrival_decision');
            $table->foreignId('accommodated_by_id')->nullable()->after('actual_service_start_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accommodated_by_id');
            $table->dropColumn(['arrival_status', 'early_arrival_decision', 'actual_service_start_at']);
        });
    }
};
