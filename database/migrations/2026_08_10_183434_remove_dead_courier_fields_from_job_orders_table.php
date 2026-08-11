<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store pickup only — the approved thesis explicitly excludes logistics/
 * courier/delivery management (StoreJobOrderRequest's fulfillment_type only
 * ever validates 'pickup'). These three columns were added early on and
 * never actually used: no controller/request sets them, no frontend page
 * reads them (only appeared in two TS type declarations, never rendered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['courier_name', 'courier_tracking_number', 'shipping_address']);
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('courier_name')->nullable()->after('notes');
            $table->string('courier_tracking_number')->nullable()->after('courier_name');
            $table->string('shipping_address')->nullable()->after('courier_tracking_number');
        });
    }
};
