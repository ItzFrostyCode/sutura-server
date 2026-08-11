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
        Schema::table('catalog_item_reviews', function (Blueprint $table) {
            // ShopReview already lets the owner reply to shop-level reviews;
            // catalog-item-level reviews (a specific Barong/gown design) had
            // no owner-facing management at all — no reply, no moderation.
            $table->text('reply')->nullable()->after('comment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_item_reviews', function (Blueprint $table) {
            $table->dropColumn('reply');
        });
    }
};
