<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What a measurement was taken for: a service and, optionally, one of that service's designs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $t) {
            $t->foreignId('service_id')->nullable()->after('appointment_id')->constrained()->nullOnDelete();
            $t->foreignId('catalog_item_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
        });

        // Measurements taken at an appointment are for whatever that appointment booked.
        DB::statement('UPDATE measurements SET service_id = (SELECT service_id FROM appointments WHERE appointments.id = measurements.appointment_id), catalog_item_id = (SELECT catalog_item_id FROM appointments WHERE appointments.id = measurements.appointment_id) WHERE appointment_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('catalog_item_id');
            $t->dropConstrainedForeignId('service_id');
        });
    }
};
