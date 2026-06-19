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
            $table->unsignedInteger('views_count')->default(0)->after('care_instructions');
        });

        Schema::create('catalog_item_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('rating')->comment('1 to 5 stars');
            $table->text('comment')->nullable();
            $table->timestamps();

            // A user can only review an item once
            $table->unique(['catalog_item_id', 'user_id']);
        });

        Schema::create('catalog_item_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // A user can only save an item once
            $table->unique(['catalog_item_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_item_saves');
        Schema::dropIfExists('catalog_item_reviews');

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('views_count');
        });
    }
};
