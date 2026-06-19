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
            $table->json('social_links')->nullable()->after('email');
            $table->json('gallery_images')->nullable()->after('social_links');
        });

        Schema::create('shop_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('rating')->comment('1 to 5 stars');
            $table->text('comment')->nullable();
            $table->timestamps();

            // A user can only review a shop once
            $table->unique(['shop_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_reviews');

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['social_links', 'gallery_images']);
        });
    }
};
