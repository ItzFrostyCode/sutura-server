<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rush per service: some work can be rushed cheaply (a polo), some costs more to rush or cannot be rushed at all (a
 * beaded gown). rush_available: null = follow the shop's rush setting, false = no rush for this service, true = this
 * service's own fee (rush_fee_mode with rush_fee_percent or rush_fee_per_piece). See App\Support\RushFee::rules().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('rush_available')->nullable()->after('own_fabric_deduction');
            $table->string('rush_fee_mode', 16)->nullable()->after('rush_available');
            $table->decimal('rush_fee_percent', 5, 2)->nullable()->after('rush_fee_mode');
            $table->decimal('rush_fee_per_piece', 10, 2)->nullable()->after('rush_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['rush_available', 'rush_fee_mode', 'rush_fee_percent', 'rush_fee_per_piece']);
        });
    }
};
