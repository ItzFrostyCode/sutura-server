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
            $table->date('rental_start_date')->nullable()->after('fulfillment_type');
            $table->date('rental_end_date')->nullable()->after('rental_start_date');
            $table->decimal('security_deposit_amount', 10, 2)->nullable()->after('rental_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn(['rental_start_date', 'rental_end_date', 'security_deposit_amount']);
        });
    }
};
