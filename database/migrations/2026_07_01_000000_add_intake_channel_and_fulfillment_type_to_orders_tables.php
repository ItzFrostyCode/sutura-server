<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Modify job_orders
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('intake_channel')->default('walk_in')->after('order_number');
            $table->string('fulfillment_type')->default('pickup')->after('intake_channel');
        });

        // Copy old order_type data
        DB::table('job_orders')->update(['intake_channel' => DB::raw('order_type')]);
        
        // If shipping address is set, set fulfillment type to shipping
        DB::table('job_orders')
            ->whereNotNull('shipping_address')
            ->where('shipping_address', '!=', '')
            ->update(['fulfillment_type' => 'shipping']);

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('order_type');
        });

        // 2. Modify catalog_orders
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->string('intake_channel')->default('online')->after('customer_id');
            $table->string('fulfillment_type')->default('shipping')->after('intake_channel');
        });
        
        // If delivery_address is null or empty, set fulfillment to pickup
        DB::table('catalog_orders')
            ->whereNull('delivery_address')
            ->orWhere('delivery_address', '')
            ->update(['fulfillment_type' => 'pickup']);
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('order_type')->default('walk_in')->after('order_number');
        });

        DB::table('job_orders')->update(['order_type' => DB::raw('intake_channel')]);

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['intake_channel', 'fulfillment_type']);
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn(['intake_channel', 'fulfillment_type']);
        });
    }
};
