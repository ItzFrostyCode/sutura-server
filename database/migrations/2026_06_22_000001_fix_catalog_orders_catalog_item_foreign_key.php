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
            $table->dropForeign(['catalog_item_id']);
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->foreign('catalog_item_id')->references('id')->on('catalog_items')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropForeign(['catalog_item_id']);
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->foreign('catalog_item_id')->references('id')->on('catalog')->cascadeOnDelete();
        });
    }
};
