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
            $table->dropColumn('fit_guide');
            $table->string('size_chart_image_url')->nullable();
            $table->json('size_chart_columns')->nullable();
            $table->json('size_chart_rows')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['size_chart_image_url', 'size_chart_columns', 'size_chart_rows']);
            $table->json('fit_guide')->nullable();
        });
    }
};
