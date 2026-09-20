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
        // The Top/Bottom split this column enabled turned out to add more
        // complexity (separate version histories per half, an extra filter
        // dropdown on both the owner and customer sides) than value — one
        // profile per customer per shop, kept simple.
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->string('category')->nullable()->after('profile_name');
        });
    }
};
