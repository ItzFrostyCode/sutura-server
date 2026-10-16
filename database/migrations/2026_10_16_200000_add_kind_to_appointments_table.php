<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // service = for one of the shop's services, designs or combos; shop = a general visit
            // (consultation, measurement, other); production = made by the system for a production order
            // (the fitting / pickup it creates). Only the first two can be booked by a person.
            $table->string('kind', 16)->default('shop')->after('appointment_type');
            $table->index(['store_id', 'kind']);
        });

        // Backfill. Fittings and pickups were always tied to an order, so they are production.
        DB::table('appointments')->whereIn('appointment_type', ['fitting', 'pickup'])->update(['kind' => 'production']);

        DB::table('appointments')
            ->where('kind', 'shop')
            ->where(function ($q) {
                $q->whereNotNull('service_id')->orWhereNotNull('service_package_id')->orWhereNotNull('catalog_item_id');
            })
            ->update(['kind' => 'service']);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
