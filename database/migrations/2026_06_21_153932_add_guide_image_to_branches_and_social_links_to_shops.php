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
        if (!Schema::hasColumn('shop_branches', 'guide_image_url')) {
            Schema::table('shop_branches', function (Blueprint $table) {
                $table->string('guide_image_url')->nullable()->after('is_main');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('shop_branches', 'guide_image_url')) {
            Schema::table('shop_branches', function (Blueprint $table) {
                $table->dropColumn('guide_image_url');
            });
        }
    }
};
