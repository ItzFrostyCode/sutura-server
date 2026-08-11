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
        Schema::table('shop_special_hours', function (Blueprint $table) {
            // Null = applies shop-wide (every branch); set = applies to just
            // that one branch (e.g. "Lanang closed for renovation" without
            // also closing Main/Matina). Multi-branch is meant to be a
            // first-class dimension everywhere per this project's own
            // conventions — Special Hours/Closures was the one place left
            // where a shop with 3 branches couldn't close just one of them.
            $table->foreignId('shop_branch_id')->nullable()->after('shop_id')
                ->constrained('shop_branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_special_hours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_branch_id');
        });
    }
};
