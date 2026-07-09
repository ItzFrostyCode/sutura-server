<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_posts', function (Blueprint $table) {
            $table->json('image_urls')->nullable()->after('service_id');
        });

        // Backfill any existing single-image posts into the new array shape
        // before the old column disappears.
        DB::table('shop_posts')->whereNotNull('image_url')->orderBy('id')->each(function ($post) {
            DB::table('shop_posts')->where('id', $post->id)->update([
                'image_urls' => json_encode([$post->image_url]),
            ]);
        });

        Schema::table('shop_posts', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_posts', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('service_id');
        });

        DB::table('shop_posts')->whereNotNull('image_urls')->orderBy('id')->each(function ($post) {
            $urls = json_decode($post->image_urls, true) ?? [];
            DB::table('shop_posts')->where('id', $post->id)->update([
                'image_url' => $urls[0] ?? null,
            ]);
        });

        Schema::table('shop_posts', function (Blueprint $table) {
            $table->dropColumn('image_urls');
        });
    }
};
