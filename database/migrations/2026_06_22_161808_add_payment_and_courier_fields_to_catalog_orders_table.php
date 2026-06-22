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
            $table->string('payment_method')->nullable()->after('payment_status');
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->string('payment_receipt_path')->nullable()->after('payment_reference');
            $table->string('courier_name')->nullable()->after('payment_receipt_path');
            $table->string('courier_tracking_number')->nullable()->after('courier_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_reference',
                'payment_receipt_path',
                'courier_name',
                'courier_tracking_number',
            ]);
        });
    }
};
