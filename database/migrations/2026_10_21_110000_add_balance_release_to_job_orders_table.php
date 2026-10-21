<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Release with balance due": the owner or a branch manager lets the garments go before the balance is paid
// (for example a group leader who collects from the group). The order stays open until the balance is paid.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('released_with_balance_at')->nullable();
            $table->foreignId('released_with_balance_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('balance_due_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_with_balance_by');
            $table->dropColumn(['released_with_balance_at', 'balance_due_by']);
        });
    }
};
