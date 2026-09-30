<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // What the SHOP shares with the customer once an appointment is
            // accepted (a link, photos) — kept apart from reference_link /
            // reference_images, which are the customer's own from booking.
            $table->string('shared_link', 500)->nullable()->after('reference_link');
            $table->json('shared_images')->nullable()->after('shared_link');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['shared_link', 'shared_images']);
        });
    }
};
