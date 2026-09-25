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
        Schema::table('store_special_hours', function (Blueprint $table) {
            // Null = applies store-wide (every branch); set = applies to just
            // that one branch (e.g. "Lanang closed for renovation" without
            // also closing Main/Matina). Multi-branch is meant to be a
            // first-class dimension everywhere per this project's own
            // conventions — Special Hours/Closures was the one place left
            // where a store with 3 branches couldn't close just one of them.
            $table->foreignId('store_branch_id')->nullable()->after('store_id')
                ->constrained('store_branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_special_hours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_branch_id');
        });
    }
};
