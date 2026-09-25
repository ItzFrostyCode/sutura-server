<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement("ALTER TABLE support_tickets MODIFY COLUMN type ENUM('problem','update_request','general','billing','product_report') NOT NULL DEFAULT 'general'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE support_tickets MODIFY COLUMN type ENUM('problem','update_request','general','billing') NOT NULL DEFAULT 'general'");
    }
};
