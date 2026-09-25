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
        Schema::table('stores', function (Blueprint $table) {
            $table->json('social_links')->nullable()->after('email');
            $table->json('gallery_images')->nullable()->after('social_links');
        });

        Schema::create('store_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('rating')->comment('1 to 5 stars');
            $table->text('comment')->nullable();
            $table->timestamps();

            // A user can only review a store once
            $table->unique(['store_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_reviews');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['social_links', 'gallery_images']);
        });
    }
};
