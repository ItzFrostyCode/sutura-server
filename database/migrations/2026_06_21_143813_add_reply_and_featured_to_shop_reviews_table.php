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
        Schema::table('shop_reviews', function (Blueprint $table) {
            $table->text('reply')->nullable()->after('comment');
            $table->boolean('is_featured')->default(false)->after('reply');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_reviews', function (Blueprint $table) {
            $table->dropColumn(['reply', 'is_featured']);
        });
    }
};
