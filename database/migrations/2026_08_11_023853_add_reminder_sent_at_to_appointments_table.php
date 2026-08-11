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
        Schema::table('appointments', function (Blueprint $table) {
            // Nullable marker, not a boolean — lets the reminder command run
            // hourly and use "still null" as its own idempotency check
            // instead of a separate dedup table. Named after what it
            // records (when the reminder actually went out), not the
            // upcoming feature, so it reads correctly forever.
            $table->timestamp('reminder_sent_at')->nullable()->after('outcome');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
