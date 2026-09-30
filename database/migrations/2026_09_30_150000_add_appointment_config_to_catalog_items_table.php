<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // How customers may book an appointment from this design's page:
            // {accepts_bookings: bool, types: {consultation: {enabled, minutes},
            // measurement: {enabled, minutes}}}. Null = the store's defaults.
            $table->json('appointment_config')->nullable()->after('measurement_guide');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('appointment_config');
        });
    }
};
