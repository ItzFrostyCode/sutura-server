<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Customer-submitted "Report This Product" (catalog item detail
        // page's ⋯ menu) lands here as a real ticket so it reaches System
        // Admin the same way a store owner's ticket does — none of the
        // existing types (problem/update_request/general/billing) describe
        // a content report, so it gets its own.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE support_tickets MODIFY COLUMN type ENUM('problem','update_request','general','billing','product_report') NOT NULL DEFAULT 'general'");
        } else {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->string('type')->default('general')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE support_tickets MODIFY COLUMN type ENUM('problem','update_request','general','billing') NOT NULL DEFAULT 'general'");
        } else {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->string('type')->default('general')->change();
            });
        }
    }
};
