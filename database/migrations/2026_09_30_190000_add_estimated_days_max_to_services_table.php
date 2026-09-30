<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Upper end of the turnaround range ("5 to 7 days"). Null = exactly estimated_days.
            // estimated_days itself null = "depends on the order".
            $table->unsignedInteger('estimated_days_max')->nullable()->after('estimated_days');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('estimated_days_max');
        });
    }
};
