<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // What an "other" appointment is for (e.g. "Fabric selection"). Only used when appointment_type = other.
            $table->string('purpose_label', 60)->nullable()->after('appointment_type');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('purpose_label');
        });
    }
};
