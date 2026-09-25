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
            $table->string('landmark')->nullable()->after('address');
        });

        Schema::table('store_branches', function (Blueprint $table) {
            $table->string('landmark')->nullable()->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('landmark');
        });

        Schema::table('store_branches', function (Blueprint $table) {
            $table->dropColumn('landmark');
        });
    }
};
