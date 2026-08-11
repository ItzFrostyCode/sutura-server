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
        Schema::table('catalog_orders', function (Blueprint $table) {
            // JobOrder and Appointment already carry shop_branch_id for
            // exactly this reason — a walk-in/RTW sale was the one order
            // type in the system with no branch attribution at all, so it
            // was invisible to branch performance comparison and to a
            // branch_manager's own access scoping.
            $table->foreignId('shop_branch_id')->nullable()->after('shop_id')
                ->constrained('shop_branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_branch_id');
        });
    }
};
