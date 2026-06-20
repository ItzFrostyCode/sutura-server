<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Add appointment_type — core classification field
            $table->string('appointment_type')->default('consultation')->after('service_id');

            // Rename duration → duration_minutes for clarity
            $table->renameColumn('duration', 'duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('appointment_type');
            $table->renameColumn('duration_minutes', 'duration');
        });
    }
};
