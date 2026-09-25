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
        Schema::table('appointments', function (Blueprint $table) {
            // Required whenever the store cancels a customer's appointment —
            // AppointmentController::destroy() enforces "required" only when
            // the actor is store_owner/branch_manager/staff, not when a
            // customer cancels their own booking (no reason needed there).
            $table->text('cancellation_reason')->nullable()->after('outcome');

            // Set by the store at cancel time — default false (customer may
            // still rebook at this store). true means the store is refusing
            // further bookings from this customer; enforced in
            // PublicBookingController::submit() by checking the customer's
            // most recent cancelled appointment at that store.
            $table->boolean('rebooking_blocked')->default(false)->after('cancellation_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'rebooking_blocked']);
        });
    }
};
