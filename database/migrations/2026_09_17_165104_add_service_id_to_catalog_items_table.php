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
        Schema::table('catalog_items', function (Blueprint $table) {
            // Optional — links a Showroom item to the Service its
            // production actually falls under (e.g. a jersey design →
            // "Custom Sublimation Team Jerseys"). Nullable and store-owner
            // set: the customer-facing "Bulk Order" flow only appears once
            // this is set to a bulk_sublimation-typed service, rather than
            // guessing or forcing every item to have one.
            $table->foreignId('service_id')->nullable()->after('store_id')->constrained('services')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
