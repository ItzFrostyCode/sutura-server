<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // Separate sale vs rent economics on the listing itself (was a single `price`).
            $table->decimal('sale_price', 10, 2)->nullable()->after('price');
            $table->decimal('rental_price', 10, 2)->nullable()->after('sale_price');
            $table->decimal('rental_deposit', 10, 2)->nullable()->after('rental_price');
            // Attributes for pre-tagging / filtering.
            $table->string('color')->nullable()->after('material');
            $table->string('fabric_image_url')->nullable()->after('color');
            $table->json('sizes')->nullable()->after('fabric_image_url');
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            // Explicit sale|rent so a receipt can clearly state the transaction mode
            // (previously rent was only inferred from the presence of rental dates).
            $table->string('order_mode')->default('sale');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'rental_price', 'rental_deposit', 'color', 'fabric_image_url', 'sizes']);
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn('order_mode');
        });
    }
};
