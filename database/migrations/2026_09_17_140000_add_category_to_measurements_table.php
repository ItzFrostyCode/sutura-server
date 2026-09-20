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
        Schema::table('measurements', function (Blueprint $table) {
            // Nullable, not required — a one-piece garment (a gown, a
            // Barong) doesn't need splitting, but a suit (jacket +
            // trousers) does: a customer's chest/shoulder measurements can
            // change independently of their waist/inseam, so each half
            // needs its own version history, not one shared version number
            // covering both. Null means "general/uncategorized", same as
            // every profile before this column existed.
            $table->string('category')->nullable()->after('profile_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
