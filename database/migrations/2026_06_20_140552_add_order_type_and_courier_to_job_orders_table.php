<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            // Walk-in or Online order type
            $table->string('order_type')->default('walk_in')->after('order_number');

            // Shipping info for online orders
            $table->string('courier_name')->nullable()->after('notes');
            $table->string('courier_tracking_number')->nullable()->after('courier_name');
            $table->string('shipping_address')->nullable()->after('courier_tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'courier_name', 'courier_tracking_number', 'shipping_address']);
        });
    }
};
