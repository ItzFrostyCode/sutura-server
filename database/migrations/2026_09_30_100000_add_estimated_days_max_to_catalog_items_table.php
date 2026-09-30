<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // estimated_days stays the shortest turnaround ("from"); this is
            // the longest ("to"), so a design can promise 5–7 days instead
            // of a single number. Null = a fixed single-number estimate.
            $table->integer('estimated_days_max')->nullable()->after('estimated_days');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('estimated_days_max');
        });
    }
};
