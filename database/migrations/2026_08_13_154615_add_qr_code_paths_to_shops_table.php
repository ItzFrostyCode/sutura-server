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
        // Typing a GCash/bank number correctly is real friction — scan-to-pay
        // via a QR code is how most GCash payments actually happen. TEXT, not
        // string(), from the start: real cloud storage URLs (domain + bucket +
        // encoded filename) routinely exceed varchar(255), a bug already hit
        // and fixed once on logo_path/catalog images (see CLAUDE.md).
        Schema::table('shops', function (Blueprint $table) {
            $table->text('gcash_qr_path')->nullable()->after('bank_account_name');
            $table->text('bank_qr_path')->nullable()->after('gcash_qr_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['gcash_qr_path', 'bank_qr_path']);
        });
    }
};
