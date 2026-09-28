<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors catalog_item_saves exactly — a Service can now be
        // hearted/saved the same way a CatalogItem design already can.
        // Ratings already existed on both (CatalogItemReview/ServiceReview);
        // saves/hearts didn't have a Service-side equivalent until now.
        Schema::create('service_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_saves');
    }
};
