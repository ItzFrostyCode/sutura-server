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
        Schema::table('measurements', function (Blueprint $table) {
            // Editing a profile no longer overwrites it in place — it stamps
            // the current row's superseded_at and inserts a new row with
            // version + 1. The "current" row for a profile is the one where
            // superseded_at is null.
            $table->unsignedInteger('version')->default(1)->after('profile_name');
            $table->timestamp('superseded_at')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn(['version', 'superseded_at']);
        });
    }
};
