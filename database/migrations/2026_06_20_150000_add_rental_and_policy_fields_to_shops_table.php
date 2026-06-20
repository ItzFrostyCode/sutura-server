<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('security_deposit', 10, 2)->default(0.00)->after('booking_questions');
            $table->integer('rental_duration_days')->default(3)->after('security_deposit');
            $table->decimal('overdue_penalty_per_day', 10, 2)->default(0.00)->after('rental_duration_days');
            $table->decimal('fitting_fee', 10, 2)->default(0.00)->after('overdue_penalty_per_day');
            $table->integer('fitting_limit')->default(3)->after('fitting_fee');
            $table->integer('reschedule_fee_percent')->default(0)->after('fitting_limit');
            $table->integer('change_reserved_hours')->default(24)->after('reschedule_fee_percent');
            $table->integer('change_reserved_fee_percent')->default(0)->after('change_reserved_hours');
            $table->json('supported_couriers')->nullable()->after('change_reserved_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'security_deposit',
                'rental_duration_days',
                'overdue_penalty_per_day',
                'fitting_fee',
                'fitting_limit',
                'reschedule_fee_percent',
                'change_reserved_hours',
                'change_reserved_fee_percent',
                'supported_couriers'
            ]);
        });
    }
};
