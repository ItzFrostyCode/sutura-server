<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->boolean('is_rush')->default(false)->after('status');
            $table->decimal('rush_fee', 10, 2)->default(0.00)->after('is_rush');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['is_rush', 'rush_fee']);
        });
    }
};
