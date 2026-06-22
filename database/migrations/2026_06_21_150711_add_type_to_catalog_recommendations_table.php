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
        Schema::table('catalog_recommendations', function (Blueprint $table) {
            $table->string('recommendation_type')->default('similar')->after('recommended_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_recommendations', function (Blueprint $table) {
            $table->dropColumn('recommendation_type');
        });
    }
};
