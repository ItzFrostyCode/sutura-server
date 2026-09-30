<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A booking made from a catalog design used to record the design only
     * as free text in `notes` ("[Design Reference: …]"), so the owner,
     * branch, and staff appointment views had no real title or category to
     * show. These columns store the design as an actual link, plus the size
     * and color the customer picked on the design's page.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')->nullable()->after('service_id')
                ->constrained('catalog_items')->nullOnDelete();
            $table->string('selected_size', 50)->nullable()->after('catalog_item_id');
            $table->string('selected_color', 100)->nullable()->after('selected_size');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_item_id');
            $table->dropColumn(['selected_size', 'selected_color']);
        });
    }
};
