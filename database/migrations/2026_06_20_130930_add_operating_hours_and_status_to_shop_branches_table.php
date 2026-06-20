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
        Schema::table('shop_branches', function (Blueprint $table) {
            $table->string('operating_hours')->nullable()->after('contact_number');
            $table->string('status')->default('active')->after('is_main');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_branches', function (Blueprint $table) {
            $table->dropColumn(['operating_hours', 'status']);
        });
    }
};
