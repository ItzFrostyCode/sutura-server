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
        // A customer's own standing body-measurement profile — deliberately
        // NOT shop-scoped (unlike `measurements`, which is a shop's own
        // fitting-history record for a customer). One row per customer,
        // filled in once and reused as the "Size Guide" reference across
        // every shop's catalog. All values are cm/kg — the global fashion
        // industry standard, not the free-form unlabeled units the existing
        // shop-scoped `measurements.metrics` JSON has always used.
        Schema::create('size_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            // Optional secondary measurements (shoulder, bust, under_bust,
            // waist, hip, thigh, ball_girth, foot_length — all cm) — a JSON
            // blob rather than 8 more columns since this set is exactly the
            // kind of thing that grows over time without needing a schema
            // change, same reasoning as `measurements.metrics`.
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('size_profiles');
    }
};
