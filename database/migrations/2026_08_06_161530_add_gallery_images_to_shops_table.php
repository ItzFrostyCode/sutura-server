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
        Schema::table('shops', function (Blueprint $table) {
            // The Account Settings/Storefront "About" tab editor has had a
            // full gallery upload UI (add/remove photos, save button) for
            // some time, but this column never existed — every save
            // silently dropped gallery_images (not in UpdateShopRequest's
            // validated fields, and no column to persist it to even if it
            // were), so uploaded photos vanished on the next page load.
            $table->json('gallery_images')->nullable()->after('banner_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('gallery_images');
        });
    }
};
