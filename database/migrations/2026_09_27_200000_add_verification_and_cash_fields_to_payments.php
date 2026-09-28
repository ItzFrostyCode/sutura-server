<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Null = still pending verification (gcash/paymaya, balance not yet
            // touched); set = verified and applied to the job's balance. Cash is
            // auto-verified at creation (verified_by = whoever recorded it) since
            // the money is physically in hand, no separate confirmation needed.
            $table->timestamp('verified_at')->nullable()->after('receipt_path');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            // Cash-only. Both null for gcash/paymaya.
            $table->decimal('cash_tendered', 10, 2)->nullable()->after('verified_by');
            $table->decimal('change_amount', 10, 2)->nullable()->after('cash_tendered');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verified_at', 'cash_tendered', 'change_amount']);
        });
    }
};
