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
        Schema::table('shop_subscriptions', function (Blueprint $table) {
            // app:expire-subscriptions only ever notifies AFTER the shop is
            // already hidden — nothing warned the owner beforehand, even
            // though "maintain active subscription validity for continued
            // platform visibility" is one of the thesis's own stated
            // objectives. Nullable marker (not a boolean), same idempotency
            // pattern as appointments.reminder_sent_at.
            $table->timestamp('expiry_reminder_sent_at')->nullable()->after('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_subscriptions', function (Blueprint $table) {
            $table->dropColumn('expiry_reminder_sent_at');
        });
    }
};
