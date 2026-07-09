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
        Schema::table('appointments', function (Blueprint $table) {
            // Reference photos (design mockups, existing uniform samples) and
            // an optional link (Google Drive/YouTube/Pinterest) a customer
            // attaches to a bulk/custom order inquiry — no native video
            // upload, a link is far cheaper to store than hosting video files.
            $table->json('reference_images')->nullable()->after('notes');
            $table->string('reference_link', 500)->nullable()->after('reference_images');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['reference_images', 'reference_link']);
        });
    }
};
