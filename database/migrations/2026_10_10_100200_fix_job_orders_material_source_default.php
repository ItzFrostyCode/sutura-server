<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `job_orders.material_source` defaulted to 'shop_supplied', but the only valid values are
 * 'store_supplied' and 'customer_supplied' (JobOrder::MATERIAL_SOURCES) -- so every order created
 * without an explicit value held an invalid one. Fix the default and the existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('job_orders')->where('material_source', 'shop_supplied')->update(['material_source' => 'store_supplied']);

        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('material_source')->default('store_supplied')->change();
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('material_source')->default('shop_supplied')->change();
        });
    }
};
