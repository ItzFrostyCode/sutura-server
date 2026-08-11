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
        // The system already records a GCash/bank reference number against
        // every payment (JobOrderController::pay, CatalogOrderController::store)
        // but had nowhere for the owner to actually publish *which* GCash
        // number or bank account customers should send that payment to — a
        // real gap for a GCash-dominant market. Informational only (the
        // system still never moves money itself, no gateway integration).
        Schema::table('shops', function (Blueprint $table) {
            $table->string('gcash_number', 20)->nullable()->after('fitting_limit');
            $table->string('gcash_account_name', 191)->nullable()->after('gcash_number');
            $table->string('bank_name', 100)->nullable()->after('gcash_account_name');
            $table->string('bank_account_number', 50)->nullable()->after('bank_name');
            $table->string('bank_account_name', 191)->nullable()->after('bank_account_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['gcash_number', 'gcash_account_name', 'bank_name', 'bank_account_number', 'bank_account_name']);
        });
    }
};
