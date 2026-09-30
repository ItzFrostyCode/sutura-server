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
            // Same shape/convention as care_instructions: a nullable TEXT
            // column holding either plain text or a JSON-encoded
            // {text, image_url} object, parsed client-side. Sits alongside
            // the structured size_chart_* fields in the Size Guide section
            // as a free-text sub-block (e.g. "measure with light clothing,
            // arms relaxed at your sides").
            $table->text('measurement_guide')->nullable()->after('size_chart_rows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('measurement_guide');
        });
    }
};
