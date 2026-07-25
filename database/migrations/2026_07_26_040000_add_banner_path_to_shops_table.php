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
            // TEXT, not string() — matches logo_path (widened in
            // 2026_07_23_225530) for the same reason: real cloud storage
            // URLs routinely exceed 255 chars.
            $table->text('banner_path')->nullable()->after('logo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('banner_path');
        });
    }
};
