<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive to due_date, not a replacement — due_date stays the
     * calendar-day due date every order type already has. This is the
     * time-of-day refinement a same-day repair ETA needs
     * ("Balik after 2 hours" -> Estimated Ready = 2:00 PM), which a pure
     * DATE column structurally cannot represent. See docs/REPAIR-WORKFLOW.md §6.
     */
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('estimated_ready_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('estimated_ready_at');
        });
    }
};
