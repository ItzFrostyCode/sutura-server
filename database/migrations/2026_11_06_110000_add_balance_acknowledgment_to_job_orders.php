<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W-F01 / W-F03 (DSD-02 WF-7, WD-16): a garment may leave with a balance only under an authorized release, and the
 * record keeps the customer's acknowledgment of the remaining balance and its due date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('balance_acknowledged_at')->nullable()->after('released_with_balance_by');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', fn (Blueprint $table) => $table->dropColumn('balance_acknowledged_at'));
    }
};
