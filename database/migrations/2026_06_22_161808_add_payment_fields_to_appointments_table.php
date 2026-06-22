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
            $table->string('payment_method')->nullable()->after('status');
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->string('payment_receipt_path')->nullable()->after('payment_reference');
            $table->string('payment_status')->default('pending')->after('payment_receipt_path'); // pending, paid
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_reference',
                'payment_receipt_path',
                'payment_status',
            ]);
        });
    }
};
