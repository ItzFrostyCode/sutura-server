<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same reasoning as the job_orders courier cleanup migration — pickup only,
 * these columns were never set by any controller/request and never read on
 * the frontend at all (not even in a type declaration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_address', 'courier_name', 'courier_tracking_number']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->text('delivery_address')->nullable();
            $table->string('courier_name')->nullable()->after('payment_receipt_path');
            $table->string('courier_tracking_number')->nullable()->after('courier_name');
        });
    }
};
