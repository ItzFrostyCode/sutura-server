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
        Schema::table('appointments', function (Blueprint $table) {
            $table->integer('duration')->default(60)->after('scheduled_at');
            $table->foreignId('assigned_staff_id')->nullable()->after('duration')->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['assigned_staff_id']);
            $table->dropColumn(['duration', 'assigned_staff_id']);
            // We cannot easily revert string back to enum in sqlite/all DBs, but standard is to revert status back if needed.
            // SQLite doesn't support changing type back to enum easily, so leaving it as string is safer.
        });
    }
};
