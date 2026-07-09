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
        Schema::table('payments', function (Blueprint $table) {
            // Optional — the owner is self-certifying this payment (unlike the
            // Receipts queue, where a customer's proof needs verification), but
            // attaching a screenshot still gives them their own record to point
            // to later if a balance is ever disputed.
            $table->string('receipt_path')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });
    }
};
