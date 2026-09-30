<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('catalog_items', 'appointment_config')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->dropColumn('appointment_config');
            });
        }
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->json('appointment_config')->nullable()->after('measurement_guide');
        });
    }
};
