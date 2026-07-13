<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Catalog pivots to made-to-order only — no ready-to-wear inventory
 * to sell and no rental stock, so sale/rental pricing windows are removed.
 * price becomes the item's real tailoring price (base or bulk, per the
 * existing services-style pricing pattern), and estimated_days mirrors
 * Service.estimated_days so the catalog can promise a turnaround time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'sale_starts_at', 'sale_ends_at', 'rental_price', 'rental_deposit']);
            $table->integer('estimated_days')->nullable()->default(7)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('estimated_days');
            $table->decimal('sale_price', 10, 2)->nullable()->after('price');
            $table->decimal('rental_price', 10, 2)->nullable()->after('sale_price');
            $table->decimal('rental_deposit', 10, 2)->nullable()->after('rental_price');
            $table->timestamp('sale_starts_at')->nullable()->after('rental_deposit');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
        });
    }
};
