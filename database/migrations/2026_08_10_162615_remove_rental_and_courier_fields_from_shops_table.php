<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental lifecycle management and courier/delivery/logistics are both
 * explicitly excluded from SUTURA's approved thesis scope (store pickup
 * only — see StoreJobOrderRequest's fulfillment_type validation). These
 * columns were added early on for a rental feature that never shipped, are
 * never read by any controller/request, and the settings UI that edited
 * them (SettingsRentalPolicies.tsx) was itself never even rendered anywhere
 * in the app. fitting_fee/fitting_limit are kept — they're a real tailoring
 * concept (fitting-session policy), not rental.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'security_deposit',
                'rental_duration_days',
                'overdue_penalty_per_day',
                'reschedule_fee_percent',
                'change_reserved_hours',
                'change_reserved_fee_percent',
                'supported_couriers',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('security_deposit', 10, 2)->default(0.00)->after('booking_questions');
            $table->integer('rental_duration_days')->default(3)->after('security_deposit');
            $table->decimal('overdue_penalty_per_day', 10, 2)->default(0.00)->after('rental_duration_days');
            $table->integer('reschedule_fee_percent')->default(0)->after('fitting_limit');
            $table->integer('change_reserved_hours')->default(24)->after('reschedule_fee_percent');
            $table->integer('change_reserved_fee_percent')->default(0)->after('change_reserved_hours');
            $table->json('supported_couriers')->nullable()->after('change_reserved_fee_percent');
        });
    }
};
