<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('outcome')->nullable()->after('status');
            $table->string('priority')->default('normal')->after('outcome');
            $table->string('garment_category')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'priority', 'garment_category']);
        });
    }
};
