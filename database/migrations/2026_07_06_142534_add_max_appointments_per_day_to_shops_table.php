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
        Schema::table('shops', function (Blueprint $table) {
            // Null = unlimited. Once bookings for a given day hit this cap
            // (peak season protection), the day is blocked from new bookings
            // rather than letting quality slip from overcommitting production.
            $table->unsignedInteger('max_appointments_per_day')->nullable()->after('booking_questions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('max_appointments_per_day');
        });
    }
};
