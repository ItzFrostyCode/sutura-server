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
        Schema::table('staff_profiles', function (Blueprint $table) {
            // Ranked secondary roles — `role` stays the primary/main role (rank
            // 1), this holds the rest in order (index 0 = rank 2, index 1 =
            // rank 3, etc.) so one versatile staff member doesn't need a
            // separate duplicate account per role they can perform.
            $table->json('additional_roles')->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropColumn('additional_roles');
        });
    }
};
