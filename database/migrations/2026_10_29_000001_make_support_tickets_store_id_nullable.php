<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * General ("My Support Tickets") support tickets aren't about any shop,
     * so store_id stops being required. Every shop-side row — owner/staff
     * dashboard tickets and product reports — keeps carrying one; only the
     * customer-filed general rows (SupportTicketController::storeMine) can
     * be null.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE support_tickets MODIFY COLUMN store_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Putting NOT NULL back needs a default for existing null rows;
        // the nullable column is safe to keep.
    }
};
