<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * On-time/Late is DERIVED from checked_in_at vs scheduled_at — never its
     * own stored status, and never added to Appointment::STATUSES. Written
     * by Staff (the actual check-in action) once that Staff-side endpoint is
     * built — this column is the read-side dependency the Customer module
     * needs prepared now so /account/appointments can display arrival state
     * once it exists. See docs/PRACTICAL-SITUATIONS.md #1,
     * docs/STAFF-WORKFLOW.md §1-2 (cross-role dependency).
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('checked_in_at');
        });
    }
};
