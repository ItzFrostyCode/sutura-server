<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a design is priced against its service: the service's price (null / "same", the default), the
 * service's price plus an extra charge ("extra"), or a quotation ("quote"). Additive only: the old
 * catalog_items.price stays as it was — it is still the price of a design that has no service, and
 * past orders keep the totals they were saved with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('pricing_mode', 16)->nullable()->after('price');
            $table->decimal('extra_charge', 10, 2)->nullable()->after('pricing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'extra_charge']);
        });
    }
};
